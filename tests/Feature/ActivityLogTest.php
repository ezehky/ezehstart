<?php

use App\Enums\ActivityActionEnum;
use App\Enums\UserRoleEnum;
use App\Models\User;
use App\Services\ActivityLogService;

test('nothing is logged for a guest', function () {
    app(ActivityLogService::class)->logActivity(ActivityActionEnum::LOGIN);

    $this->assertDatabaseCount('activity_logs', 0);
});

test('an action with no description and no default wording is not logged', function () {
    $this->actingAs(userWithRole(UserRoleEnum::ADMIN));

    // VIEW has neither a startDescription nor a defaultDescription, so there is
    // nothing to record and the entry is skipped rather than written blank.
    app(ActivityLogService::class)->logActivity(ActivityActionEnum::VIEW);

    $this->assertDatabaseCount('activity_logs', 0);
});

test('a description is prefixed by the action wording', function () {
    $admin = userWithRole(UserRoleEnum::ADMIN);
    $this->actingAs($admin);

    app(ActivityLogService::class)->logActivity(ActivityActionEnum::USER_CREATE, 'admin: Ada');

    $this->assertDatabaseHas('activity_logs', [
        'user_id' => $admin->id,
        'description' => 'Created new admin: Ada',
    ]);
});

test('prefixDescription false leaves the sentence alone', function () {
    $admin = userWithRole(UserRoleEnum::ADMIN);
    $this->actingAs($admin);

    app(ActivityLogService::class)->logActivity(
        ActivityActionEnum::USER_CREATE,
        'A sentence written in full.',
        prefixDescription: false,
    );

    $this->assertDatabaseHas('activity_logs', ['description' => 'A sentence written in full.']);
});

test('affectedColumns records the before and after of a change', function () {
    $user = User::factory()->create(['name' => 'Ada Lovelace']);

    $user->name = 'Ada King';

    $affected = app(ActivityLogService::class)->affectedColumns($user);

    expect($affected['original']['name'])->toBe('Ada Lovelace')
        ->and($affected['changes']['name'])->toBe('Ada King');
});

test('affectedColumns never records a password', function () {
    $user = User::factory()->create();

    $user->password = 'a-new-secret';
    $user->name = 'Ada King';

    $affected = app(ActivityLogService::class)->affectedColumns($user);

    expect($affected['changes'])->not->toHaveKey('password')
        ->and($affected['original'])->not->toHaveKey('password');
});

test('affectedColumns returns null when nothing changed', function () {
    $user = User::factory()->create();

    expect(app(ActivityLogService::class)->affectedColumns($user))->toBeNull();
});

test('a logged entry carries the model it happened to', function () {
    $admin = userWithRole(UserRoleEnum::ADMIN);
    $subject = userWithoutRole();
    $this->actingAs($admin);

    app(ActivityLogService::class)->logActivity(
        ActivityActionEnum::USER_UPDATE,
        'user: Ada',
        model: $subject,
    );

    $this->assertDatabaseHas('activity_logs', [
        'loggable_id' => $subject->id,
        'loggable_type' => User::class,
    ]);
});
