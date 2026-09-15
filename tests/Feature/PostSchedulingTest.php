<?php

use App\Enums\NotificationTopicEnum;
use App\Enums\NotificationTypeEnum;
use App\Enums\StatusDefault;
use App\Enums\StatusPost;
use App\Enums\StatusUser;
use App\Enums\StatusYes;
use App\Enums\UserTypeEnum;
use App\Mail\NewPostEmail;
use App\Models\NotificationType;
use App\Models\Post;
use App\Models\User;
use App\Notifications\GeneralNotification;
use App\Services\BlogService;
use App\Services\NotificationSubscriberService;
use App\Services\SiteConfigurationService;
use App\Services\UserService;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    Mail::fake();

    app(SiteConfigurationService::class)->update(initials: true);

    $this->author = userOfType(UserTypeEnum::ADMIN, ['email_verified_at' => now()]);

    seededNotificationTypes();
});

/**
 * A member subscribed to announcements, which is what the fan-out reads.
 *
 * Preferences are backfilled on the first dashboard visit, so the helper does
 * what that visit does rather than inserting rows by hand.
 */
function subscribedMember(array $attributes = []): User
{
    $member = userOfType(UserTypeEnum::USER, ['email_verified_at' => now(), ...$attributes]);

    app(UserService::class, ['user' => $member])->runNotificationPreferencesUpdate();

    return $member;
}

/**
 * Announcing is opt-in, so every post here asks for it — a post that does not is
 * the subject of its own test rather than the default the rest are written against.
 */
function scheduledPost(string $when = '+1 hour', array $attributes = []): Post
{
    return Post::query()->create([
        'user_id' => test()->author->id,
        'title' => 'A scheduled post',
        'slug' => 'a-scheduled-post',
        'excerpt' => 'Something worth reading later.',
        'content' => '<p>The body.</p>',
        'status' => StatusPost::SCHEDULED,
        'published_at' => now()->modify($when),
        'send_email' => StatusYes::YES,
        ...$attributes,
    ]);
}

// ||||||||||||||||||||||||||||||||||||||||||||||||
// WHO COUNTS AS A SUBSCRIBER

test('an active member with the preference on is a subscriber', function () {
    $member = subscribedMember();

    expect(app(NotificationSubscriberService::class)->subscriberCount(NotificationTypeEnum::ANNOUNCEMENTS))
        ->toBe(1)
        ->and(app(NotificationSubscriberService::class)
            ->subscriberQuery(NotificationTypeEnum::ANNOUNCEMENTS)
            ->pluck('id')->all())
        ->toBe([$member->id]);
});

test('a member who switched the preference off is not', function () {
    $member = subscribedMember();

    $member->notificationPreferences()
        ->whereHas('notificationType', fn ($q) => $q->where('notification_type', NotificationTypeEnum::ANNOUNCEMENTS))
        ->update(['status' => StatusDefault::INACTIVE]);

    expect(app(NotificationSubscriberService::class)->subscriberCount(NotificationTypeEnum::ANNOUNCEMENTS))->toBe(0);
});

test('a suspended account is never a subscriber', function () {
    subscribedMember(['status' => StatusUser::SUSPENDED]);

    expect(app(NotificationSubscriberService::class)->subscriberCount(NotificationTypeEnum::ANNOUNCEMENTS))->toBe(0);
});

test('a retired notification type reaches nobody', function () {
    subscribedMember();

    NotificationType::query()
        ->where('notification_type', NotificationTypeEnum::ANNOUNCEMENTS)
        ->update(['status' => StatusDefault::INACTIVE]);

    expect(app(NotificationSubscriberService::class)->subscriberCount(NotificationTypeEnum::ANNOUNCEMENTS))->toBe(0);
});

// ||||||||||||||||||||||||||||||||||||||||||||||||
// THE FAN-OUT

test('a broadcast reaches every subscriber on both channels', function () {
    Notification::fake();

    $first = subscribedMember();
    $second = subscribedMember();

    // Built once, outside the closure: the closure runs per recipient, and a
    // factory with a side effect in it would be testing the wrong thing.
    $post = scheduledPost();

    $reached = app(NotificationSubscriberService::class)->broadcast(
        NotificationTypeEnum::ANNOUNCEMENTS,
        NotificationTopicEnum::NEW_POST,
        'Something happened',
        mailFor: fn (User $user) => new NewPostEmail($user, $post),
    );

    expect($reached)->toBe(2);

    Mail::assertQueued(NewPostEmail::class, 2);
    Notification::assertSentTo([$first, $second], GeneralNotification::class);
});

test('a broadcast with no mailable leaves only the bell entry', function () {
    subscribedMember();

    $reached = app(NotificationSubscriberService::class)->broadcast(
        NotificationTypeEnum::ANNOUNCEMENTS,
        NotificationTopicEnum::NEW_POST,
        'Bell only',
    );

    expect($reached)->toBe(1);

    Mail::assertNothingQueued();
});

