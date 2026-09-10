<?php

use App\Enums\StatusDefault;
use App\Enums\UserTypeEnum;
use App\Models\UserTwoFactor;
use App\Services\SiteConfigurationService;
use App\Services\TwoFactorService;
use Livewire\Livewire;
use PragmaRX\Google2FA\Google2FA;

beforeEach(function () {
    $this->member = userOfType(UserTypeEnum::USER, [
        'email_verified_at' => now(),
        'password' => 'Correct-horse-1!',
    ]);

    // Two-factor is off by default, so every test here has to switch it on first.
    app(SiteConfigurationService::class)->update([
        'security' => ['two-factor' => true, 'passwordless-login' => true],
    ]);
});

/**
 * A valid code for the secret currently on the account.
 */
function currentOtp(string $secret): string
{
    return (new Google2FA)->getCurrentOtp($secret);
}

// ||||||||||||||||||||||||||||||||||||||||||||||||
// AVAILABILITY

test('two factor is off unless the site switch turns it on', function () {
    app(SiteConfigurationService::class)->update(['security' => ['two-factor' => false]]);

    expect(app(TwoFactorService::class)->isAvailable())->toBeFalse();
});

test('the settings page hides the section when the feature is off', function () {
    app(SiteConfigurationService::class)->update(['security' => ['two-factor' => false]]);

    Livewire::actingAs($this->member)
        ->test('pages::user.account.security-settings')
        ->assertDontSee('Two-factor authentication');
});

// ||||||||||||||||||||||||||||||||||||||||||||||||
// ENROLMENT

test('starting enrolment creates an unconfirmed row that does not yet protect anything', function () {
    $twoFactor = app(TwoFactorService::class)->beginEnrolment($this->member);

    expect($twoFactor->secret)->not->toBeNull()
        ->and($twoFactor->confirmed_at)->toBeNull()
        ->and($twoFactor->isEnabled())->toBeFalse()
        ->and(app(TwoFactorService::class)->isEnabledFor($this->member->fresh()))->toBeFalse();
});

test('a correct code finishes enrolment and issues recovery codes', function () {
    $service = app(TwoFactorService::class);

    $twoFactor = $service->beginEnrolment($this->member);

    expect($service->confirm($this->member->fresh(), currentOtp($twoFactor->secret)))->toBeTrue();

    $fresh = $this->member->fresh();

    expect($fresh->twoFactor->isEnabled())->toBeTrue()
        ->and($fresh->twoFactor->remainingRecoveryCodes())->toBe(8);
});

test('a wrong code leaves enrolment unfinished', function () {
    $service = app(TwoFactorService::class);

    $service->beginEnrolment($this->member);

    expect($service->confirm($this->member->fresh(), '000000'))->toBeFalse()
        ->and($this->member->fresh()->twoFactor->isEnabled())->toBeFalse();
});

test('re-enrolling replaces the old secret so a lost authenticator stops working', function () {
    $service = app(TwoFactorService::class);

    $first = $service->beginEnrolment($this->member);
    $firstSecret = $first->secret;

    $second = $service->beginEnrolment($this->member->fresh());

    expect($second->secret)->not->toBe($firstSecret);
});

test('cancelling an unconfirmed enrolment removes the row', function () {
    app(TwoFactorService::class)->beginEnrolment($this->member);

    Livewire::actingAs($this->member->fresh())
        ->test('pages::user.account.security-settings')
        ->call('cancelTwoFactor');

    expect($this->member->fresh()->twoFactor)->toBeNull();
});

test('the whole enrolment works through the settings page', function () {
    $component = Livewire::actingAs($this->member)
        ->test('pages::user.account.security-settings')
        ->call('startTwoFactor');

    $secret = $this->member->fresh()->twoFactor->secret;

    $component->set('two_factor_code', currentOtp($secret))
        ->call('confirmTwoFactor')
        ->assertHasNoErrors();

    expect($this->member->fresh()->twoFactor->isEnabled())->toBeTrue();
});

// ||||||||||||||||||||||||||||||||||||||||||||||||
// RECOVERY CODES

test('a recovery code works once and is then spent', function () {
    $service = app(TwoFactorService::class);

    $twoFactor = $service->beginEnrolment($this->member);
    $service->confirm($this->member->fresh(), currentOtp($twoFactor->secret));

    $codes = (array) $this->member->fresh()->twoFactor->recovery_codes;
    $code = $codes[0];

    expect($service->useRecoveryCode($this->member->fresh(), $code))->toBeTrue()
        ->and($this->member->fresh()->twoFactor->remainingRecoveryCodes())->toBe(7)
        ->and($service->useRecoveryCode($this->member->fresh(), $code))->toBeFalse();
});

test('a recovery code is accepted however it was retyped', function () {
    $service = app(TwoFactorService::class);

    $twoFactor = $service->beginEnrolment($this->member);
    $service->confirm($this->member->fresh(), currentOtp($twoFactor->secret));

    $code = ((array) $this->member->fresh()->twoFactor->recovery_codes)[0];

    // Lower case and without the dash — how somebody actually types it back in.
    $retyped = mb_strtolower(str_replace('-', '', $code));

    expect($service->useRecoveryCode($this->member->fresh(), $retyped))->toBeTrue();
});

