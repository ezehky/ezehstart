<?php

use App\Enums\ActivityActionEnum;
use App\Enums\GateAccessEnum;
use App\Enums\UserTypeEnum;
use App\Models\Role;
use App\Services\GateService;
use App\Services\RoleService;
use Livewire\Livewire;

beforeEach(function () {
    $this->admin = userOfType(UserTypeEnum::ADMIN);
    $this->service = app(GateService::class);
});

/**
 * Narrow the protected role to exactly the gates given, bypassing the lockout guard so
 * a test can set up a deliberately restricted administrator.
 */
function setAdminRoleGates(array $gates): Role
{
    $role = app(RoleService::class)->protectedRole();
    $role->gates = $gates;
    $role->save();

    app(GateService::class)->flush();

    return $role;
}

// |||||||||||||||||||||||||||||||||||||||||||||||||||||||||||
// THE LADDER

test('a level covers everything below it and nothing above', function () {
    expect(GateAccessEnum::FULL->covers(GateAccessEnum::VIEW))->toBeTrue()
        ->and(GateAccessEnum::CREATE->covers(GateAccessEnum::MODIFY))->toBeTrue()
        ->and(GateAccessEnum::MODIFY->covers(GateAccessEnum::VIEW))->toBeTrue()
        ->and(GateAccessEnum::VIEW->covers(GateAccessEnum::MODIFY))->toBeFalse()
        ->and(GateAccessEnum::MODIFY->covers(GateAccessEnum::FULL))->toBeFalse();
});

test('none satisfies nothing, including a request for none', function () {
    expect(GateAccessEnum::NONE->covers(GateAccessEnum::VIEW))->toBeFalse()
        ->and(GateAccessEnum::NONE->covers(GateAccessEnum::NONE))->toBeFalse()
        ->and(GateAccessEnum::FULL->covers(GateAccessEnum::NONE))->toBeFalse();
});

// |||||||||||||||||||||||||||||||||||||||||||||||||||||||||||
// RESOLUTION

test('the protected role reaches every gate at full access', function () {
    foreach ($this->service->keys() as $key) {
        expect($this->service->accessFor($this->admin, $key))->toBe(GateAccessEnum::FULL);
    }
});

test('a child with no gate of its own inherits its parent', function () {
    setAdminRoleGates(['content' => GateAccessEnum::MODIFY->value]);

    expect($this->service->accessFor($this->admin, 'content.tags'))->toBe(GateAccessEnum::MODIFY)
        ->and($this->service->accessFor($this->admin, 'content.blogs'))->toBe(GateAccessEnum::MODIFY);
});

test('a child gate of its own beats the parent in both directions', function () {
    setAdminRoleGates([
        'content' => GateAccessEnum::VIEW->value,
        'content.tags' => GateAccessEnum::FULL->value,
        'users' => GateAccessEnum::FULL->value,
        'users.roles' => GateAccessEnum::VIEW->value,
    ]);

    expect($this->service->accessFor($this->admin, 'content.tags'))->toBe(GateAccessEnum::FULL)
        ->and($this->service->accessFor($this->admin, 'content.blogs'))->toBe(GateAccessEnum::VIEW)
        ->and($this->service->accessFor($this->admin, 'users.roles'))->toBe(GateAccessEnum::VIEW);
});

test('the dashboard and profile are never gated', function () {
    setAdminRoleGates([]);

    expect($this->service->accessFor($this->admin, 'dashboard'))->toBe(GateAccessEnum::FULL)
        ->and($this->service->accessFor($this->admin, 'profile'))->toBe(GateAccessEnum::FULL);
});

test('an override widens what the role grants', function () {
    setAdminRoleGates(['content' => GateAccessEnum::VIEW->value, 'users' => GateAccessEnum::FULL->value]);

    $this->admin->gates = ['content' => GateAccessEnum::FULL->value];
    $this->admin->save();

    $this->service->flush();

    expect($this->service->accessFor($this->admin, 'content'))->toBe(GateAccessEnum::FULL);
});

