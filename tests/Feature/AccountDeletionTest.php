<?php

use App\Enums\ActivityActionEnum;
use App\Enums\DeletionReminderEnum;
use App\Enums\StatusUser;
use App\Enums\UserTypeEnum;
use App\Mail\AccountDeletionCancelledEmail;
use App\Mail\AccountDeletionReminderEmail;
use App\Mail\AccountDeletionScheduledEmail;
use App\Models\User;
use App\Models\UserDeletionReminder;
use App\Services\AccountDeletionService;
use App\Services\ActivityLogService;
use App\Services\ImageLibraryService;
use App\Services\SiteConfigurationService;
use App\Services\UserService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;

beforeEach(function () {
    Mail::fake();
    Storage::fake('public');

    app(SiteConfigurationService::class)->update(initials: true);

    $this->member = userOfType(UserTypeEnum::USER, ['email_verified_at' => now()]);

    // The delete-account screen is gated on the per-account switch as well as the
    // site one, and that switch is written by the first dashboard visit.
    app(UserService::class, ['user' => $this->member])->runProfileSettingsUpdate();
});

/**
 * An account already inside its grace period, without going through the screen.
 */
function scheduledMember(int $daysFromNow = 30, array $attributes = []): User
{
    return userOfType(UserTypeEnum::USER, [
        'email_verified_at' => now(),
        'status' => StatusUser::PENDING_DELETION,
        'deletion_requested_at' => now(),
        'deletion_scheduled_at' => now()->addDays($daysFromNow),
        ...$attributes,
    ]);
}

/**
 * Give an account something the deletion sweep has to preserve.
 *
 * Written through the service rather than by inserting a row, so the fixture
 * cannot drift from the columns an activity log actually requires.
 */
function givesTheAccountHistory(User $user): void
{
    auth()->login($user);

    app(ActivityLogService::class)->logActivity(ActivityActionEnum::LOGIN);

    auth()->logout();
}

// ||||||||||||||||||||||||||||||||||||||||||||||||
// ASKING TO BE DELETED

test('confirming deletion schedules the account rather than removing it', function () {
    Livewire::actingAs($this->member)
        ->test('pages::user.account.delete-account')
        ->set('password', 'password')
        ->set('confirmation_text', 'DELETE')
        ->call('step1')
        ->assertHasNoErrors();

    $fresh = $this->member->fresh();

    expect($fresh)->not->toBeNull()
        ->and($fresh->status)->toBe(StatusUser::PENDING_DELETION)
        ->and($fresh->deletion_requested_at)->not->toBeNull()
        ->and($fresh->deletion_scheduled_at->startOfDay()->toDateString())
        ->toBe(now()->addDays(30)->startOfDay()->toDateString());

    Mail::assertQueued(AccountDeletionScheduledEmail::class);
});

test('the grace period follows the configured number of days', function () {
    app(SiteConfigurationService::class)->update(['user' => ['account-deletion-days' => 7]]);

    $scheduledAt = app(AccountDeletionService::class)->schedule($this->member);

    expect($scheduledAt->startOfDay()->toDateString())
        ->toBe(now()->addDays(7)->startOfDay()->toDateString());
});

test('an account inside its grace period can still reach its workspace', function () {
    $pending = scheduledMember();

    $this->actingAs($pending)->get(route('user.dashboard'))->assertSuccessful();
    $this->assertAuthenticated();
});

test('a deleted account is signed out and turned away', function () {
    $deleted = userOfType(UserTypeEnum::USER, ['status' => StatusUser::DELETED]);

    $this->actingAs($deleted)->get(route('user.dashboard'))->assertRedirect(route('login'));
    $this->assertGuest();
});

// ||||||||||||||||||||||||||||||||||||||||||||||||
// CHANGING YOUR MIND

test('cancelling from the screen puts the account back', function () {
    $pending = scheduledMember();
    app(UserService::class, ['user' => $pending])->runProfileSettingsUpdate();

    Livewire::actingAs($pending)
        ->test('pages::user.account.delete-account')
        ->call('cancelDeletion')
        ->assertHasNoErrors();

    $fresh = $pending->fresh();

    expect($fresh->status)->toBe(StatusUser::ACTIVE)
        ->and($fresh->deletion_requested_at)->toBeNull()
        ->and($fresh->deletion_scheduled_at)->toBeNull();

    Mail::assertQueued(AccountDeletionCancelledEmail::class);
});

test('the signed restore link cancels the deletion', function () {
    $pending = scheduledMember();

    $this->get(kAccountRestoreUrl($pending))->assertRedirect(route('login'));

    expect($pending->fresh()->status)->toBe(StatusUser::ACTIVE);
});

test('the restore link is refused without a signature', function () {
    $pending = scheduledMember();

    $this->get(route('account.restore', ['user' => $pending->id]))->assertForbidden();

    expect($pending->fresh()->status)->toBe(StatusUser::PENDING_DELETION);
});

test('the restore link cannot be used twice', function () {
    $pending = scheduledMember();
    $url = kAccountRestoreUrl($pending);

    $this->get($url)->assertRedirect(route('login'));
    $this->get($url)->assertNotFound();
});

test('restoring clears the reminders already claimed', function () {
    $pending = scheduledMember();

    UserDeletionReminder::query()->create([
        'user_id' => $pending->id,
        'reminder' => DeletionReminderEnum::FIVE_DAYS,
        'sent_at' => now(),
    ]);

    app(AccountDeletionService::class)->cancel($pending);

    expect($pending->deletionReminders()->count())->toBe(0);
});

// ||||||||||||||||||||||||||||||||||||||||||||||||
// THE SWEEP

