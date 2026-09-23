<?php

use App\Enums\ActivityActionEnum;
use App\Enums\AnnouncementLayoutEnum;
use App\Enums\StatusDefault;
use App\Enums\StatusYes;
use App\Enums\UserTypeEnum;
use App\Models\ActivityLog;
use App\Models\Announcement;
use App\Services\AnnouncementService;
use App\Services\NewsletterService;
use App\Services\SiteConfigurationService;
use Livewire\Livewire;

function announcement(array $attributes = []): Announcement
{
    return Announcement::query()->create([
        'title' => 'Summer sale',
        'body' => 'A quarter off everything this week.',
        'show_newsletter' => StatusYes::YES,
        'layout' => AnnouncementLayoutEnum::STACKED,
        'status' => StatusDefault::ACTIVE,
        ...$attributes,
    ]);
}

// ||||||||||||||||||||||||||||||||||||||||||||||||
// WHICH ONE SHOWS

test('the latest live announcement is the one shown', function () {
    announcement(['title' => 'Older', 'starts_at' => now()->subDays(3)]);
    $newer = announcement(['title' => 'Newer', 'starts_at' => now()->subDay()]);

    expect(app(AnnouncementService::class)->current()?->is($newer))->toBeTrue();
});

test('a switched-off or out-of-window announcement is not shown', function () {
    announcement(['status' => StatusDefault::INACTIVE]);
    announcement(['starts_at' => now()->addDay()]);
    announcement(['ends_at' => now()->subMinute()]);

    expect(app(AnnouncementService::class)->current())->toBeNull();
});

test('the public popup carries the announcement and the sign-up form', function () {
    announcement();

    $this->get(route('home'))
        ->assertOk()
        ->assertSee('Summer sale')
        ->assertSee('site-announcement')
        ->assertSee('newsletter-popup', false);
});

test('with the form off and no copy the popup is the picture alone', function () {
    // Unsaved: the shape is the point, and there is no library image to point at.
    $announcement = new Announcement(['title' => null, 'body' => null, 'show_newsletter' => StatusYes::NO, 'image_id' => 1]);

    expect($announcement->isImageOnly())->toBeTrue()
        ->and(app(AnnouncementService::class)->showsNewsletter($announcement))->toBeFalse();
});

test('the form is left off when the newsletter is not taking sign-ups', function () {
    app(SiteConfigurationService::class)->update(['preferences' => ['newsletter' => ['status' => false]]]);

    expect(app(AnnouncementService::class)->showsNewsletter(announcement()))->toBeFalse();
});

test('with nothing live the popup falls back to the newsletter', function () {
    expect(app(AnnouncementService::class)->showsPopup(null))
        ->toBe(app(NewsletterService::class)->showsPopup());
});

test('an edited announcement gets a new dismissal key', function () {
    $announcement = announcement();
    $before = $announcement->dismissKey();

    $this->travel(5)->seconds();
    $announcement->update(['title' => 'Winter sale']);

    expect($announcement->fresh()->dismissKey())->not->toBe($before);
});

// ||||||||||||||||||||||||||||||||||||||||||||||||
// THE ADMIN SCREEN

test('an administrator writes an announcement and the write is logged', function () {
    Livewire::actingAs(userOfType(UserTypeEnum::ADMIN))
        ->test('pages::admin.content.announcements')
        ->call('create')
        ->set('title', 'Black Friday')
        ->set('link_url', 'https://example.test/sale')
        ->set('link_label', 'Shop now')
        ->set('starts_on', now()->toDateString())
        ->set('ends_on', now()->addWeek()->toDateString())
        ->call('save')
        ->assertHasNoErrors();

    $saved = Announcement::query()->firstWhere('title', 'Black Friday');

    expect($saved)->not->toBeNull()
        ->and($saved->ends_at->toDateString())->toBe(now()->addWeek()->addDay()->toDateString())
        ->and($saved->isLive())->toBeTrue()
        ->and(ActivityLog::query()->where('activity_log_action', ActivityActionEnum::ANNOUNCEMENT_CREATE)->exists())->toBeTrue();
});

test('an announcement needs a picture or some copy', function () {
    Livewire::actingAs(userOfType(UserTypeEnum::ADMIN))
        ->test('pages::admin.content.announcements')
        ->call('create')
        ->call('save')
        ->assertHasErrors('image_id');
});

test('a member cannot open the announcements screen', function () {
    $this->actingAs(userOfType(UserTypeEnum::USER))
        ->get(route('admin.announcements'))
        ->assertNotFound();
});
