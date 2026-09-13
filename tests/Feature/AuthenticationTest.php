<?php

use App\Enums\ActivityActionEnum;
use App\Enums\UserTypeEnum;
use App\Mail\EmailVerificationOtpEmail;
use App\Mail\LoginEmail;
use App\Mail\WelcomeEmail;
use App\Models\User;
use App\Services\EmailVerificationOtpService;
use App\Services\SiteConfigurationService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;

test('a visitor can register and lands in the member workspace', function () {
    Mail::fake();

    Livewire::test('pages::auth.register')
        ->set('name', 'Ada Lovelace')
        ->set('email', 'ada@example.test')
        ->set('password', 'Password123!')
        ->set('password_confirmation', 'Password123!')
        ->set('agreed_to_terms', true)
        ->call('register');

    $this->assertAuthenticated();

    $user = User::query()->whereEmail('ada@example.test')->firstOrFail();

    // The account, its role and its profile are written together.
    expect($user->isUser())->toBeTrue()
        ->and($user->userProfile)->not->toBeNull();
});

test('registration requires the terms to be accepted', function () {
    Mail::fake();

    Livewire::test('pages::auth.register')
        ->set('name', 'Ada Lovelace')
        ->set('email', 'ada@example.test')
        ->set('password', 'Password123!')
        ->set('password_confirmation', 'Password123!')
        ->set('agreed_to_terms', false)
        ->call('register')
        ->assertHasErrors('agreed_to_terms');

    $this->assertGuest();
});

test('a user can sign in with their password', function () {
    Mail::fake();

    $user = userOfType(UserTypeEnum::USER, ['password' => Hash::make('Password123!')]);

    Livewire::test('pages::auth.login')
        ->set('email', $user->email)
        ->set('password', 'Password123!')
        ->call('login');

    $this->assertAuthenticatedAs($user);

    // Signing in is both audited and announced to the account holder.
    Mail::assertQueued(LoginEmail::class);
    $this->assertDatabaseHas('activity_logs', [
        'user_id' => $user->id,
        'activity_log_action' => ActivityActionEnum::LOGIN->value,
    ]);
});

test('bad credentials are rejected', function () {
    $user = userOfType(UserTypeEnum::USER, ['password' => Hash::make('Password123!')]);

    Livewire::test('pages::auth.login')
        ->set('email', $user->email)
        ->set('password', 'wrong-password')
        ->call('login')
        ->assertHasErrors('email');

    $this->assertGuest();
});

test('an account with no password is told which flow to use', function () {
    $user = userOfType(UserTypeEnum::USER, ['password' => null]);

    Livewire::test('pages::auth.login')
        ->set('email', $user->email)
        ->set('password', 'anything')
        ->call('login')
        ->assertHasErrors('email');
});

test('signing out clears the session', function () {
    $user = userOfType(UserTypeEnum::USER);

    $this->actingAs($user)->get(route('logout'))->assertRedirect(route('login'));

    $this->assertGuest();
});

test('a user can verify their email with a cached otp', function () {
    Mail::fake();

    $user = User::factory()->unverified()->create(['email' => 'ada@example.test']);
    $service = app(EmailVerificationOtpService::class);

    $service->sendWelcomeEmail($user);

    $otp = null;
    Mail::assertSent(WelcomeEmail::class, function (WelcomeEmail $mail) use (&$otp, $user) {
        $otp = $mail->otp;

        return $mail->user->is($user) && preg_match('/^\d{6}$/', $mail->otp) === 1;
    });

    expect($service->verify($user, $otp))->toBeTrue()
        ->and($user->fresh()->hasVerifiedEmail())->toBeTrue()
        // The code is single use: it is dropped the moment it works.
        ->and(Cache::has('email-verification-otp:'.$user->id))->toBeFalse();
});

test('a wrong verification code leaves the account unverified', function () {
    Mail::fake();

    $user = User::factory()->unverified()->create();
    $service = app(EmailVerificationOtpService::class);

    $service->sendWelcomeEmail($user);

    expect($service->verify($user, '000000'))->toBeFalse()
        ->and($user->fresh()->hasVerifiedEmail())->toBeFalse();
});

test('a resent code uses the resend mailable', function () {
    Mail::fake();

    $user = User::factory()->unverified()->create();

    app(EmailVerificationOtpService::class)->sendResentOtpEmail($user);

    Mail::assertSent(EmailVerificationOtpEmail::class, function (EmailVerificationOtpEmail $mail) use ($user) {
        return $mail->user->is($user) && preg_match('/^\d{6}$/', $mail->otp) === 1;
    });
});

// ||||||||||||||||||||||||||||||||||||||||||||||||
// WHAT THE GUEST SCREENS OFFER

/*
 * Both guest screens hand these links to the layout through <x-slot:extra>, and a
 * slot is only filled once the layout renders. Livewire::test() renders the
 * component on its own, so it sees the <form> and nothing around it — an
 * assertSee() there fails however well the feature works, and an assertDontSee()
 * passes however badly it works. These go over HTTP for that reason.
 */
test('a switched-off feature is off both guest screens, not just one of them', function () {
    app(SiteConfigurationService::class)->update([
        'security' => ['passwordless-login' => false],
    ]);

    $this->get(route('login'))->assertSuccessful()->assertDontSee(route('passwordless'));
    $this->get(route('register'))->assertSuccessful()->assertDontSee(route('passwordless'));
});

test('passwordless sign-in is linked from both guest screens while it is on', function () {
    app(SiteConfigurationService::class)->update([
        'security' => ['passwordless-login' => true],
    ]);

    $this->get(route('login'))->assertSuccessful()->assertSee(route('passwordless'));
    $this->get(route('register'))->assertSuccessful()->assertSee(route('passwordless'));
});

test('social sign-in is offered on the registration screen as well as the sign-in one', function () {
    config([
        'services.google.client_id' => 'test-client-id',
        'services.google.client_secret' => 'test-client-secret',
    ]);

    app(SiteConfigurationService::class)->update(['security' => ['socialite' => true]]);

    $this->get(route('login'))->assertSuccessful()->assertSee(route('social.redirect', 'google'));
    $this->get(route('register'))->assertSuccessful()->assertSee(route('social.redirect', 'google'));
});

test('the registration screen offers nothing social while the switch is off', function () {
    config([
        'services.google.client_id' => 'test-client-id',
        'services.google.client_secret' => 'test-client-secret',
    ]);

    app(SiteConfigurationService::class)->update(['security' => ['socialite' => false]]);

    $this->get(route('register'))->assertSuccessful()->assertDontSee(route('social.redirect', 'google'));
});
