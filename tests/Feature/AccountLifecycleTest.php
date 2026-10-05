<?php

use App\Enums\StatusUser;
use App\Enums\UserTypeEnum;
use App\Mail\InactivityReminderEmail;
use App\Mail\PasswordResetOtpEmail;
use App\Mail\UnverifiedAccountWarningEmail;
use App\Models\User;
use App\Services\SiteConfigurationService;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;

beforeEach(function () {
    Mail::fake();

    app(SiteConfigurationService::class)->update(initials: true);
});

/**
 * A member who signed up this many days ago and never verified.
 */
function unverifiedMember(int $daysAgo, array $attributes = []): User
{
    return userOfType(UserTypeEnum::USER, [
        'email_verified_at' => null,
        'created_at' => now()->subDays($daysAgo),
        ...$attributes,
    ]);
}

/**
 * A verified member last seen this many days ago.
 */
function quietMember(int $daysAgo, array $attributes = []): User
{
    return userOfType(UserTypeEnum::USER, [
        'email_verified_at' => now()->subYear(),
        'last_seen_at' => now()->subDays($daysAgo),
        ...$attributes,
    ]);
}

// ||||||||||||||||||||||||||||||||||||||||||||||||
// UNVERIFIED ACCOUNTS

test('an unverified account is warned once the notice days have passed', function () {
    $member = unverifiedMember(2);

    $this->artisan('account:prune-unverified')->assertSuccessful();

    Mail::assertQueued(
        UnverifiedAccountWarningEmail::class,
        fn (UnverifiedAccountWarningEmail $mail) => $mail->user->is($member) && $mail->daysUntilDeletion === 1,
    );

    expect($member->fresh()->unverified_notice_sent_at)->not->toBeNull();
});

test('a newer unverified account is left alone', function () {
    unverifiedMember(1);

    $this->artisan('account:prune-unverified')->assertSuccessful();

    Mail::assertNothingQueued();
});

test('the warning is never sent twice', function () {
    unverifiedMember(2);

    $this->artisan('account:prune-unverified')->assertSuccessful();
    $this->artisan('account:prune-unverified')->assertSuccessful();

    Mail::assertQueuedCount(1);
});

test('an account warned the grace period ago is deleted on the third day', function () {
    $member = unverifiedMember(3, ['unverified_notice_sent_at' => now()->subDay()]);

    $this->artisan('account:prune-unverified')->assertSuccessful();

    expect(User::withTrashed()->find($member->id))->toBeNull();
});

test('an account is never deleted on the same run that warns it', function () {
    // Old enough to be past the delete day, but never warned — the window is
    // counted from the warning, so it gets the warning and the full grace period.
    $member = unverifiedMember(10);

    $this->artisan('account:prune-unverified')->assertSuccessful();

    expect($member->fresh())->not->toBeNull();
    Mail::assertQueued(UnverifiedAccountWarningEmail::class);
});

test('an account verified after the warning is kept', function () {
    $member = unverifiedMember(3, [
        'unverified_notice_sent_at' => now()->subDay(),
        'email_verified_at' => now(),
    ]);

    $this->artisan('account:prune-unverified')->assertSuccessful();

    expect($member->fresh())->not->toBeNull();
});

test('admins, newsletter rows and accounts awaiting deletion are never swept', function () {
    $admin = userOfType(UserTypeEnum::ADMIN, ['email_verified_at' => null, 'created_at' => now()->subDays(5), 'unverified_notice_sent_at' => now()->subDays(2)]);
    $subscriber = unverifiedMember(5, ['status' => StatusUser::NEWSLETTER_SUBSCRIBER, 'unverified_notice_sent_at' => now()->subDays(2)]);
    $pending = unverifiedMember(5, ['status' => StatusUser::PENDING_DELETION, 'unverified_notice_sent_at' => now()->subDays(2)]);

    $this->artisan('account:prune-unverified')->assertSuccessful();

    expect($admin->fresh())->not->toBeNull()
        ->and($subscriber->fresh())->not->toBeNull()
        ->and($pending->fresh())->not->toBeNull();

    Mail::assertNothingQueued();
});

test('nothing is swept while auto-delete is off', function () {
    app(SiteConfigurationService::class)->update(['email-settings' => ['unverified-auto-delete' => false]]);

    $member = unverifiedMember(3, ['unverified_notice_sent_at' => now()->subDay()]);
    unverifiedMember(2);

    $this->artisan('account:prune-unverified')->assertSuccessful();

    expect($member->fresh())->not->toBeNull();
    Mail::assertNothingQueued();
});

test('nothing is swept while email verification itself is off', function () {
    // With verification off nobody can verify, so every account is unverified.
    app(SiteConfigurationService::class)->update(['email-settings' => ['verification' => false]]);

    $member = unverifiedMember(3, ['unverified_notice_sent_at' => now()->subDay()]);

    $this->artisan('account:prune-unverified')->assertSuccessful();

    expect($member->fresh())->not->toBeNull();
});

