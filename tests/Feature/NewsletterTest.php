<?php

use App\Enums\NotificationTopicEnum;
use App\Enums\StatusUser;
use App\Enums\UserTypeEnum;
use App\Models\User;
use App\Services\NewsletterService;
use App\Services\NotificationSubscriberService;
use App\Services\SiteConfigurationService;
use Livewire\Livewire;

/**
 * Turn the newsletter on with the given placement switches.
 *
 * Written out in full rather than merged into the defaults: the point of most of
 * these tests is that a switch saved as false is honoured, and a helper that only
 * ever set the keys it was asked about would leave the others absent and prove the
 * fallback instead.
 */
function newsletterConfig(array $overrides = []): void
{
    app(SiteConfigurationService::class)->update([
        'preferences' => [
            'newsletter' => [
                'status' => true,
                'popup' => true,
                'popup-delay' => 5,
                'footer' => true,
                ...$overrides,
            ],
        ],
    ]);
}

/**
 * The newsletter is a notification preference, so the types have to exist before
 * there is a switch to turn on. RefreshDatabase does not seed.
 */
beforeEach(function () {
    seededNotificationTypes();
});

// ||||||||||||||||||||||||||||||||||||||||||||||||
// THE SWITCHES

test('the newsletter is offered until the site turns it off', function () {
    app(SiteConfigurationService::class)->update(initials: true);

    $service = app(NewsletterService::class);

    expect($service->isEnabled())->toBeTrue()
        ->and($service->showsInFooter())->toBeTrue()
        ->and($service->showsPopup())->toBeTrue();
});

test('the master switch takes both placements with it', function () {
    newsletterConfig(['status' => false]);

    $service = app(NewsletterService::class);

    expect($service->isEnabled())->toBeFalse()
        ->and($service->showsInFooter())->toBeFalse()
        ->and($service->showsPopup())->toBeFalse();
});

test('a placement turned off stays off rather than falling back to its default', function () {
    newsletterConfig(['footer' => false]);

    $service = app(NewsletterService::class);

    // The nested read is the whole reason NewsletterService::flag() exists —
    // kSiteConfig() would hand back the default for a switch saved as false.
    expect($service->showsInFooter())->toBeFalse()
        ->and($service->showsPopup())->toBeTrue();
});

test('the popup delay is clamped to something a reader will actually see', function () {
    newsletterConfig(['popup-delay' => 0]);
    expect(app(NewsletterService::class)->popupDelay())->toBe(1);

    newsletterConfig(['popup-delay' => 9999]);
    expect(app(NewsletterService::class)->popupDelay())->toBe(NewsletterService::MAX_POPUP_DELAY);
});

// ||||||||||||||||||||||||||||||||||||||||||||||||
// THE PUBLIC PAGE

test('the footer block appears on a public page once it is on', function () {
    newsletterConfig();

    $this->get(route('home'))
        ->assertSuccessful()
        ->assertSee('Get the newsletter');
});

test('neither placement renders while the newsletter is off', function () {
    newsletterConfig(['status' => false]);

    $this->get(route('home'))
        ->assertSuccessful()
        ->assertDontSee('Get the newsletter')
        ->assertDontSee('aria-label="Newsletter sign-up"', escape: false);
});

test('the popup renders with the configured delay', function () {
    newsletterConfig(['popup-delay' => 12]);

    $this->get(route('home'))
        ->assertSuccessful()
        ->assertSee('aria-label="Newsletter sign-up"', escape: false)
        // Milliseconds in the markup, seconds in the configuration.
        ->assertSee('12000');
});

test('the popup can be turned off without taking the footer block with it', function () {
    newsletterConfig(['popup' => false]);

    $this->get(route('home'))
        ->assertSuccessful()
        ->assertSee('Get the newsletter')
        ->assertDontSee('aria-label="Newsletter sign-up"', escape: false);
});

// ||||||||||||||||||||||||||||||||||||||||||||||||
// WHAT A SIGN-UP WRITES

test('an address with no account becomes a subscriber row and nothing more', function () {
    newsletterConfig();

    Livewire::test('livewire.newsletter-form')
        ->set('email', 'reader@example.com')
        ->call('subscribe')
        ->assertHasNoErrors()
        ->assertSet('subscribed', true)
        ->assertDispatched('newsletter-subscribed');

    $row = User::query()->where('email', 'reader@example.com')->first();

    expect($row)->not->toBeNull()
        ->and($row->status)->toBe(StatusUser::NEWSLETTER_SUBSCRIBER)
        // No account: nothing to sign in with and nothing proved.
        ->and($row->password)->toBeNull()
        ->and($row->email_verified_at)->toBeNull()
        ->and(app(NewsletterService::class)->isSubscribed('reader@example.com'))->toBeTrue();
});