// ||||||||||||||||||||||||||||||||||||||||||||||||
// PUBLISHING ON A SCHEDULE

test('a scheduled post is not live before its date', function () {
    $post = scheduledPost('+1 hour');

    expect(Post::query()->live()->count())->toBe(0)
        ->and($post->status)->toBe(StatusPost::SCHEDULED);
});

test('the command publishes a post whose date has passed', function () {
    subscribedMember();
    $post = scheduledPost('-1 minute');

    $this->artisan('blog:publish-scheduled')->assertSuccessful();

    $fresh = $post->fresh();

    expect($fresh->status)->toBe(StatusPost::PUBLISHED)
        ->and($fresh->announced_at)->not->toBeNull();

    Mail::assertQueued(NewPostEmail::class, 1);
});

test('the command leaves a post whose date has not arrived', function () {
    $post = scheduledPost('+1 hour');

    $this->artisan('blog:publish-scheduled')->assertSuccessful();

    expect($post->fresh()->status)->toBe(StatusPost::SCHEDULED);

    Mail::assertNothingQueued();
});

test('a dry run publishes nothing', function () {
    $post = scheduledPost('-1 minute');

    $this->artisan('blog:publish-scheduled', ['--dry-run' => true])->assertSuccessful();

    expect($post->fresh()->status)->toBe(StatusPost::SCHEDULED);

    Mail::assertNothingQueued();
});

test('no-announce publishes without telling anybody', function () {
    subscribedMember();
    $post = scheduledPost('-1 minute');

    $this->artisan('blog:publish-scheduled', ['--no-announce' => true])->assertSuccessful();

    expect($post->fresh()->status)->toBe(StatusPost::PUBLISHED);

    Mail::assertNothingQueued();
});

// ||||||||||||||||||||||||||||||||||||||||||||||||
// ANNOUNCING ONCE

test('a post is never announced twice', function () {
    subscribedMember();
    $post = scheduledPost('-1 minute');

    $this->artisan('blog:publish-scheduled')->assertSuccessful();

    // A second run, and a direct call, both find the stamp already written.
    $this->artisan('blog:publish-scheduled')->assertSuccessful();

    expect(app(BlogService::class)->announce($post->fresh()))->toBeNull();

    Mail::assertQueued(NewPostEmail::class, 1);
});

test('a post published without the email tick tells nobody', function () {
    subscribedMember();
    $post = scheduledPost('-1 minute', ['send_email' => StatusYes::NO]);

    $this->artisan('blog:publish-scheduled')->assertSuccessful();

    $fresh = $post->fresh();

    // Published, and deliberately quiet: the stamp is only written by a send.
    expect($fresh->status)->toBe(StatusPost::PUBLISHED)
        ->and($fresh->announced_at)->toBeNull();

    Mail::assertNothingQueued();
});

test('ticking the email box later still sends, once', function () {
    subscribedMember();
    $post = scheduledPost('-1 minute', ['send_email' => StatusYes::NO]);

    $this->artisan('blog:publish-scheduled')->assertSuccessful();

    Mail::assertNothingQueued();

    $post->refresh()->forceFill(['send_email' => StatusYes::YES])->save();

    expect(app(BlogService::class)->announce($post))->toBe(1);

    Mail::assertQueued(NewPostEmail::class, 1);
});

test('a draft is not announced', function () {
    subscribedMember();
    $post = scheduledPost('-1 minute', ['status' => StatusPost::DRAFT]);

    expect(app(BlogService::class)->announce($post))->toBeNull();

    Mail::assertNothingQueued();
});

test('a published post dated in the future is not announced yet', function () {
    subscribedMember();
    $post = scheduledPost('+1 hour', ['status' => StatusPost::PUBLISHED]);

    expect(app(BlogService::class)->announce($post))->toBeNull();

    Mail::assertNothingQueued();
});

test('scheduling a post for a date already past publishes it outright', function () {
    $post = scheduledPost('-1 hour', ['status' => StatusPost::DRAFT]);

    app(BlogService::class)->applyStatus($post, StatusPost::SCHEDULED);

    // Nothing to wait for, so it does not sit in a queue until the next tick.
    expect($post->status)->toBe(StatusPost::PUBLISHED);
});

test('one failing recipient does not cost the rest their notification', function () {
    subscribedMember();
    $reachable = subscribedMember();

    $post = scheduledPost();
    $calls = 0;

    $reached = app(NotificationSubscriberService::class)->broadcast(
        NotificationTypeEnum::ANNOUNCEMENTS,
        NotificationTopicEnum::NEW_POST,
        'Something happened',
        mailFor: function (User $user) use ($post, &$calls) {
            $calls++;

            // The first recipient blows up the way a bad address would.
            if ($calls === 1) {
                throw new RuntimeException('Bad address');
            }

            return new NewPostEmail($user, $post);
        },
    );

    // Both still got the bell entry, and the second still got their mail.
    expect($reached)->toBe(2)
        ->and($calls)->toBe(2);

    Mail::assertQueued(NewPostEmail::class, 1);
});