test('an override can deny something the role allows', function () {
    setAdminRoleGates($this->service->fullAccessMap());

    $this->admin->gates = ['content' => GateAccessEnum::NONE->value];
    $this->admin->save();

    $this->service->flush();

    expect($this->service->accessFor($this->admin, 'content'))->toBe(GateAccessEnum::NONE)
        // The children fall with the parent, because they were inheriting it.
        ->and($this->service->accessFor($this->admin, 'content.tags'))->toBe(GateAccessEnum::NONE)
        // Everything not named in the override is untouched.
        ->and($this->service->accessFor($this->admin, 'transactions'))->toBe(GateAccessEnum::FULL);
});

// |||||||||||||||||||||||||||||||||||||||||||||||||||||||||||
// ENFORCEMENT

test('a page the account has no gate over returns a 404', function () {
    setAdminRoleGates(['users' => GateAccessEnum::FULL->value]);

    $this->actingAs($this->admin)->get(route('admin.tags'))->assertNotFound();
    $this->actingAs($this->admin)->get(route('admin.transactions'))->assertNotFound();
});

test('a page the account does hold a gate over still opens', function () {
    setAdminRoleGates([
        'users' => GateAccessEnum::FULL->value,
        'content.tags' => GateAccessEnum::VIEW->value,
    ]);

    $this->actingAs($this->admin)->get(route('admin.tags'))->assertSuccessful();
});

test('the dashboard and profile stay reachable with no gates at all', function () {
    setAdminRoleGates([]);

    $this->actingAs($this->admin)->get(route('admin.dashboard'))->assertSuccessful();
    $this->actingAs($this->admin)->get(route('admin.profile'))->assertSuccessful();
});

test('an admin with no role reaches nothing but the exempt screens', function () {
    $stranded = adminWithoutRole();

    expect($this->service->accessFor($stranded, 'content'))->toBe(GateAccessEnum::NONE)
        ->and($this->service->accessFor($stranded, 'users'))->toBe(GateAccessEnum::NONE)
        ->and($this->service->accessFor($stranded, 'dashboard'))->toBe(GateAccessEnum::FULL);

    $this->actingAs($stranded)->get(route('admin.dashboard'))->assertSuccessful();
    $this->actingAs($stranded)->get(route('admin.tags'))->assertNotFound();
});

test('the member workspace is not gated by the admin map', function () {
    $member = userOfType(UserTypeEnum::USER);

    // The shared library screen sits behind content.image-library in the admin
    // workspace. Reached from /app it is a member screen and gates do not apply.
    $this->actingAs($member)->get(route('user.image-library'))->assertSuccessful();
});

test('the sidebar drops a branch the account cannot reach', function () {
    setAdminRoleGates(['users' => GateAccessEnum::FULL->value]);

    $this->actingAs($this->admin);

    $links = kPageNavigationLinks('admin');

    expect($links)->toHaveKey('users')
        ->and($links)->not->toHaveKey('content')
        ->and($links)->not->toHaveKey('transactions')
        // Never gated, so they survive an otherwise empty map.
        ->and($links)->toHaveKey('dashboard')
        ->and($links)->toHaveKey('profile');
});

test('the sidebar drops a single child without dropping its parent', function () {
    setAdminRoleGates([
        'users' => GateAccessEnum::FULL->value,
        'users.roles' => GateAccessEnum::NONE->value,
    ]);

    $this->actingAs($this->admin);

    $children = data_get(kPageNavigationLinks('admin'), 'users.children');

    expect($children)->toHaveKey('admins')
        ->and($children)->not->toHaveKey('roles');
});

// |||||||||||||||||||||||||||||||||||||||||||||||||||||||||||
// EDITING

test('an admin can change a role gate map from the roles screen', function () {
    // A second role, so the map under test is not the one the lockout guard is
    // watching. Narrowing the protected role is a different test.
    $role = roleWithGates('Media');

    Livewire::actingAs($this->admin)
        ->test('pages::admin.users.roles')
        ->call('openRoleGates', $role->id)
        ->set('gateRows.0.level', GateAccessEnum::VIEW->value)
        ->call('saveGates')
        ->assertHasNoErrors();

    // Through gatesArray(): the cast returns an ArrayObject, and toBeEmpty() on any
    // object is false whatever it holds, so asserting on the raw attribute would
    // pass even if nothing had been written.
    expect($role->fresh()->gatesArray())->not->toBeEmpty();
});

