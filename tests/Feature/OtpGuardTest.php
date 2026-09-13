<?php

use App\Enums\UserTypeEnum;
use App\Mail\PasswordResetOtpEmail;
use App\Models\User;
use App\Services\AccountOtpService;
use App\Services\EmailVerificationOtpService;
use App\Services\PasswordResetOtpService;
use App\Services\PasswordSecurityService;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;

beforeEach(function () {
    $this->member = userOfType(UserTypeEnum::USER, [
        'email_verified_at' => now(),
        'password' => 'Correct-horse-1!',
    ]);
});

/**
 * Ask for a reset code and hand back what was mailed.
 */
function issuedResetCode(User $user): string
{
    app(PasswordResetOtpService::class)->send($user);

    $otp = null;

    Mail::assertSent(PasswordResetOtpEmail::class, function (PasswordResetOtpEmail $mail) use (&$otp) {
        $otp = $mail->otp;

        return true;
    });

    return (string) $otp;
}

// ||||||||||||||||||||||||||||||||||||||||||||||||
// THE GUESS ALLOWANCE

test('a password reset code is destroyed once the guess allowance is spent', function () {
    Mail::fake();

    $service = app(PasswordResetOtpService::class);
    $otp = issuedResetCode($this->member);

    // The allowance is spent on wrong codes, and the last of them takes the code with
    // it — which is the whole point, because a six-digit code standing for an hour is
    // otherwise a million guesses wide.
    foreach (range(1, PasswordResetOtpService::MAX_ATTEMPTS) as $attempt) {
        expect($service->verify($this->member->email, '000000'))->toBeFalse();
    }

    expect($service->verify($this->member->email, $otp))->toBeFalse();
});

test('a reset code still works while the allowance has something left in it', function () {
    Mail::fake();

    $service = app(PasswordResetOtpService::class);
    $otp = issuedResetCode($this->member);

    foreach (range(1, PasswordResetOtpService::MAX_ATTEMPTS - 1) as $attempt) {
        expect($service->verify($this->member->email, '000000'))->toBeFalse();
    }

    expect($service->verify($this->member->email, $otp))->toBeTrue();
});

test('an account otp is destroyed once the guess allowance is spent', function () {
    Mail::fake();

    $service = app(AccountOtpService::class);
    $otp = $service->send($this->member, 'change-password');

    foreach (range(1, AccountOtpService::MAX_ATTEMPTS) as $attempt) {
        expect($service->verify($this->member, 'change-password', '000000'))->toBeFalse();
    }

    expect($service->verify($this->member, 'change-password', $otp))->toBeFalse();
});

test('one purpose spending its allowance leaves another purpose alone', function () {
    Mail::fake();

    $service = app(AccountOtpService::class);
    $service->send($this->member, 'change-password');
    $emailOtp = $service->send($this->member, 'change-email');

    foreach (range(1, AccountOtpService::MAX_ATTEMPTS) as $attempt) {
        expect($service->verify($this->member, 'change-password', '000000'))->toBeFalse();
    }

    // The two codes are counted separately, so guessing at one does not close the
    // other — they confirm different changes and share nothing but the account.
    expect($service->verify($this->member, 'change-email', $emailOtp))->toBeTrue();
});

test('an email verification code is destroyed once the guess allowance is spent', function () {
    Mail::fake();

    $user = User::factory()->unverified()->create();
    $service = app(EmailVerificationOtpService::class);

    $service->sendResentOtpEmail($user);

    foreach (range(1, EmailVerificationOtpService::MAX_ATTEMPTS) as $attempt) {
        expect($service->verify($user, '000000'))->toBeFalse();
    }

    expect($user->fresh()->hasVerifiedEmail())->toBeFalse();
});

// ||||||||||||||||||||||||||||||||||||||||||||||||
// THE RESEND FLOOR

test('another reset code cannot be requested inside the resend floor', function () {
    Mail::fake();

    app(PasswordResetOtpService::class)->send($this->member);

    expect(app(PasswordResetOtpService::class)->secondsUntilResendFor($this->member->email))
        ->toBeGreaterThan(0);
});

test('the reset screen refuses a resend inside the floor rather than sending anyway', function () {
    Mail::fake();

    $component = Livewire::test('pages::auth.forgot-password')
        ->set('email', $this->member->email)
        ->call('step1')
        ->assertHasNoErrors();

    Mail::assertSent(PasswordResetOtpEmail::class, 1);

    // Without the floor the captcha on step one buys one pass at an inbox and this
    // button turns it into as much mail as anybody cares to send.
    $component->call('resendOtp')->assertHasErrors('otp');

    Mail::assertSent(PasswordResetOtpEmail::class, 1);
});

