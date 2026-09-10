<?php

use App\Enums\ActivityActionEnum;
use App\Enums\PolicyTypeEnum;
use App\Enums\StatusPolicy;
use App\Enums\UserTypeEnum;
use App\Models\ActivityLog;
use App\Models\Policy;
use App\Services\PolicyContentService;
use Livewire\Livewire;

beforeEach(function () {
    $this->admin = userOfType(UserTypeEnum::ADMIN);
});

test('the screen is closed to a member', function () {
    // The admin workspace answers 404 rather than 403 to an account that has no
    // business there, which is the behaviour the rest of the suite asserts too.
    $this->actingAs(userOfType(UserTypeEnum::USER))
        ->get(route('admin.config.policies'))
        ->assertNotFound();
});

// ==================== Drafting

test('a new policy is saved as a draft and stays off the public page', function () {
    Livewire::actingAs($this->admin)
        ->test('pages::admin.configs.policies')
        ->call('create', PolicyTypeEnum::TERMS->value)
        ->set('title', 'Terms of Service')
        ->set('content', '## 1. Overview')
        ->call('save')
        ->assertHasNoErrors();

    $policy = Policy::query()->first();

    expect($policy->status)->toBe(StatusPolicy::DRAFT)
        ->and($policy->version)->toBe('1.0');

    $this->get(route('terms'))->assertDontSee('1. Overview');
});

/**
 * A new version starts from the text in force rather than a blank page — writing
 * v2 is nearly always an edit of v1, and retyping it is how clauses get lost.
 */
test('drafting a new version starts from the one in force', function () {
    publishedPolicy(attributes: ['content' => '## Existing clause', 'intro' => 'The intro in force.']);

    Livewire::actingAs($this->admin)
        ->test('pages::admin.configs.policies')
        ->call('create', PolicyTypeEnum::TERMS->value)
        ->assertSet('version', '2.0')
        ->assertSet('content', '## Existing clause')
        ->assertSet('intro', 'The intro in force.');
});

test('two versions of the same policy cannot share a number', function () {
    publishedPolicy(attributes: ['version' => '1.0']);

    Livewire::actingAs($this->admin)
        ->test('pages::admin.configs.policies')
        ->call('create', PolicyTypeEnum::TERMS->value)
        ->set('version', '1.0')
        ->set('title', 'Terms of Service')
        ->set('content', '## Something')
        ->call('save')
        ->assertHasErrors('version');
});

/**
 * Two different policies each having a v1.0 is normal, and the unique index is
 * scoped to the type precisely so that stays allowed.
 */
test('different policies may share a version number', function () {
    publishedPolicy(PolicyTypeEnum::TERMS, ['version' => '1.0']);

    Livewire::actingAs($this->admin)
        ->test('pages::admin.configs.policies')
        ->call('create', PolicyTypeEnum::PRIVACY->value)
        ->set('version', '1.0')
        ->set('title', 'Privacy Policy')
        ->set('content', '## What we collect')
        ->call('save')
        ->assertHasNoErrors();

    expect(Policy::query()->where('policy_type', PolicyTypeEnum::PRIVACY)->count())->toBe(1);
});

/**
 * Published text is what people consented to. Refused in the method rather than
 * only hidden in the menu — a missing menu item is not a guard.
 */
test('a published version cannot be edited', function () {
    $policy = publishedPolicy();

    Livewire::actingAs($this->admin)
        ->test('pages::admin.configs.policies')
        ->call('edit', $policy->id)
        ->assertHasErrors();

    expect($policy->fresh()->status)->toBe(StatusPolicy::PUBLISHED);
});

// ==================== Publishing

test('publishing puts a draft in force and archives what it replaces', function () {
    $superseded = publishedPolicy(attributes: ['version' => '1.0', 'content' => '## Old']);

    $draft = Policy::query()->create([
        'policy_type' => PolicyTypeEnum::TERMS,
        'version' => '2.0',
        'title' => 'Terms of Service',
        'content' => '## New',
        'status' => StatusPolicy::DRAFT,
    ]);

    Livewire::actingAs($this->admin)
        ->test('pages::admin.configs.policies')
        ->call('confirmPublish', $draft->id)
        ->call('publish')
        ->assertHasNoErrors();

    expect($draft->fresh()->status)->toBe(StatusPolicy::PUBLISHED)
        ->and($draft->fresh()->effective_at)->not->toBeNull()
        // Archived rather than deleted: consent records still point at it.
        ->and($superseded->fresh()->status)->toBe(StatusPolicy::ARCHIVED)
        ->and(app(PolicyContentService::class)->getCurrent(PolicyTypeEnum::TERMS)->id)->toBe($draft->id);

    $this->get(route('terms'))->assertSee('New')->assertDontSee('Old');
});

test('an already published version cannot be published again', function () {
    $policy = publishedPolicy();

    Livewire::actingAs($this->admin)
        ->test('pages::admin.configs.policies')
        ->call('confirmPublish', $policy->id)
        ->call('publish')
        ->assertHasErrors();
});

test('publishing is recorded in the activity log as its own action', function () {
    $draft = Policy::query()->create([
        'policy_type' => PolicyTypeEnum::TERMS,
        'version' => '1.0',
        'title' => 'Terms of Service',
        'content' => '## Overview',
        'status' => StatusPolicy::DRAFT,
    ]);

    Livewire::actingAs($this->admin)
        ->test('pages::admin.configs.policies')
        ->call('confirmPublish', $draft->id)
        ->call('publish');

    expect(ActivityLog::query()->where('action', ActivityActionEnum::POLICY_PUBLISH)->exists())->toBeTrue();
});

test('saving a draft is recorded against the policy', function () {
    Livewire::actingAs($this->admin)
        ->test('pages::admin.configs.policies')
        ->call('create', PolicyTypeEnum::TERMS->value)
        ->set('title', 'Terms of Service')
        ->set('content', '## 1. Overview')
        ->call('save');

    expect(ActivityLog::query()->where('action', ActivityActionEnum::POLICY_CREATE)->exists())->toBeTrue();
});

// ==================== Version numbering

test('the next version is offered as a whole number above the last', function () {
    $service = app(PolicyContentService::class);

    expect($service->getNextVersion(PolicyTypeEnum::TERMS))->toBe('1.0');

    publishedPolicy(attributes: ['version' => '1.0']);

    expect($service->getNextVersion(PolicyTypeEnum::TERMS))->toBe('2.0');
});
