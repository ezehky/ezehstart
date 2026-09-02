<?php

use App\Enums\ActivityActionEnum;
use App\Enums\StatusDefault;
use App\Enums\UserRoleEnum;
use App\Services\UserRoleService;
use Livewire\Livewire;

test('granting a role gives the account its workspace', function () {
    $account = userWithoutRole();

    expect(app(UserRoleService::class)->grant($account, UserRoleEnum::USER))->toBeTrue();

    expect($account->fresh()->isUser())->toBeTrue();
});

test('a revoked role is deactivated, not deleted', function () {
    $account = userWithRole(UserRoleEnum::USER);
    app(UserRoleService::class)->grant($account, UserRoleEnum::ADMIN);

    expect(app(UserRoleService::class)->revoke($account, UserRoleEnum::USER))->toBeTrue();

    // The row survives, so the history of who held what is not lost and a
    // re-grant reuses the same assignment.
    $this->assertDatabaseHas('user_roles', [
        'user_id' => $account->id,
        'status' => StatusDefault::INACTIVE->value,
    ]);

    expect($account->fresh()->isUser())->toBeFalse();
});

test('the only role on an account cannot be revoked', function () {
    $account = userWithRole(UserRoleEnum::USER);

    $reason = app(UserRoleService::class)->revokeBlockedReason($account, UserRoleEnum::USER);

    expect($reason)->toContain('only role')
        ->and(app(UserRoleService::class)->revoke($account, UserRoleEnum::USER))->toBeFalse();
});

test('the last admin cannot lose the admin role', function () {
    $service = app(UserRoleService::class);

    // Both carry a second role, so the "only role" guard is never what fires.
    $adminA = userWithRole(UserRoleEnum::ADMIN, ['email' => 'first@example.test']);
    $adminB = userWithRole(UserRoleEnum::ADMIN, ['email' => 'second@example.test']);
    $service->grant($adminA, UserRoleEnum::USER);
    $service->grant($adminB, UserRoleEnum::USER);

    $this->actingAs($adminA);

    // Two admins exist, so letting one go still leaves the workspace reachable.
    expect($service->revokeBlockedReason($adminB, UserRoleEnum::ADMIN))->toBeNull();

    $service->revoke($adminB, UserRoleEnum::ADMIN);

    // $adminA is now the only admin left, and it is not the account being checked,
    // so it is the last-admin guard that speaks rather than the self-revoke one.
    $this->actingAs($adminB);

    expect($service->revokeBlockedReason($adminA, UserRoleEnum::ADMIN))
        ->toContain('last admin account');
});

test('an admin cannot remove their own admin role', function () {
    $admin = userWithRole(UserRoleEnum::ADMIN);
    app(UserRoleService::class)->grant($admin, UserRoleEnum::USER);

    $this->actingAs($admin);

    expect(app(UserRoleService::class)->revokeBlockedReason($admin, UserRoleEnum::ADMIN))
        ->toContain('your own admin role');
});

test('granting a role is written to the audit trail', function () {
    $admin = userWithRole(UserRoleEnum::ADMIN);
    $account = userWithoutRole();

    $this->actingAs($admin);

    app(UserRoleService::class)->grant($account, UserRoleEnum::USER);

    $this->assertDatabaseHas('activity_logs', [
        'user_id' => $admin->id,
        'action' => ActivityActionEnum::USER_ROLE_GRANT->value,
        'loggable_id' => $account->id,
    ]);
});

test('the role manager modal offers grant on a role the account lacks', function () {
    $admin = userWithRole(UserRoleEnum::ADMIN);
    $account = userWithoutRole();

    Livewire::actingAs($admin)
        ->test('pages::admin.users.unassigned')
        ->call('openRoleManager', $account->id)
        ->call('grantRole', UserRoleEnum::USER->value);

    expect($account->fresh()->isUser())->toBeTrue();
});

test('roles are created on demand the first time they are used', function () {
    $this->assertDatabaseCount('roles', 0);

    app(UserRoleService::class)->role(UserRoleEnum::ADMIN);

    $this->assertDatabaseHas('roles', ['name' => UserRoleEnum::ADMIN->value]);
});