test('a role map cannot be narrowed until nobody would be left to administer', function () {
    $role = app(RoleService::class)->protectedRole();

    Livewire::actingAs($this->admin)
        ->test('pages::admin.users.roles')
        ->call('openRoleGates', $role->id)
        ->call('clearEveryGate')
        ->call('saveGates');

    // The map is untouched: the guard refused before anything was written.
    expect($role->fresh()->gateFor('users'))->toBe(GateAccessEnum::FULL);
});

test('an override cannot strip the last account that can administer gates', function () {
    Livewire::actingAs($this->admin)
        ->test('pages::admin.users.user-view', ['user' => $this->admin])
        ->call('openAdminGates', $this->admin->id)
        ->call('grantEveryGate')
        ->set('gateRows.0.level', GateAccessEnum::NONE->value)
        ->call('saveGates');

    // Row 0 is the first gateable parent. Whatever it is, the users gate is still
    // full, so this specific attempt is allowed — the guard only refuses a change
    // that takes the last administrator down.
    expect($this->service->accessFor($this->admin->fresh(), 'users'))->toBe(GateAccessEnum::FULL);
});

test('an override that leaves nobody able to administer is refused', function () {
    $reason = $this->service->adminGatesBlockedReason(
        $this->admin,
        [GateService::ADMINISTRATION => GateAccessEnum::NONE->value],
    );

    expect($reason)->toBeString();
});

test('clearing an override puts the account back on its role', function () {
    setAdminRoleGates($this->service->fullAccessMap());

    $this->admin->gates = ['content' => GateAccessEnum::VIEW->value];
    $this->admin->save();

    $this->service->flush();

    Livewire::actingAs($this->admin)
        ->test('pages::admin.users.user-view', ['user' => $this->admin])
        ->call('openAdminGates', $this->admin->id)
        ->call('clearEveryGate')
        ->call('saveGates')
        ->assertHasNoErrors();

    expect($this->admin->fresh()->gates)->toBeNull()
        ->and($this->service->accessFor($this->admin->fresh(), 'content'))->toBe(GateAccessEnum::FULL);
});

test('a gate change is written to the activity log', function () {
    $role = roleWithGates('Media');

    Livewire::actingAs($this->admin)
        ->test('pages::admin.users.roles')
        ->call('openRoleGates', $role->id)
        ->call('grantEveryGate')
        ->call('saveGates');

    expect($this->admin->activityLogs()->where('action', ActivityActionEnum::ROLE_GATES_UPDATE)->exists())
        ->toBeTrue();
});

// |||||||||||||||||||||||||||||||||||||||||||||||||||||||||||
// STORAGE SHAPE

test('a role answers for a dotted child key', function () {
    $role = setAdminRoleGates([
        'users' => GateAccessEnum::VIEW->value,
        'users.roles' => GateAccessEnum::FULL->value,
    ]);

    // The map is flat with dotted keys. Read through data_get() this returns NONE,
    // because data_get walks 'users' then 'roles' and finds nothing.
    expect($role->gateFor('users.roles'))->toBe(GateAccessEnum::FULL)
        ->and($role->gateFor('users.admins'))->toBe(GateAccessEnum::VIEW)
        ->and($role->gateFor('content'))->toBe(GateAccessEnum::NONE);
});

test('a gate map can be written to in place', function () {
    $role = setAdminRoleGates(['users' => GateAccessEnum::VIEW->value]);

    // The whole point of the AsArrayObject cast: a plain 'array' cast returns a
    // copy, so this assignment would be thrown away and the model stay clean.
    $role->gates['users'] = GateAccessEnum::FULL->value;

    expect($role->isDirty('gates'))->toBeTrue();

    $role->save();

    expect($role->fresh()->gateFor('users'))->toBe(GateAccessEnum::FULL);
});

test('a role map drops denials but an override keeps them', function () {
    $submitted = ['content' => GateAccessEnum::NONE->value, 'users' => GateAccessEnum::FULL->value];

    expect($this->service->normalize($submitted))->toBe(['users' => GateAccessEnum::FULL->value])
        ->and($this->service->normalize($submitted, keepDenials: true))->toBe($submitted);
});

test('an unknown or invalid gate key never reaches storage', function () {
    $normalized = $this->service->normalize([
        'users' => GateAccessEnum::FULL->value,
        'not-a-screen' => GateAccessEnum::FULL->value,
        'content' => 'wide-open',
    ]);

    expect($normalized)->toBe(['users' => GateAccessEnum::FULL->value]);
});
