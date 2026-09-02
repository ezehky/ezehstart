<?php

use App\Enums\ActivityActionEnum;
use App\Enums\UserRoleEnum;
use App\Mail\EmailVerificationOtpEmail;
use App\Mail\LoginEmail;
use App\Mail\WelcomeEmail;
use App\Models\User;
use App\Services\EmailVerificationOtpService;
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

    $user = userWithRole(UserRoleEnum::USER, ['password' => Hash::make('Password123!')]);

    Livewire::test('pages::auth.login')
        ->set('email', $user->email)
        ->set('password', 'Password123!')
        ->call('login');

    $this->assertAuthenticatedAs($user);

    // Signing in is both audited and announced to the account holder.
    Mail::assertQueued(LoginEmail::class);
    $this->assertDatabaseHas('activity_logs', [
        'user_id' => $user->id,
        'action' => ActivityActionEnum::LOGIN->value,
    ]);
});

test('bad credentials are rejected', function () {
    $user = userWithRole(UserRoleEnum::USER, ['password' => Hash::make('Password123!')]);

    Livewire::test('pages::auth.login')
        ->set('email', $user->email)
        ->set('password', 'wrong-password')
        ->call('login')
        ->assertHasErrors('email');

    $this->assertGuest();
});

test('an account with no password is told which flow to use', function () {
    $user = userWithRole(UserRoleEnum::USER, ['password' => null]);

    Livewire::test('pages::auth.login')
        ->set('email', $user->email)
        ->set('password', 'anything')
        ->call('login')
        ->assertHasErrors('email');
});

test('signing out clears the session', function () {
    $user = userWithRole(UserRoleEnum::USER);

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
