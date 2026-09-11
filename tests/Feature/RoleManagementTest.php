<?php

use App\Enums\ActivityActionEnum;
use App\Enums\StatusDefault;
use App\Enums\UserTypeEnum;
use App\Models\Role;
use App\Models\User;
use App\Services\GateService;
use App\Services\RoleService;
use Database\Seeders\RoleSeeder;
use Livewire\Livewire;

test('a member carries no role at all', function () {
    $member = userOfType(UserTypeEnum::USER);

    expect($member->user_type->carriesRole())->toBeFalse()
        ->and($member->role_id)->toBeNull()
        ->and($member->hasLiveRole())->toBeFalse();
});

test('registering makes a member, never an admin', function () {
    $user = User::factory()->create();

    expect($user->user_type)->toBe(UserTypeEnum::USER)
        ->and($user->role_id)->toBeNull();
});

test('assigning a role puts the admin on it', function () {
    // Somebody has to keep full access to Users, or the lockout guard refuses the
    // move rather than the move being what is under test.
    userOfType(UserTypeEnum::ADMIN, ['email' => 'keeper@example.test']);

    $admin = adminWithoutRole();
    $role = roleWithGates('Media');

    expect(app(RoleService::class)->assign($admin, $role))->toBeTrue()
        ->and($admin->fresh()->role_id)->toBe($role->id);
});

test('a role cannot be given to a member', function () {
    $member = userOfType(UserTypeEnum::USER);
    $role = roleWithGates('Media');

    expect(app(RoleService::class)->assignBlockedReason($member, $role))
        ->toContain('Only admin accounts carry a role');
});

test('a switched-off role cannot be assigned', function () {
    $admin = adminWithoutRole();
    $role = roleWithGates('Media');
    $role->status = StatusDefault::INACTIVE;
    $role->save();

    expect(app(RoleService::class)->assignBlockedReason($admin, $role))
        ->toContain('switched off');
});

test('a switched-off role grants nothing to the admins already on it', function () {
    // A second admin keeps the lockout guard from being what fires.
    userOfType(UserTypeEnum::ADMIN, ['email' => 'keeper@example.test']);

    $role = roleWithGates('Media', ['content' => 'full']);
    $admin = userOfType(UserTypeEnum::ADMIN, ['email' => 'media@example.test'], $role);

    expect(kGate('content', user: $admin))->toBeTrue();

    app(RoleService::class)->update($role, 'Media', null, active: false);

    expect($admin->fresh()->hasLiveRole())->toBeFalse()
        ->and(kGate('content', user: $admin->fresh()))->toBeFalse();
});

test('changing type to member clears the role', function () {
    // Another admin has to exist, or the last-admin guard fires first.
    userOfType(UserTypeEnum::ADMIN, ['email' => 'keeper@example.test']);

    $admin = userOfType(UserTypeEnum::ADMIN, ['email' => 'leaving@example.test']);

    expect(app(RoleService::class)->changeType($admin, UserTypeEnum::USER))->toBeTrue();

    $admin->refresh();

    expect($admin->user_type)->toBe(UserTypeEnum::USER)
        ->and($admin->role_id)->toBeNull();
});

test('the last admin cannot be moved out of the admin workspace', function () {
    $admin = userOfType(UserTypeEnum::ADMIN);
    $other = userOfType(UserTypeEnum::USER);

    $this->actingAs($other);

    expect(app(RoleService::class)->typeChangeBlockedReason($admin, UserTypeEnum::USER))
        ->toContain('last admin account');
});

test('an admin cannot take away their own admin access', function () {
    userOfType(UserTypeEnum::ADMIN, ['email' => 'keeper@example.test']);
    $admin = userOfType(UserTypeEnum::ADMIN, ['email' => 'self@example.test']);

    $this->actingAs($admin);

    expect(app(RoleService::class)->typeChangeBlockedReason($admin, UserTypeEnum::USER))
        ->toContain('your own admin access');
});

test('assigning a role is written to the audit trail', function () {
    $admin = userOfType(UserTypeEnum::ADMIN);
    $account = adminWithoutRole();
    $role = roleWithGates('Media');

    $this->actingAs($admin);

    app(RoleService::class)->assign($account, $role);

    $this->assertDatabaseHas('activity_logs', [
        'user_id' => $admin->id,
        'activity_log_action' => ActivityActionEnum::USER_ROLE_ASSIGN->value,
        'loggable_id' => $account->id,
    ]);
});