test('the subscriber gets the notification preferences every account has', function () {
    newsletterConfig();

    app(NewsletterService::class)->subscribe('reader@example.com');

    $row = User::query()->where('email', 'reader@example.com')->first();

    // One switch per active type, which is what makes unsubscribing the same
    // action for a subscriber as for a member.
    expect($row->notificationPreferences()->count())->toBe(seededNotificationTypes()->count());
});

test('the same address twice is one row, not an error', function () {
    newsletterConfig();

    app(NewsletterService::class)->subscribe('reader@example.com');

    Livewire::test('livewire.newsletter-form')
        ->set('email', 'READER@example.com')
        ->call('subscribe')
        ->assertHasNoErrors();

    // Lower-cased on the way in, so the same address in a different shape does not
    // become a second row nobody can see is a duplicate.
    expect(User::query()->where('email', 'reader@example.com')->count())->toBe(1);
});

test('an existing account subscribes without anything else about it changing', function () {
    newsletterConfig();

    $member = userOfType(UserTypeEnum::USER, ['email' => 'member@example.com', 'email_verified_at' => now()]);

    app(NewsletterService::class)->subscribe('member@example.com');

    $member->refresh();

    expect(User::query()->where('email', 'member@example.com')->count())->toBe(1)
        ->and($member->status)->toBe(StatusUser::ACTIVE)
        ->and(app(NewsletterService::class)->isSubscribed('member@example.com'))->toBeTrue();
});

test('unsubscribing switches the preference off and keeps the row', function () {
    newsletterConfig();

    $service = app(NewsletterService::class);
    $service->subscribe('reader@example.com');

    expect($service->unsubscribe('reader@example.com'))->toBeTrue()
        ->and($service->isSubscribed('reader@example.com'))->toBeFalse()
        ->and(User::query()->where('email', 'reader@example.com')->exists())->toBeTrue();

    // Subscribing again is a deliberate act by the address holder, and works.
    $service->subscribe('reader@example.com');

    expect($service->isSubscribed('reader@example.com'))->toBeTrue();
});

test('a form left open on a page cannot write once the newsletter is off', function () {
    newsletterConfig();

    $component = Livewire::test('livewire.newsletter-form')->set('email', 'reader@example.com');

    newsletterConfig(['status' => false]);

    $component->call('subscribe')->assertHasErrors('email');

    expect(User::query()->where('email', 'reader@example.com')->exists())->toBeFalse();
});

test('a malformed address is refused', function () {
    newsletterConfig();

    Livewire::test('livewire.newsletter-form')
        ->set('email', 'not-an-address')
        ->call('subscribe')
        ->assertHasErrors('email')
        ->assertSet('subscribed', false);

    expect(User::query()->where('email', 'not-an-address')->exists())->toBeFalse();
});

// ||||||||||||||||||||||||||||||||||||||||||||||||
// ONE LIST, NOT TWO

test('a send reaches subscribers through the same query as everybody else', function () {
    newsletterConfig();

    $member = userOfType(UserTypeEnum::USER, ['email' => 'member@example.com', 'email_verified_at' => now()]);
    app(NewsletterService::class)->subscribe($member->email);
    app(NewsletterService::class)->subscribe('reader@example.com');

    // The whole point of not having a subscribers table: one query answers who a
    // newsletter goes to, whether or not they ever opened an account.
    expect(app(NotificationSubscriberService::class)->subscriberCount(NewsletterService::TYPE))->toBe(2)
        ->and(app(NewsletterService::class)->subscriberCount())->toBe(2);
});

test('a subscriber is emailed but gets no bell entry', function () {
    newsletterConfig();

    app(NewsletterService::class)->subscribe('reader@example.com');

    app(NotificationSubscriberService::class)->broadcast(
        NewsletterService::TYPE,
        NotificationTopicEnum::NEW_POST,
        'A new post is up.',
    );

    $row = User::query()->where('email', 'reader@example.com')->first();

    // There is no workspace to open, so a database notification would be a row
    // nobody could ever read.
    expect($row->notifications()->count())->toBe(0);
});

// ||||||||||||||||||||||||||||||||||||||||||||||||
// NOT AN ACCOUNT

test('subscribers are left out of the members listing and its metrics', function () {
    newsletterConfig();

    userOfType(UserTypeEnum::USER, ['email' => 'member@example.com', 'email_verified_at' => now()]);
    app(NewsletterService::class)->subscribe('reader@example.com');

    expect(User::query()->users()->count())->toBe(1)
        ->and(User::query()->newsletterSubscribers()->count())->toBe(1);
});

test('a subscriber row cannot be signed in to with a password', function () {
    newsletterConfig();

    app(NewsletterService::class)->subscribe('reader@example.com');

    Livewire::test('pages::auth.login')
        ->set('email', 'reader@example.com')
        ->set('password', 'password')
        ->call('login')
        ->assertHasErrors('email');

    expect(auth()->check())->toBeFalse();
});