test('an account with no history is removed outright when its date passes', function () {
    $pending = scheduledMember(-1);
    $id = $pending->id;

    $this->artisan('account:process-deletions')->assertSuccessful();

    expect(User::withTrashed()->whereKey($id)->exists())->toBeFalse();
});

test('an account with history is anonymized while the switch is on', function () {
    $pending = scheduledMember(-1);

    givesTheAccountHistory($pending);

    $this->artisan('account:process-deletions')->assertSuccessful();

    $fresh = User::withTrashed()->find($pending->id);

    expect($fresh)->not->toBeNull()
        ->and($fresh->name)->toBe('Anonymous User')
        ->and($fresh->email)->toBe("deleted-user-{$pending->id}@anonymized.local")
        ->and($fresh->status)->toBe(StatusUser::DELETED)
        ->and($fresh->deletion_scheduled_at)->toBeNull()
        ->and($fresh->trashed())->toBeTrue();
});

test('an account with history is removed outright while the switch is off', function () {
    app(SiteConfigurationService::class)->update(['user' => ['anonymous-after-deletion' => false]]);

    $pending = scheduledMember(-1);

    givesTheAccountHistory($pending);

    $this->artisan('account:process-deletions')->assertSuccessful();

    expect(User::withTrashed()->whereKey($pending->id)->exists())->toBeFalse();
});

test('a hard delete takes the library files with it', function () {
    app(SiteConfigurationService::class)->update(['user' => ['anonymous-after-deletion' => false]]);

    $pending = scheduledMember(-1);

    $image = app(ImageLibraryService::class)->store($pending, UploadedFile::fake()->image('photo.jpg', 40, 30));
    $path = $image->file_path;

    Storage::disk('public')->assertExists($path);

    $this->artisan('account:process-deletions')->assertSuccessful();

    Storage::disk('public')->assertMissing($path);
    expect(User::withTrashed()->whereKey($pending->id)->exists())->toBeFalse();
});

test('an account whose date has not arrived is left alone', function () {
    $pending = scheduledMember(5);

    $this->artisan('account:process-deletions')->assertSuccessful();

    expect($pending->fresh()->status)->toBe(StatusUser::PENDING_DELETION);
});

test('a dry run reports without touching anything', function () {
    $pending = scheduledMember(-1);

    $this->artisan('account:process-deletions', ['--dry-run' => true])->assertSuccessful();

    expect($pending->fresh()->status)->toBe(StatusUser::PENDING_DELETION);
});

// ||||||||||||||||||||||||||||||||||||||||||||||||
// THE WARNINGS

test('a warning goes out five days and one day before the date', function (string $reminder, int $days) {
    $pending = scheduledMember($days);

    $this->artisan('account:send-deletion-reminders')->assertSuccessful();

    Mail::assertQueued(
        AccountDeletionReminderEmail::class,
        fn (AccountDeletionReminderEmail $mail) => $mail->user->is($pending)
            && $mail->reminder->value === $reminder,
    );
})->with([
    ['five-days', 5],
    ['one-day', 1],
]);

test('the same warning is never sent twice', function () {
    scheduledMember(5);

    $this->artisan('account:send-deletion-reminders')->assertSuccessful();
    $this->artisan('account:send-deletion-reminders')->assertSuccessful();

    Mail::assertQueuedCount(1);
});

test('an account outside every window is not warned', function () {
    scheduledMember(12);

    $this->artisan('account:send-deletion-reminders')->assertSuccessful();

    Mail::assertNothingQueued();
});

test('a dry run claims nothing and mails nothing', function () {
    $pending = scheduledMember(1);

    $this->artisan('account:send-deletion-reminders', ['--dry-run' => true])->assertSuccessful();

    Mail::assertNothingQueued();
    expect($pending->deletionReminders()->count())->toBe(0);
});

// ||||||||||||||||||||||||||||||||||||||||||||||||
// THE ADMIN SIDE

test('an administrator cannot flip the status of an account on its way out', function () {
    $admin = userOfType(UserTypeEnum::ADMIN, ['email_verified_at' => now()]);
    $pending = scheduledMember();

    Livewire::actingAs($admin)
        ->test('pages::admin.users.user-view', ['user' => $pending])
        ->call('toggleStatus');

    expect($pending->fresh()->status)->toBe(StatusUser::PENDING_DELETION);
});

test('saving an account does not overwrite a deletion in flight', function () {
    $admin = userOfType(UserTypeEnum::ADMIN, ['email_verified_at' => now()]);
    $pending = scheduledMember();

    // The form fields are filled by editAccount(), which is what opens the modal.
    Livewire::actingAs($admin)
        ->test('pages::admin.users.user-view', ['user' => $pending])
        ->call('editAccount')
        ->set('name', 'A New Name')
        ->call('save')
        ->assertHasNoErrors();

    $fresh = $pending->fresh();

    expect($fresh->name)->toBe('A New Name')
        ->and($fresh->status)->toBe(StatusUser::PENDING_DELETION)
        ->and($fresh->deletion_scheduled_at)->not->toBeNull();
});

// ||||||||||||||||||||||||||||||||||||||||||||||||
// THE SCREEN

test('the delete-account screen shows the countdown while one is running', function () {
    $pending = scheduledMember();
    app(UserService::class, ['user' => $pending])->runProfileSettingsUpdate();

    Livewire::actingAs($pending)
        ->test('pages::user.account.delete-account')
        ->assertSee('Scheduled for deletion')
        ->assertSee('Keep my account');
});

test('the signed restore url expires with the deletion date', function () {
    $pending = scheduledMember(2);

    $url = kAccountRestoreUrl($pending);

    $this->travel(3)->days();

    $this->get($url)->assertForbidden();

    expect(URL::hasValidSignature(request()->create($url)))->toBeFalse();
});