test('an account otp resend is refused inside the floor', function () {
    Mail::fake();

    app(AccountOtpService::class)->send($this->member, 'change-password');

    expect(app(AccountOtpService::class)->secondsUntilResendFor($this->member, 'change-password'))
        ->toBeGreaterThan(0);
});

test('the security screen refuses a password code resend inside the floor', function () {
    Mail::fake();

    $component = Livewire::actingAs($this->member)
        ->test('pages::user.account.security-settings')
        ->set('current_password', 'Correct-horse-1!')
        ->call('passwordStep1')
        ->assertHasNoErrors();

    $component->call('passwordResendOtp')->assertHasErrors('password_otp');
});

// ||||||||||||||||||||||||||||||||||||||||||||||||
// HISTORY ON EVERY PASSWORD WRITE

test('a password reset refuses a password the account has used before', function () {
    Mail::fake();

    // The reset is the same password write as the one on the security page, so it
    // answers to the same history — otherwise the way around history is to claim to
    // have forgotten the password on purpose.
    Livewire::test('pages::auth.forgot-password')
        ->set('user', $this->member)
        ->set('email', $this->member->email)
        ->set('step', 3)
        ->set('password', 'Correct-horse-1!')
        ->set('password_confirmation', 'Correct-horse-1!')
        ->call('step3')
        ->assertHasErrors('password');

    expect(Hash::check('Correct-horse-1!', $this->member->fresh()->password))->toBeTrue();
});

test('a password reset files the old hash away in history', function () {
    Mail::fake();

    $previous = $this->member->password;

    Livewire::test('pages::auth.forgot-password')
        ->set('user', $this->member)
        ->set('email', $this->member->email)
        ->set('step', 3)
        ->set('password', 'Totally-new-2@')
        ->set('password_confirmation', 'Totally-new-2@')
        ->call('step3')
        ->assertHasNoErrors();

    expect($this->member->passwordHistories()->pluck('password')->all())->toContain($previous)
        ->and(Hash::check('Totally-new-2@', $this->member->fresh()->password))->toBeTrue();
});

test('a used reset code does not survive the reset it authorised', function () {
    Mail::fake();

    $otp = issuedResetCode($this->member);

    Livewire::test('pages::auth.forgot-password')
        ->set('user', $this->member)
        ->set('email', $this->member->email)
        ->set('step', 3)
        ->set('password', 'Totally-new-2@')
        ->set('password_confirmation', 'Totally-new-2@')
        ->call('step3');

    expect(app(PasswordResetOtpService::class)->verify($this->member->email, $otp))->toBeFalse();
});

test('the profile screen refuses a password the account has used before', function () {
    Mail::fake();

    $admin = userOfType(UserTypeEnum::ADMIN, [
        'email_verified_at' => now(),
        'password' => 'Correct-horse-1!',
    ]);

    // The profile screen is the admin workspace's only password change, so it is the
    // one that most needs to answer to history.
    Livewire::actingAs($admin)
        ->test('pages::shared.profile')
        ->set('passwordStep', 3)
        ->set('new_password', 'Correct-horse-1!')
        ->set('new_password_confirmation', 'Correct-horse-1!')
        ->call('passwordStep3')
        ->assertHasErrors('new_password');
});

test('the profile screen files the old hash away in history', function () {
    Mail::fake();

    $admin = userOfType(UserTypeEnum::ADMIN, [
        'email_verified_at' => now(),
        'password' => 'Correct-horse-1!',
    ]);

    $previous = $admin->password;

    Livewire::actingAs($admin)
        ->test('pages::shared.profile')
        ->set('passwordStep', 3)
        ->set('new_password', 'Totally-new-2@')
        ->set('new_password_confirmation', 'Totally-new-2@')
        ->call('passwordStep3')
        ->assertHasNoErrors();

    expect($admin->passwordHistories()->pluck('password')->all())->toContain($previous);
});

test('a password an administrator set is remembered in history', function () {
    $previous = $this->member->password;

    $admin = userOfType(UserTypeEnum::ADMIN, ['email_verified_at' => now()]);

    // An account whose password an administrator set must not be able to put that same
    // password straight back, which only holds if the write was recorded.
    Livewire::actingAs($admin)
        ->test('pages::admin.users.user-view', ['user' => $this->member])
        ->set('name', $this->member->name)
        ->set('email', $this->member->email)
        ->set('password', 'Admin-set-3#')
        ->call('save');

    expect($this->member->passwordHistories()->pluck('password')->all())->toContain($previous)
        ->and(app(PasswordSecurityService::class)->hasBeenUsed($this->member->fresh(), 'Admin-set-3#'))->toBeTrue();
});
