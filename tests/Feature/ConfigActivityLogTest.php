<?php

use App\Enums\ActivityActionEnum;
use App\Enums\UserTypeEnum;
use App\Models\ActivityLog;
use App\Services\SiteConfigurationService;
use Livewire\Livewire;

beforeEach(function () {
    $this->admin = userOfType(UserTypeEnum::ADMIN);

    app(SiteConfigurationService::class)->update(initials: true);
});

/**
 * The most recent configuration entry, or null when nothing was written.
 */
function lastConfigLog(): ?ActivityLog
{
    return ActivityLog::query()
        ->where('activity_log_action', ActivityActionEnum::CONFIG_UPDATE)
        ->latest('id')
        ->first();
}

test('turning the second factor off is written to the activity log', function () {
    Livewire::actingAs($this->admin)
        ->test('pages::admin.configs.security')
        ->set('config.security.two-factor', true)
        ->call('save')
        ->assertHasNoErrors();

    $log = lastConfigLog();

    // The line somebody goes looking for after an account is taken over. It used to
    // not exist: the five JSON-backed screens were the only admin writes logging
    // nothing at all.
    expect($log)->not->toBeNull()
        ->and($log->user_id)->toBe($this->admin->id)
        ->and($log->description)->toContain('security');
});

test('the log entry carries the switch that moved and what it was before', function () {
    Livewire::actingAs($this->admin)
        ->test('pages::admin.configs.security')
        ->set('config.security.password-history', false)
        ->call('save')
        ->assertHasNoErrors();

    $log = lastConfigLog();

    expect($log->original->toArray())->toHaveKey('security.password-history')
        ->and($log->original['security.password-history'])->toBeTrue()
        ->and($log->changes['security.password-history'])->toBeFalse();
});

test('only the keys that actually changed are recorded', function () {
    Livewire::actingAs($this->admin)
        ->test('pages::admin.configs.security')
        ->set('config.security.login-max-attempts', 9)
        ->call('save')
        ->assertHasNoErrors();

    // A whole-tree dump on every save would bury the one value somebody touched,
    // which is the only reason the entry is read.
    expect(array_keys($log = lastConfigLog()->changes->toArray()))
        ->toBe(['security.login-max-attempts'])
        ->and($log['security.login-max-attempts'])->toBe(9);
});

test('each configuration screen names itself in the entry', function () {
    Livewire::actingAs($this->admin)
        ->test('pages::admin.configs.site-config')
        ->set('config.name', 'Ezeh Start')
        ->call('save')
        ->assertHasNoErrors();

    expect(lastConfigLog()->description)->toContain('site info');

    Livewire::actingAs($this->admin)
        ->test('pages::admin.configs.preferences')
        ->set('config.user.mobile-floating-menu', true)
        ->call('save')
        ->assertHasNoErrors();

    expect(lastConfigLog()->description)->toContain('preferences');
});

test('a save that changes nothing is not logged', function () {
    Livewire::actingAs($this->admin)
        ->test('pages::admin.configs.security')
        ->call('save');

    // respondPrimary() stops a no-op save before the log block, the same as it does
    // on every other admin screen.
    expect(lastConfigLog())->toBeNull();
});
