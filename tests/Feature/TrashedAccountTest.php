<?php

use App\Enums\ActivityActionEnum;
use App\Enums\GateAccessEnum;
use App\Enums\StatusUser;
use App\Enums\UserTypeEnum;
use App\Models\ActivityLog;
use App\Models\User;
use App\Services\AccountDeletionService;
use Livewire\Livewire;

beforeEach(function () {
    $this->admin = userOfType(UserTypeEnum::ADMIN);
});

/**
 * A member who reached the end of their deletion window and was anonymized.
 */
function anonymizedMember(): User
{
    $member = userOfType(UserTypeEnum::USER, ['email_verified_at' => now()]);

    app(AccountDeletionService::class)->anonymize($member);

    return $member;
}

test('an anonymized account is a soft-deleted row that no ordinary query sees', function () {
    $member = anonymizedMember();

    // The reason the screen has to exist: the default scope hides these everywhere.
    expect(User::query()->whereKey($member->id)->exists())->toBeFalse()
        ->and(app(AccountDeletionService::class)->trashedQuery()->whereKey($member->id)->exists())->toBeTrue();
});

test('the screen lists an anonymized account', function () {
    $member = anonymizedMember();

    Livewire::actingAs($this->admin)
        ->test('pages::admin.users.trashed-accounts')
        ->assertOk()
        ->assertSee($member->fresh()->email);
});

test('an active account is not on the list', function () {
    $member = userOfType(UserTypeEnum::USER, ['email_verified_at' => now()]);

    Livewire::actingAs($this->admin)
        ->test('pages::admin.users.trashed-accounts')
        ->assertDontSee($member->email);
});

// |||||||||||||||||||||||||||||||||||||||||||||||||||||||||
// RESTORE

test('restoring brings the row back without restoring access', function () {
    $member = anonymizedMember();

    Livewire::actingAs($this->admin)
        ->test('pages::admin.users.trashed-accounts')
        ->call('confirmRestore', $member->id)
        ->call('restore')
        ->assertHasNoErrors();

    $restored = User::query()->whereKey($member->id)->first();

    // The record is back and its history resolves again — but the identity was
    // overwritten before the row was trashed, so nobody got their account back.
    expect($restored)->not->toBeNull()
        ->and($restored->status)->toBe(StatusUser::DELETED)
        ->and($restored->password)->toBeNull()
        ->and($restored->email)->toContain('anonymized.local');
});

test('a restore is written to the activity log', function () {
    $member = anonymizedMember();

    Livewire::actingAs($this->admin)
        ->test('pages::admin.users.trashed-accounts')
        ->call('confirmRestore', $member->id)
        ->call('restore');

    expect(ActivityLog::query()->where('activity_log_action', ActivityActionEnum::RESTORE)->exists())->toBeTrue();
});

test('an admin without modify access cannot restore', function () {
    $member = anonymizedMember();
    $narrow = adminWithRoles(roleWithGates('Viewer', ['users.deleted-accounts' => GateAccessEnum::VIEW->value]));

    Livewire::actingAs($narrow)
        ->test('pages::admin.users.trashed-accounts')
        ->call('confirmRestore', $member->id)
        ->assertHasErrors();

    expect(User::query()->whereKey($member->id)->exists())->toBeFalse();
});

// |||||||||||||||||||||||||||||||||||||||||||||||||||||||||
// PURGE

test('purging removes the row for good', function () {
    $member = anonymizedMember();

    Livewire::actingAs($this->admin)
        ->test('pages::admin.users.trashed-accounts')
        ->call('confirmPurge', $member->id)
        ->call('purge')
        ->assertHasNoErrors();

    expect(app(AccountDeletionService::class)->trashedQuery()->whereKey($member->id)->exists())->toBeFalse()
        ->and(User::query()->withTrashed()->whereKey($member->id)->exists())->toBeFalse();
});

test('a purge is logged as a permanent deletion, not an ordinary one', function () {
    $member = anonymizedMember();

    Livewire::actingAs($this->admin)
        ->test('pages::admin.users.trashed-accounts')
        ->call('confirmPurge', $member->id)
        ->call('purge');

    // The sweep fulfilling somebody's own request and an administrator deciding to
    // stop keeping the history are different events and read differently in the log.
    expect(ActivityLog::query()->where('activity_log_action', ActivityActionEnum::FORCE_DELETE)->exists())->toBeTrue();
});

test('an account that is not deleted cannot be purged from here', function () {
    $member = userOfType(UserTypeEnum::USER, ['email_verified_at' => now()]);

    expect(app(AccountDeletionService::class)->purgeBlockedReason($member))->not->toBeNull();

    Livewire::actingAs($this->admin)
        ->test('pages::admin.users.trashed-accounts')
        ->call('confirmPurge', $member->id)
        ->call('purge')
        ->assertHasErrors();

    expect(User::query()->whereKey($member->id)->exists())->toBeTrue();
});

test('an admin without full access cannot purge', function () {
    $member = anonymizedMember();
    $narrow = adminWithRoles(roleWithGates('Editor', ['users.deleted-accounts' => GateAccessEnum::MODIFY->value]));

    Livewire::actingAs($narrow)
        ->test('pages::admin.users.trashed-accounts')
        ->call('confirmPurge', $member->id)
        ->assertHasErrors();

    expect(app(AccountDeletionService::class)->trashedQuery()->whereKey($member->id)->exists())->toBeTrue();
});

test('the screen is closed to an admin with no gate over it', function () {
    $narrow = adminWithRoles(roleWithGates('Nothing', ['dashboard' => GateAccessEnum::VIEW->value]));

    $this->actingAs($narrow)->get(route('admin.deleted-accounts'))->assertNotFound();
});
