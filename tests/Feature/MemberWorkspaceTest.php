<?php

use App\Enums\StatusDefault;
use App\Enums\UserTypeEnum;
use App\Models\NotificationType;
use App\Services\SiteConfigurationService;
use App\Services\UserService;
use Livewire\Livewire;

beforeEach(function () {
    $this->member = userOfType(UserTypeEnum::USER, ['email_verified_at' => now()]);
});

test('a member can open every account page', function (string $route) {
    // The account-deletion page is gated on both a site switch and a per-account
    // one, so the config has to exist and the profile has to be backfilled — which
    // is exactly what SiteConfigSeeder and the first dashboard visit do.
    app(SiteConfigurationService::class)->update(initials: true);
    app(UserService::class, ['user' => $this->member])->runProfileSettingsUpdate();

    $this->actingAs($this->member)->get(route($route))->assertSuccessful();
})->with([
    'user.dashboard',
    'user.profile',
    'user.account-settings',
    'user.security-settings',
    'user.delete-account',
]);

test('the delete-account page is hidden when the site switch is off', function () {
    app(SiteConfigurationService::class)->update(['user' => ['account-deletion' => false]]);
    app(UserService::class, ['user' => $this->member])->runProfileSettingsUpdate();

    $this->actingAs($this->member)->get(route('user.delete-account'))->assertNotFound();
});

test('an unverified member is let through when the site config has not been seeded', function () {
    $unverified = userOfType(UserTypeEnum::USER, ['email_verified_at' => null]);

    // A fresh install has no email-settings keys at all. The workspace has to stay
    // reachable rather than error on the missing key.
    $this->actingAs($unverified)->get(route('user.dashboard'))->assertSuccessful();
});

test('the dashboard backfills profile settings and subscriptions', function () {
    $types = seededNotificationTypes();

    expect($this->member->userProfile)->toBeNull();

    $this->actingAs($this->member)->get(route('user.dashboard'))->assertSuccessful();

    $fresh = $this->member->fresh();

    expect($fresh->userProfile)->not->toBeNull()
        ->and((array) $fresh->userProfile->settings)->toHaveKey('can-delete-account')
        ->and($fresh->notificationPreferences)->toHaveCount($types->count());
});

test('backfilling settings twice does not duplicate anything', function () {
    $types = seededNotificationTypes();

    $service = app(UserService::class, ['user' => $this->member]);

    $service->runProfileSettingsUpdate();
    $service->runNotificationPreferencesUpdate();
    $service->runProfileSettingsUpdate();
    $service->runNotificationPreferencesUpdate();

    $this->assertDatabaseCount('user_profiles', 1);
    $this->assertDatabaseCount('notification_preferences', $types->count());
});

test('a notification type added later is backfilled onto existing accounts', function () {
    seededNotificationTypes();

    $service = app(UserService::class, ['user' => $this->member]);
    $service->runNotificationPreferencesUpdate();

    $before = $this->member->notificationPreferences()->count();

    // The whole reason types are rows: one can be added after an account exists.
    NotificationType::query()->create([
        'notification_type' => 'product-updates',
        'title' => 'Product updates',
    ]);

    $service->runNotificationPreferencesUpdate();

    expect($this->member->notificationPreferences()->count())->toBe($before + 1);
});

test('an inactive notification type gets no preference row', function () {
    seededNotificationTypes();

    NotificationType::query()->create([
        'notification_type' => 'retired-channel',
        'title' => 'Retired channel',
        'status' => StatusDefault::INACTIVE,
    ]);

    app(UserService::class, ['user' => $this->member])->runNotificationPreferencesUpdate();

    $this->assertDatabaseCount('notification_preferences', NotificationType::query()->active()->count());
});

test('an unverified member is pushed to the verification page when strict mode is on', function () {
    app(SiteConfigurationService::class)->update(initials: true);

    $unverified = userOfType(UserTypeEnum::USER, ['email_verified_at' => null]);

    $this->actingAs($unverified)
        ->get(route('user.dashboard'))
        ->assertRedirect(route('email.verification', ['user' => $unverified->email, 'send' => true]));
});

test('profile completion reflects what has been filled in', function () {
    expect($this->member->profileCompletion())->toBe(0);

    $this->member->update(['avatar' => 'avatars/ada.png', 'phone_number' => '+2348000000000']);
    $this->member->userProfile()->create(['gender' => 'female', 'bio' => 'Builds things.']);

    expect($this->member->fresh()->profileCompletion())->toBe(100);
});

// ||||||||||||||||||||||||||||||||||||||||||||||||
// THE PHONE NAVIGATION

test('the floating menu is off until the site turns it on', function () {
    app(SiteConfigurationService::class)->update(initials: true);

    $this->actingAs($this->member)
        ->get(route('user.dashboard'))
        ->assertSuccessful()
        ->assertDontSee('aria-label="Workspace navigation"', escape: false);
});

test('the floating menu appears in the member workspace once it is on', function () {
    app(SiteConfigurationService::class)->update(['preferences' => ['mobile-floating-menu' => true]]);

    $this->actingAs($this->member)
        ->get(route('user.dashboard'))
        ->assertSuccessful()
        ->assertSee('aria-label="Workspace navigation"', escape: false)
        ->assertSee('More');
});

test('the admin workspace keeps its drawer regardless', function () {
    app(SiteConfigurationService::class)->update(['preferences' => ['mobile-floating-menu' => true]]);

    $admin = userOfType(UserTypeEnum::ADMIN, ['email_verified_at' => now()]);

    $this->actingAs($admin)
        ->get(route('admin.dashboard'))
        ->assertSuccessful()
        ->assertDontSee('aria-label="Workspace navigation"', escape: false);
});

test('an administrator can open the preferences screen and set the menu', function () {
    $admin = userOfType(UserTypeEnum::ADMIN, ['email_verified_at' => now()]);

    app(SiteConfigurationService::class)->update(initials: true);

    $this->actingAs($admin)->get(route('admin.config.preferences'))->assertSuccessful();

    Livewire::actingAs($admin)
        ->test('pages::admin.configs.preferences')
        ->set('config.preferences.mobile-floating-menu', true)
        ->call('save')
        ->assertHasNoErrors();

    expect(kSiteFlag('preferences', 'mobile-floating-menu'))->toBeTrue();
});

test('the security screen no longer carries the workspace preference', function () {
    $admin = userOfType(UserTypeEnum::ADMIN, ['email_verified_at' => now()]);

    app(SiteConfigurationService::class)->update(initials: true);

    $this->actingAs($admin)
        ->get(route('admin.config.security'))
        ->assertSuccessful()
        ->assertDontSee('Floating menu on phones');
});