test('a dry run warns and deletes nothing', function () {
    $doomed = unverifiedMember(3, ['unverified_notice_sent_at' => now()->subDay()]);
    $fresh = unverifiedMember(2);

    $this->artisan('account:prune-unverified', ['--dry-run' => true])->assertSuccessful();

    expect($doomed->fresh())->not->toBeNull()
        ->and($fresh->fresh()->unverified_notice_sent_at)->toBeNull();

    Mail::assertNothingQueued();
});

// ||||||||||||||||||||||||||||||||||||||||||||||||
// INACTIVITY

test('a member away for thirty days is told they were missed', function () {
    $member = quietMember(30);

    $this->artisan('account:send-inactivity-reminders')->assertSuccessful();

    Mail::assertQueued(InactivityReminderEmail::class, fn (InactivityReminderEmail $mail) => $mail->user->is($member));
});

test('a member seen recently is not reminded', function () {
    quietMember(10);

    $this->artisan('account:send-inactivity-reminders')->assertSuccessful();

    Mail::assertNothingQueued();
});

test('one reminder per absence, and another only after the next visit', function () {
    $member = quietMember(40);

    $this->artisan('account:send-inactivity-reminders')->assertSuccessful();
    $this->artisan('account:send-inactivity-reminders')->assertSuccessful();

    Mail::assertQueuedCount(1);

    // Back once, then gone again for longer than the threshold.
    $this->travel(5)->days();
    $member->forceFill(['last_seen_at' => now()])->saveQuietly();
    $this->travel(31)->days();

    $this->artisan('account:send-inactivity-reminders')->assertSuccessful();

    Mail::assertQueuedCount(2);
});

test('unverified, suspended and admin accounts are not reminded', function () {
    quietMember(40, ['email_verified_at' => null]);
    quietMember(40, ['status' => StatusUser::SUSPENDED]);
    userOfType(UserTypeEnum::ADMIN, ['email_verified_at' => now(), 'last_seen_at' => now()->subDays(40)]);

    $this->artisan('account:send-inactivity-reminders')->assertSuccessful();

    Mail::assertNothingQueued();
});

test('no reminders go out while the switch is off', function () {
    app(SiteConfigurationService::class)->update(['user' => ['inactivity-reminder' => false]]);

    quietMember(40);

    $this->artisan('account:send-inactivity-reminders')->assertSuccessful();

    Mail::assertNothingQueued();
});

test('the reminder threshold follows the configured days', function () {
    app(SiteConfigurationService::class)->update(['user' => ['inactivity-reminder-days' => 7]]);

    quietMember(8);

    $this->artisan('account:send-inactivity-reminders')->assertSuccessful();

    Mail::assertQueuedCount(1);
});

// ||||||||||||||||||||||||||||||||||||||||||||||||
// THE SETTINGS SCREEN

test('the delete day must come after the warning day', function () {
    $admin = userOfType(UserTypeEnum::ADMIN, ['email_verified_at' => now()]);

    Livewire::actingAs($admin)
        ->test('pages::admin.configs.security')
        ->set('config.email-settings.unverified-notice-days', 3)
        ->set('config.email-settings.unverified-delete-days', 3)
        ->call('save')
        ->assertHasErrors('config.email-settings.unverified-delete-days');
});

// ||||||||||||||||||||||||||||||||||||||||||||||||
// FORGOT PASSWORD

test('an unknown address moves on as if it were an account, and gets no mail', function () {
    Livewire::test('pages::auth.forgot-password')
        ->set('email', 'nobody@example.test')
        ->call('step1')
        ->assertHasNoErrors()
        ->assertSet('step', 2);

    Mail::assertNothingSent();
});

test('a known address gets its code', function () {
    $member = quietMember(1);

    Livewire::test('pages::auth.forgot-password')
        ->set('email', $member->email)
        ->call('step1')
        ->assertHasNoErrors()
        ->assertSet('step', 2);

    Mail::assertSent(PasswordResetOtpEmail::class, 1);
});

test('a malformed address is still refused', function () {
    Livewire::test('pages::auth.forgot-password')
        ->set('email', 'not-an-email')
        ->call('step1')
        ->assertHasErrors('email');
});

test('an unknown address is held to the resend floor like a real one', function () {
    // Otherwise "please wait" would only ever appear for registered addresses.
    Livewire::test('pages::auth.forgot-password')
        ->set('email', 'nobody@example.test')
        ->call('step1')
        ->call('resendOtp')
        ->assertHasErrors('otp');
});

test('the password step cannot be reached without a verified code', function () {
    $member = quietMember(1);

    // Step one used to load the account, so calling step three straight after it
    // reset the password without the code. Now only a verified code loads it.
    Livewire::test('pages::auth.forgot-password')
        ->set('email', $member->email)
        ->call('step1')
        ->set('step', 3)
        ->set('password', 'Totally-new-2@')
        ->set('password_confirmation', 'Totally-new-2@')
        ->call('step3')
        ->assertForbidden();
});
