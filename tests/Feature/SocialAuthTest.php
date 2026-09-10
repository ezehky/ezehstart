<?php

use App\Enums\SocialProviderEnum;
use App\Enums\StatusUser;
use App\Enums\UserRoleEnum;
use App\Models\User;
use App\Models\UserConnectedAccount;
use App\Services\SiteConfigurationService;
use App\Services\SocialAccountService;
use Illuminate\Database\QueryException;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;

beforeEach(function () {
    // Credentials live in the environment, so a provider is only "configured"
    // once these are set — the enum reads exactly this.
    config([
        'services.google.client_id' => 'test-client-id',
        'services.google.client_secret' => 'test-client-secret',
    ]);

    app(SiteConfigurationService::class)->update(['security' => ['socialite' => true]]);
});

/**
 * A provider identity, shaped the way Socialite hands one back.
 */
function socialiteUser(string $id = 'provider-123', ?string $email = 'someone@example.test'): SocialiteUser
{
    $user = new SocialiteUser;

    $user->map([
        'id' => $id,
        'nickname' => 'someone',
        'name' => 'Some One',
        'email' => $email,
        'avatar' => 'https://example.test/avatar.png',
    ]);

    $user->token = 'access-token';
    $user->refreshToken = 'refresh-token';
    $user->expiresIn = 3600;

    return $user;
}

function fakeSocialiteReturns(SocialiteUser $user): void
{
    $provider = Mockery::mock('Laravel\Socialite\Contracts\Provider');
    $provider->shouldReceive('user')->andReturn($user);

    Socialite::shouldReceive('driver')->with('google')->andReturn($provider);
}

// ||||||||||||||||||||||||||||||||||||||||||||||||
// AVAILABILITY

test('social sign-in is off unless the site switch turns it on', function () {
    app(SiteConfigurationService::class)->update(['security' => ['socialite' => false]]);

    expect(app(SocialAccountService::class)->isAvailable())->toBeFalse();
});

test('a provider with no credentials is never offered', function () {
    config(['services.google.client_id' => null]);

    expect(SocialProviderEnum::GOOGLE->isConfigured())->toBeFalse()
        ->and(app(SocialAccountService::class)->enabledProviders())->not->toContain(SocialProviderEnum::GOOGLE);
});

test('an unconfigured provider route is a 404, not a friendly error', function () {
    config(['services.google.client_id' => null]);

    $this->get(route('social.redirect', 'google'))->assertNotFound();
});

test('an unknown provider is a 404', function () {
    $this->get('/auth/myspace/redirect')->assertNotFound();
});

test('the switch closes the route, not just the button', function () {
    app(SiteConfigurationService::class)->update(['security' => ['socialite' => false]]);

    $this->get(route('social.redirect', 'google'))->assertNotFound();
});

// ||||||||||||||||||||||||||||||||||||||||||||||||
// SIGNING IN

test('a first-time identity creates an account and signs it in', function () {
    fakeSocialiteReturns(socialiteUser());

    $this->get(route('social.callback', 'google'))->assertRedirect(route('user.dashboard'));

    $user = User::query()->where('email', 'someone@example.test')->first();

    expect($user)->not->toBeNull()
        ->and($user->password)->toBeNull()
        // The provider already verified the address, which is the whole reason
        // this is a shortcut worth having.
        ->and($user->email_verified_at)->not->toBeNull();

    $this->assertAuthenticatedAs($user);
});

test('a returning identity signs into the same account rather than making another', function () {
    fakeSocialiteReturns(socialiteUser());
    $this->get(route('social.callback', 'google'));

    $first = User::query()->where('email', 'someone@example.test')->first();

    auth()->logout();

    fakeSocialiteReturns(socialiteUser());
    $this->get(route('social.callback', 'google'));

    expect(User::query()->where('email', 'someone@example.test')->count())->toBe(1);

    $this->assertAuthenticatedAs($first->fresh());
});

test('an identity whose email changed still lands on the original account', function () {
    fakeSocialiteReturns(socialiteUser());
    $this->get(route('social.callback', 'google'));

    $user = User::query()->where('email', 'someone@example.test')->first();

    auth()->logout();

    // Same provider id, different email. Matching on email first would strand
    // this person with a second account.
    fakeSocialiteReturns(socialiteUser(email: 'changed@example.test'));
    $this->get(route('social.callback', 'google'));

    expect(User::query()->count())->toBe(1);

    $this->assertAuthenticatedAs($user->fresh());
});

