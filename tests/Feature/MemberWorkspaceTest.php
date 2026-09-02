<?php

use App\Enums\NotificationTypeEnum;
use App\Enums\UserRoleEnum;
use App\Services\SiteConfigurationService;
use App\Services\UserService;

beforeEach(function () {
    $this->member = userWithRole(UserRoleEnum::USER, ['email_verified_at' => now()]);
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
    $unverified = userWithRole(UserRoleEnum::USER, ['email_verified_at' => null]);

    // A fresh install has no email-settings keys at all. The workspace has to stay
    // reachable rather than error on the missing key.
    $this->actingAs($unverified)->get(route('user.dashboard'))->assertSuccessful();
});

test('the dashboard backfills profile settings and subscriptions', function () {
    expect($this->member->userProfile)->toBeNull();

    $this->actingAs($this->member)->get(route('user.dashboard'))->assertSuccessful();

    $fresh = $this->member->fresh();

    expect($fresh->userProfile)->not->toBeNull()
        ->and((array) $fresh->userProfile->settings)->toHaveKey('can-delete-account')
        ->and($fresh->notificationSubscriptions)->toHaveCount(\count(NotificationTypeEnum::cases()));
});

test('backfilling settings twice does not duplicate anything', function () {
    $service = app(UserService::class, ['user' => $this->member]);

    $service->runProfileSettingsUpdate();
    $service->runNotificationSubscriptionsUpdate();
    $service->runProfileSettingsUpdate();
    $service->runNotificationSubscriptionsUpdate();

    $this->assertDatabaseCount('user_profiles', 1);
    $this->assertDatabaseCount('notification_subscriptions', \count(NotificationTypeEnum::cases()));
});

test('an unverified member is pushed to the verification page when strict mode is on', function () {
    app(SiteConfigurationService::class)->update(initials: true);

    $unverified = userWithRole(UserRoleEnum::USER, ['email_verified_at' => null]);

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