test('regenerating invalidates every code from the previous set', function () {
    $service = app(TwoFactorService::class);

    $twoFactor = $service->beginEnrolment($this->member);
    $service->confirm($this->member->fresh(), currentOtp($twoFactor->secret));

    $old = (array) $this->member->fresh()->twoFactor->recovery_codes;

    $new = $service->regenerateRecoveryCodes($this->member->fresh());

    expect($new)->toHaveCount(8)
        ->and(array_intersect($old, $new))->toBeEmpty()
        ->and($service->useRecoveryCode($this->member->fresh(), $old[0]))->toBeFalse();
});

// ||||||||||||||||||||||||||||||||||||||||||||||||
// SIGN IN

test('an account with two factor is not signed in by its password alone', function () {
    $service = app(TwoFactorService::class);

    $twoFactor = $service->beginEnrolment($this->member);
    $service->confirm($this->member->fresh(), currentOtp($twoFactor->secret));

    Livewire::test('pages::auth.login')
        ->set('email', $this->member->email)
        ->set('password', 'Correct-horse-1!')
        ->call('login')
        ->assertRedirect(route('two-factor.challenge'));

    // Half-way through sign-in is not signed in.
    $this->assertGuest();
});

test('the challenge completes the sign-in with a valid code', function () {
    $service = app(TwoFactorService::class);

    $twoFactor = $service->beginEnrolment($this->member);
    $service->confirm($this->member->fresh(), currentOtp($twoFactor->secret));

    Livewire::test('pages::auth.login')
        ->set('email', $this->member->email)
        ->set('password', 'Correct-horse-1!')
        ->call('login');

    $secret = $this->member->fresh()->twoFactor->secret;

    Livewire::test('pages::auth.two-factor-challenge')
        ->set('code', currentOtp($secret))
        ->call('verify')
        ->assertHasNoErrors();

    $this->assertAuthenticatedAs($this->member->fresh());
});

test('the challenge refuses a wrong code', function () {
    $service = app(TwoFactorService::class);

    $twoFactor = $service->beginEnrolment($this->member);
    $service->confirm($this->member->fresh(), currentOtp($twoFactor->secret));

    Livewire::test('pages::auth.login')
        ->set('email', $this->member->email)
        ->set('password', 'Correct-horse-1!')
        ->call('login');

    Livewire::test('pages::auth.two-factor-challenge')
        ->set('code', '000000')
        ->call('verify')
        ->assertHasErrors('code');

    $this->assertGuest();
});

test('the challenge redirects away when no sign-in is in progress', function () {
    $this->get(route('two-factor.challenge'))->assertRedirect(route('login'));
});

test('a recovery code gets somebody through the challenge', function () {
    $service = app(TwoFactorService::class);

    $twoFactor = $service->beginEnrolment($this->member);
    $service->confirm($this->member->fresh(), currentOtp($twoFactor->secret));

    $code = ((array) $this->member->fresh()->twoFactor->recovery_codes)[0];

    Livewire::test('pages::auth.login')
        ->set('email', $this->member->email)
        ->set('password', 'Correct-horse-1!')
        ->call('login');

    Livewire::test('pages::auth.two-factor-challenge')
        ->call('toggleRecovery')
        ->set('recovery_code', $code)
        ->call('verify')
        ->assertHasNoErrors();

    $this->assertAuthenticatedAs($this->member->fresh());
});

test('an account without two factor signs in as before', function () {
    Livewire::test('pages::auth.login')
        ->set('email', $this->member->email)
        ->set('password', 'Correct-horse-1!')
        ->call('login')
        ->assertHasNoErrors();

    $this->assertAuthenticatedAs($this->member);
});

// ||||||||||||||||||||||||||||||||||||||||||||||||
// DISABLING

test('turning it off destroys the secret rather than flagging it', function () {
    $service = app(TwoFactorService::class);

    $twoFactor = $service->beginEnrolment($this->member);
    $service->confirm($this->member->fresh(), currentOtp($twoFactor->secret));

    $service->disable($this->member->fresh());

    expect($this->member->fresh()->twoFactor)->toBeNull()
        ->and(UserTwoFactor::query()->count())->toBe(0);
});

test('a row that exists but was never confirmed does not gate sign-in', function () {
    app(TwoFactorService::class)->beginEnrolment($this->member);

    // Deliberately active-but-unconfirmed, the state a half-finished enrolment
    // would leave behind if status alone were trusted.
    $this->member->fresh()->twoFactor->update(['status' => StatusDefault::ACTIVE]);

    Livewire::test('pages::auth.login')
        ->set('email', $this->member->email)
        ->set('password', 'Correct-horse-1!')
        ->call('login')
        ->assertHasNoErrors();

    $this->assertAuthenticatedAs($this->member);
});
