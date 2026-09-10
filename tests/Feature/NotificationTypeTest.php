<?php

use App\Enums\NotificationTypeEnum;
use App\Enums\StatusDefault;
use App\Enums\UserRoleEnum;
use App\Models\NotificationType;
use App\Services\UserService;
use Livewire\Livewire;

beforeEach(function () {
    $this->admin = userWithRole(UserRoleEnum::ADMIN, ['email_verified_at' => now()]);
});

test('the seeder lays down one row per enum case', function () {
    $types = seededNotificationTypes();

    expect($types)->toHaveCount(\count(NotificationTypeEnum::cases()))
        ->and($types->first()->knownType())->toBeInstanceOf(NotificationTypeEnum::class);
});

test('re-seeding refreshes the wording without stacking duplicates', function () {
    seededNotificationTypes();

    $type = NotificationType::query()->first();
    $type->update(['title' => 'Renamed by hand', 'flow_order' => 99, 'status' => StatusDefault::INACTIVE]);

    seededNotificationTypes();

    $fresh = $type->fresh();

    expect(NotificationType::query()->count())->toBe(\count(NotificationTypeEnum::cases()))
        // Wording comes back from the enum...
        ->and($fresh->title)->not->toBe('Renamed by hand')
        // ...but an administrator's ordering and their decision to silence it stand.
        ->and($fresh->flow_order)->toBe(99)
        ->and($fresh->status)->toBe(StatusDefault::INACTIVE);
});

test('an administrator can add a type no code knows about', function () {
    Livewire::actingAs($this->admin)
        ->test('pages::admin.configs.notification-types')
        ->call('create')
        ->set('title', 'Product updates')
        ->set('notification_type', 'product-updates')
        ->call('save')
        ->assertHasNoErrors();

    $type = NotificationType::query()->where('notification_type', 'product-updates')->first();

    expect($type)->not->toBeNull()
        // No enum case matches, and that has to be fine rather than an exception.
        ->and($type->knownType())->toBeNull();
});

test('a custom type reaches every member on their next visit', function () {
    seededNotificationTypes();

    $member = userWithRole(UserRoleEnum::USER, ['email_verified_at' => now()]);

    app(UserService::class, ['user' => $member])->runNotificationPreferencesUpdate();

    $before = $member->notificationPreferences()->count();

    NotificationType::query()->create([
        'notification_type' => 'product-updates',
        'title' => 'Product updates',
    ]);

    $this->actingAs($member)->get(route('user.dashboard'))->assertSuccessful();

    expect($member->fresh()->notificationPreferences()->count())->toBe($before + 1);
});

test('two types cannot share a key', function () {
    seededNotificationTypes();

    Livewire::actingAs($this->admin)
        ->test('pages::admin.configs.notification-types')
        ->call('create')
        ->set('title', 'Security')
        ->set('notification_type', NotificationTypeEnum::SECURITY->value)
        ->call('save')
        ->assertHasErrors('notification_type');
});

test('deleting a type takes its preference rows with it', function () {
    seededNotificationTypes();

    $member = userWithRole(UserRoleEnum::USER, ['email_verified_at' => now()]);
    app(UserService::class, ['user' => $member])->runNotificationPreferencesUpdate();

    $type = NotificationType::query()->first();

    Livewire::actingAs($this->admin)
        ->test('pages::admin.configs.notification-types')
        ->call('confirmDelete', $type->id)
        ->call('delete')
        ->assertHasNoErrors();

    expect($member->notificationPreferences()->count())
        ->toBe(\count(NotificationTypeEnum::cases()) - 1);
});

test('an inactive type is not offered on the member settings page', function () {
    seededNotificationTypes();

    $member = userWithRole(UserRoleEnum::USER, ['email_verified_at' => now()]);

    $silenced = NotificationType::query()->first();
    $silenced->update(['status' => StatusDefault::INACTIVE]);

    Livewire::actingAs($member)
        ->test('pages::user.account.account-settings')
        ->assertDontSee($silenced->title);
});

test('a member can save their preferences', function () {
    seededNotificationTypes();

    $member = userWithRole(UserRoleEnum::USER, ['email_verified_at' => now()]);

    $component = Livewire::actingAs($member)->test('pages::user.account.account-settings');

    $typeId = NotificationType::query()->value('id');

    $component->set("notifications.{$typeId}", false)
        ->call('save')
        ->assertHasNoErrors();

    // value() applies the model's cast, so this comes back as the enum case
    // rather than the raw column.
    expect($member->notificationPreferences()->where('notification_type_id', $typeId)->value('status'))
        ->toBe(StatusDefault::INACTIVE);
});