test('a provider identity matching an existing email links rather than duplicates', function () {
    $existing = userWithRole(UserRoleEnum::USER, [
        'email' => 'someone@example.test',
        'email_verified_at' => now(),
    ]);

    fakeSocialiteReturns(socialiteUser());

    $this->get(route('social.callback', 'google'));

    expect(User::query()->where('email', 'someone@example.test')->count())->toBe(1)
        ->and($existing->fresh()->connectedAccounts()->count())->toBe(1);

    $this->assertAuthenticatedAs($existing->fresh());
});

test('a provider that shares no email cannot sign anybody in', function () {
    fakeSocialiteReturns(socialiteUser(email: null));

    $this->get(route('social.callback', 'google'))->assertRedirect(route('login'));

    expect(User::query()->count())->toBe(0);
    $this->assertGuest();
});

test('a suspended account cannot walk back in through a provider', function () {
    $suspended = userWithRole(UserRoleEnum::USER, [
        'email' => 'someone@example.test',
        'email_verified_at' => now(),
        'status' => StatusUser::SUSPENDED,
    ]);

    fakeSocialiteReturns(socialiteUser());

    $this->get(route('social.callback', 'google'))->assertRedirect(route('login'));

    $this->assertGuest();
});

test('tokens are stored encrypted, not in the clear', function () {
    fakeSocialiteReturns(socialiteUser());
    $this->get(route('social.callback', 'google'));

    $account = UserConnectedAccount::query()->first();

    expect($account->provider_token)->toBe('access-token');

    // The raw column must not contain the token as typed.
    $raw = DB::table('user_connected_accounts')->where('id', $account->id)->value('provider_token');

    expect($raw)->not->toBe('access-token');
});

// ||||||||||||||||||||||||||||||||||||||||||||||||
// LINKING AND UNLINKING

test('an already signed-in user connects a provider instead of signing in again', function () {
    $member = userWithRole(UserRoleEnum::USER, ['email_verified_at' => now()]);

    fakeSocialiteReturns(socialiteUser(email: 'other@example.test'));

    $this->actingAs($member)
        ->get(route('social.callback', 'google'))
        ->assertRedirect(route('user.security-settings'));

    expect($member->fresh()->connectedAccounts()->count())->toBe(1)
        // Connecting must not change who is signed in, or move their email.
        ->and($member->fresh()->email)->not->toBe('other@example.test');
});

test('the last sign-in method cannot be disconnected', function () {
    fakeSocialiteReturns(socialiteUser());
    $this->get(route('social.callback', 'google'));

    $user = User::query()->where('email', 'someone@example.test')->first();

    // No password and one provider: removing it would lock this account out for good.
    $error = app(SocialAccountService::class)->unlink($user->fresh(), SocialProviderEnum::GOOGLE);

    expect($error)->not->toBeNull()
        ->and($user->fresh()->connectedAccounts()->count())->toBe(1);
});

test('a provider can be disconnected once a password exists', function () {
    fakeSocialiteReturns(socialiteUser());
    $this->get(route('social.callback', 'google'));

    $user = User::query()->where('email', 'someone@example.test')->first();
    $user->forceFill(['password' => 'Correct-horse-1!'])->save();

    $error = app(SocialAccountService::class)->unlink($user->fresh(), SocialProviderEnum::GOOGLE);

    expect($error)->toBeNull()
        ->and($user->fresh()->connectedAccounts()->count())->toBe(0);
});

test('one provider identity cannot be claimed by two local accounts', function () {
    fakeSocialiteReturns(socialiteUser());
    $this->get(route('social.callback', 'google'));

    $other = userWithRole(UserRoleEnum::USER, ['email_verified_at' => now()]);

    // The unique index on (provider, provider_id) is what enforces this — without
    // it, sign-in becomes a coin toss between two accounts.
    expect(fn () => UserConnectedAccount::query()->create([
        'user_id' => $other->id,
        'provider' => SocialProviderEnum::GOOGLE,
        'provider_id' => 'provider-123',
    ]))->toThrow(QueryException::class);
});