test('the access modal moves an admin onto a role', function () {
    $admin = userOfType(UserTypeEnum::ADMIN);
    $account = adminWithoutRole();
    $role = roleWithGates('Media');

    Livewire::actingAs($admin)
        ->test('pages::admin.users.admins')
        ->call('openRoleManager', $account->id)
        ->set('accountRole', (string) $role->id)
        ->call('saveRoleAccess');

    expect($account->fresh()->role_id)->toBe($role->id);
});

test('the access modal moves an account between workspaces', function () {
    $admin = userOfType(UserTypeEnum::ADMIN);
    $member = userOfType(UserTypeEnum::USER);
    $role = roleWithGates('Media');

    Livewire::actingAs($admin)
        ->test('pages::admin.users.members')
        ->call('openRoleManager', $member->id)
        ->set('accountType', UserTypeEnum::ADMIN->value)
        ->set('accountRole', (string) $role->id)
        ->call('saveRoleAccess');

    $member->refresh();

    expect($member->user_type)->toBe(UserTypeEnum::ADMIN)
        ->and($member->role_id)->toBe($role->id);
});

// |||||||||||||||||||||||||||||||||||||||||||||||||||||||||||
// ROLE RECORDS

test('the protected role is created with every gate', function () {
    $role = app(RoleService::class)->protectedRole();

    expect($role->is_protected)->toBeTrue()
        ->and($role->slug)->toBe(RoleService::PROTECTED_SLUG)
        ->and($role->gatesArray())->toBe(app(GateService::class)->fullAccessMap());
});

test('resolving the protected role again does not restore gates somebody removed', function () {
    $service = app(RoleService::class);
    $role = $service->protectedRole();

    $role->gates = ['users' => 'full'];
    $role->save();

    expect($service->protectedRole()->gatesArray())->toBe(['users' => 'full']);
});

test('the protected role cannot be deleted or switched off', function () {
    $service = app(RoleService::class);
    $role = $service->protectedRole();

    expect($service->deleteBlockedReason($role))->toContain('protected role')
        ->and($service->delete($role))->toBeFalse()
        ->and($service->updateBlockedReason($role, active: false))->toContain('protected role');
});

test('a role with accounts on it cannot be deleted', function () {
    $role = roleWithGates('Media');
    userOfType(UserTypeEnum::ADMIN, [], $role);

    expect(app(RoleService::class)->deleteBlockedReason($role))
        ->toContain('Move it to another role first');
});

test('an empty role is deleted', function () {
    $role = roleWithGates('Media');

    expect(app(RoleService::class)->delete($role))->toBeTrue();

    $this->assertDatabaseMissing('roles', ['id' => $role->id]);
});

test('two roles named the same get distinct slugs', function () {
    $service = app(RoleService::class);

    $first = $service->create('Media');
    $second = $service->create('Media');

    expect($first->slug)->toBe('media')
        ->and($second->slug)->toBe('media-2');
});

test('a new role starts with no access', function () {
    $role = app(RoleService::class)->create('Media');

    expect($role->gatesArray())->toBe([]);
});

test('the roles screen creates a role', function () {
    $admin = userOfType(UserTypeEnum::ADMIN);

    Livewire::actingAs($admin)
        ->test('pages::admin.users.roles')
        ->call('create')
        ->set('name', 'Support')
        ->set('description', 'Answers to members.')
        ->call('save');

    $this->assertDatabaseHas('roles', ['slug' => 'support', 'name' => 'Support']);
});

test('the roles screen refuses to delete a role somebody is on', function () {
    $admin = userOfType(UserTypeEnum::ADMIN);
    $role = roleWithGates('Media');
    userOfType(UserTypeEnum::ADMIN, ['email' => 'media@example.test'], $role);

    Livewire::actingAs($admin)
        ->test('pages::admin.users.roles')
        ->call('confirmDelete', $role->id)
        ->call('delete');

    $this->assertDatabaseHas('roles', ['id' => $role->id]);
});

test('role rows are seeded once and not reset on a second run', function () {
    (new RoleSeeder)->run();

    $media = Role::query()->where('slug', 'media')->firstOrFail();
    $media->gates = ['content' => 'view'];
    $media->save();

    (new RoleSeeder)->run();

    expect($media->fresh()->gatesArray())->toBe(['content' => 'view'])
        ->and(Role::query()->where('slug', 'media')->count())->toBe(1);
});