test('a subscriber address is refused a password reset', function () {
    newsletterConfig();

    app(NewsletterService::class)->subscribe('reader@example.com');

    Livewire::test('pages::auth.forgot-password')
        ->set('email', 'reader@example.com')
        ->call('step1')
        ->assertHasErrors('email');
});

test('the status is not something an administrator can assign', function () {
    // It is what a row is until somebody registers. Saving it onto a real account
    // would strip that account of its sign-in without deleting anything.
    expect(StatusUser::forSelect())->not->toHaveKey(StatusUser::NEWSLETTER_SUBSCRIBER->value);
});

// ||||||||||||||||||||||||||||||||||||||||||||||||
// REGISTERING LATER

test('registering with a subscribed address claims the row instead of colliding', function () {
    newsletterConfig();

    $subscriber = app(NewsletterService::class)->subscribe('reader@example.com');

    Livewire::test('pages::auth.register')
        ->set('name', 'Reader Person')
        ->set('email', 'reader@example.com')
        ->set('password', 'Password1!')
        ->set('password_confirmation', 'Password1!')
        ->set('agreed_to_terms', true)
        ->call('register')
        ->assertHasNoErrors();

    // One row, the same row, now an account — and still subscribed.
    expect(User::query()->where('email', 'reader@example.com')->count())->toBe(1);

    $user = User::query()->where('email', 'reader@example.com')->first();

    expect($user->id)->toBe($subscriber->id)
        ->and($user->status)->toBe(StatusUser::ACTIVE)
        ->and($user->name)->toBe('Reader Person')
        ->and($user->password)->not->toBeNull()
        ->and(app(NewsletterService::class)->isSubscribed('reader@example.com'))->toBeTrue();
});

test('a subscriber going through passwordless sign-in is registered, not signed in', function () {
    newsletterConfig();

    app(NewsletterService::class)->subscribe('reader@example.com');

    // The row exists, but there is no account behind it and nothing was agreed to,
    // so step one routes to the details form rather than mailing a sign-in code.
    Livewire::test('pages::auth.passwordless')
        ->set('email', 'reader@example.com')
        ->call('submitEmail')
        ->assertHasNoErrors()
        ->assertSet('isNewAccount', true)
        ->assertSet('step', 'details');
});

test('an ordinary registration is unaffected', function () {
    newsletterConfig();

    Livewire::test('pages::auth.register')
        ->set('name', 'New Person')
        ->set('email', 'new@example.com')
        ->set('password', 'Password1!')
        ->set('password_confirmation', 'Password1!')
        ->set('agreed_to_terms', true)
        ->call('register')
        ->assertHasNoErrors();

    $user = User::query()->where('email', 'new@example.com')->first();

    expect($user)->not->toBeNull()
        ->and($user->status)->toBe(StatusUser::ACTIVE);
});

// ||||||||||||||||||||||||||||||||||||||||||||||||
// THE ADMIN SCREEN

test('an administrator can turn the newsletter on and choose its placements', function () {
    $admin = userOfType(UserTypeEnum::ADMIN, ['email_verified_at' => now()]);

    app(SiteConfigurationService::class)->update(initials: true);

    Livewire::actingAs($admin)
        ->test('pages::admin.configs.preferences')
        ->set('config.preferences.newsletter.status', true)
        ->set('config.preferences.newsletter.footer', false)
        ->set('config.preferences.newsletter.popup', true)
        ->set('config.preferences.newsletter.popup-delay', 20)
        ->call('save')
        ->assertHasNoErrors();

    $service = app(NewsletterService::class);

    expect($service->showsInFooter())->toBeFalse()
        ->and($service->showsPopup())->toBeTrue()
        ->and($service->popupDelay())->toBe(20);
});

test('the popup delay is not asked for while the newsletter is off', function () {
    $admin = userOfType(UserTypeEnum::ADMIN, ['email_verified_at' => now()]);

    app(SiteConfigurationService::class)->update(initials: true);

    // Nothing dependent is on the screen, so nothing dependent is validated —
    // a save that only turns the feature off must not fail on a hidden field.
    Livewire::actingAs($admin)
        ->test('pages::admin.configs.preferences')
        ->set('config.preferences.newsletter.status', false)
        ->set('config.preferences.newsletter.popup-delay', null)
        ->call('save')
        ->assertHasNoErrors();

    expect(app(NewsletterService::class)->isEnabled())->toBeFalse();
});

test('a silly popup delay is refused at the screen as well as clamped in the service', function () {
    $admin = userOfType(UserTypeEnum::ADMIN, ['email_verified_at' => now()]);

    app(SiteConfigurationService::class)->update(initials: true);

    Livewire::actingAs($admin)
        ->test('pages::admin.configs.preferences')
        ->set('config.preferences.newsletter.status', true)
        ->set('config.preferences.newsletter.popup', true)
        ->set('config.preferences.newsletter.popup-delay', 9999)
        ->call('save')
        ->assertHasErrors('config.preferences.newsletter.popup-delay');
});
