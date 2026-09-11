<?php

use App\Enums\ActivityActionEnum;
use App\Enums\GateAccessEnum;
use App\Enums\StatusDefault;
use App\Enums\UserTypeEnum;
use App\Models\ActivityLog;
use App\Models\Tag;
use Livewire\Livewire;

/**
 * The row switch — WithStatusToggle plus <x-util.status-toggle> — exercised through
 * the tags listing, which is the first screen to carry it.
 */
beforeEach(function () {
    $this->admin = userOfType(UserTypeEnum::ADMIN);
    $this->tag = Tag::query()->create(['name' => 'winter', 'slug' => 'winter', 'status' => StatusDefault::ACTIVE]);
});

test('a switch flips the status without touching anything else', function () {
    Livewire::actingAs($this->admin)
        ->test('pages::admin.content.tags')
        ->call('toggleStatus', $this->tag->id);

    expect($this->tag->fresh()->status)->toBe(StatusDefault::INACTIVE)
        ->and($this->tag->fresh()->name)->toBe('winter');
});

test('a second click brings it back', function () {
    $component = Livewire::actingAs($this->admin)->test('pages::admin.content.tags');

    $component->call('toggleStatus', $this->tag->id);
    $component->call('toggleStatus', $this->tag->id);

    expect($this->tag->fresh()->status)->toBe(StatusDefault::ACTIVE);
});

test('the change is written to the audit trail with both sides of it', function () {
    Livewire::actingAs($this->admin)
        ->test('pages::admin.content.tags')
        ->call('toggleStatus', $this->tag->id);

    $log = ActivityLog::query()->latest('id')->first();

    expect($log->activity_log_action)->toBe(ActivityActionEnum::TAG_UPDATE)
        // ActivityLogService excludes `status` from the before-and-after payload, so
        // the description is the only place the direction can be read.
        ->and($log->description)->toContain('tag: winter — inactive');
});

test('a key that no longer exists is refused rather than fataling', function () {
    Livewire::actingAs($this->admin)
        ->test('pages::admin.content.tags')
        ->call('toggleStatus', 99999)
        ->assertHasErrors();

    expect(Tag::query()->count())->toBe(1);
});

test('an account below modify cannot flip a status', function () {
    $restricted = adminWithRoles(roleWithGates('Reader', ['content.tags' => GateAccessEnum::VIEW->value]));

    Livewire::actingAs($restricted)
        ->test('pages::admin.content.tags')
        ->call('toggleStatus', $this->tag->id)
        ->assertHasErrors();

    expect($this->tag->fresh()->status)->toBe(StatusDefault::ACTIVE);
});

// |||||||||||||||||||||||||||||||||||||||||||||||||||||||||||
// THE COMPONENT

// The edit modal on the same page carries a switch of its own, so the row control is
// found by the call behind it rather than by the markup Flux gives every switch.
test('the switch renders in place of the badge for an account that may use it', function () {
    Livewire::actingAs($this->admin)
        ->test('pages::admin.content.tags')
        ->assertSee('wire:click="toggleStatus(\''.$this->tag->id.'\')"', escape: false);
});

test('an account that may only look at the row gets the badge instead', function () {
    $restricted = adminWithRoles(roleWithGates('Reader', ['content.tags' => GateAccessEnum::VIEW->value]));

    Livewire::actingAs($restricted)
        ->test('pages::admin.content.tags')
        ->assertDontSee('wire:click="toggleStatus', escape: false)
        ->assertSee('data-flux-badge', escape: false);
});
