<?php

use App\Enums\PolicyTypeEnum;
use App\Enums\StatusDefault;
use App\Enums\StatusPolicy;
use App\Enums\StatusYes;
use App\Models\Faq;
use App\Models\Policy;
use App\Services\PolicyContentService;

// ==================== The public pages

test('every policy type has a public page', function (PolicyTypeEnum $type) {
    publishedPolicy($type);

    $this->get($type->url())
        ->assertSuccessful()
        ->assertSee($type->defaultTitle());
})->with(PolicyTypeEnum::cases());

test('the page renders the version in force, headings and all', function () {
    publishedPolicy();

    $this->get(route('terms'))
        ->assertSuccessful()
        ->assertSee('First heading')
        ->assertSee('The first body.')
        ->assertSee('Version 1.0');
});

/**
 * A legal page with nothing published is a configuration problem rather than a
 * missing page, and a 404 would send somebody looking for a broken link instead
 * of a policy that was never written.
 */
test('a policy nobody has written yet still answers, and says so', function () {
    $this->get(route('privacy'))
        ->assertSuccessful()
        ->assertSee('Privacy Policy')
        ->assertSee('No version of this policy has been published yet');
});

test('a draft is never shown on the public page', function () {
    publishedPolicy(attributes: ['content' => '## Live section', 'version' => '1.0']);

    Policy::query()->create([
        'policy_type' => PolicyTypeEnum::TERMS,
        'version' => '2.0',
        'title' => 'Terms of Service',
        'content' => '## Draft section',
        'requires_consent' => StatusYes::YES,
        'status' => StatusPolicy::DRAFT,
    ]);

    $this->get(route('terms'))
        ->assertSuccessful()
        ->assertSee('Live section')
        ->assertDontSee('Draft section');
});

/**
 * Published today, in force next month: the page has to keep showing the version
 * people are actually held to until the new one starts.
 */
test('a version published ahead of its date is not yet in force', function () {
    publishedPolicy(attributes: ['content' => '## Current terms']);

    Policy::query()->create([
        'policy_type' => PolicyTypeEnum::TERMS,
        'version' => '2.0',
        'title' => 'Terms of Service',
        'content' => '## Future terms',
        'requires_consent' => StatusYes::YES,
        'status' => StatusPolicy::PUBLISHED,
        'effective_at' => now()->addMonth(),
    ]);

    $this->get(route('terms'))
        ->assertSuccessful()
        ->assertSee('Current terms')
        ->assertDontSee('Future terms');
});

/**
 * A consent record points at one version. If that text stopped being readable the
 * record would say nothing useful.
 */
test('an archived version stays readable by its version number', function () {
    publishedPolicy(attributes: [
        'version' => '1.0',
        'content' => '## Old wording',
        'status' => StatusPolicy::ARCHIVED,
    ]);

    $this->get(route('terms').'?version=1.0')
        ->assertSuccessful()
        ->assertSee('Old wording');
});

test('a draft cannot be reached by its version number either', function () {
    publishedPolicy(attributes: ['content' => '## Live wording']);

    Policy::query()->create([
        'policy_type' => PolicyTypeEnum::TERMS,
        'version' => '9.0',
        'title' => 'Terms of Service',
        'content' => '## Unpublished wording',
        'requires_consent' => StatusYes::YES,
        'status' => StatusPolicy::DRAFT,
    ]);

    // Falls back to the version in force rather than 404ing, so a stale link in an
    // old email lands somewhere useful.
    $this->get(route('terms').'?version=9.0')
        ->assertSuccessful()
        ->assertSee('Live wording')
        ->assertDontSee('Unpublished wording');
});

test('a policy page cross-links to the others', function () {
    publishedPolicy();

    $this->get(route('terms'))
        ->assertSuccessful()
        ->assertSee(route('privacy'))
        ->assertSee(route('cookies'));
});

test('the landing page footer links to every policy', function () {
    $this->get(route('home'))
        ->assertSuccessful()
        ->assertSee(route('terms'))
        ->assertSee(route('privacy'))
        ->assertSee(route('cookies'));
});

// ==================== The section compiler

test('each heading becomes a section with an anchor derived from it', function () {
    $sections = app(PolicyContentService::class)
        ->sections("## 1. What we collect\n\nSome text.\n\n## 2. Your rights\n\nMore text.");

    expect($sections)->toHaveCount(2)
        // The leading numbering is dropped, so renumbering the sections later does
        // not move every anchor on the page.
        ->and($sections[0]['id'])->toBe('what-we-collect')
        ->and($sections[0]['title'])->toBe('1. What we collect')
        ->and($sections[1]['id'])->toBe('your-rights');
});

/**
 * The whole reason explicit anchors exist: a legal page gets deep-linked from
 * contracts and emails nobody can go back and edit.
 */
test('an explicit anchor survives the heading being reworded', function () {
    $first = app(PolicyContentService::class)->sections('## 5. Refunds {#refunds}');
    $second = app(PolicyContentService::class)->sections('## 5. Refunds and cancellations {#refunds}');

    expect($first[0]['id'])->toBe('refunds')
        ->and($second[0]['id'])->toBe('refunds')
        ->and($second[0]['title'])->toBe('5. Refunds and cancellations');
});

test('two headings that slug the same still get distinct anchors', function () {
    $sections = app(PolicyContentService::class)
        ->sections("## Contact\n\nOne.\n\n## Contact\n\nTwo.");

    expect($sections[0]['id'])->not->toBe($sections[1]['id']);
});

/**
 * Text before the first heading still has to render. It is left out of the
 * contents sidebar, where an untitled entry would be a link with nothing on it.
 */
test('a preamble renders but stays out of the contents', function () {
    $sections = app(PolicyContentService::class)
        ->sections("An opening paragraph.\n\n## 1. First\n\nBody.");

    expect($sections)->toHaveCount(2)
        ->and($sections[0]['title'])->toBeNull()
        ->and($sections[0]['html'])->toContain('An opening paragraph.');
});

test('markdown in a policy cannot inject script into the page', function () {
    $sections = app(PolicyContentService::class)
        ->sections("## Heading\n\n<script>alert('x')</script>");

    expect($sections[0]['html'])->not->toContain('<script>');
});

test('nothing compiles to no sections at all', function () {
    expect(app(PolicyContentService::class)->sections(null))->toBe([])
        ->and(app(PolicyContentService::class)->sections('   '))->toBe([]);
});

// ==================== The FAQ section

test('active questions show on the landing page in their set order', function () {
    Faq::query()->create([
        'question' => 'Second question?',
        'answer' => 'Second answer.',
        'flow_order' => 2,
        'status' => StatusDefault::ACTIVE,
    ]);

    Faq::query()->create([
        'question' => 'First question?',
        'answer' => 'First answer.',
        'flow_order' => 1,
        'status' => StatusDefault::ACTIVE,
    ]);

    $this->get(route('home'))
        ->assertSuccessful()
        ->assertSeeInOrder(['First question?', 'Second question?']);
});

test('a hidden question leaves the landing page', function () {
    Faq::query()->create([
        'question' => 'Hidden question?',
        'answer' => 'Hidden answer.',
        'flow_order' => 1,
        'status' => StatusDefault::INACTIVE,
    ]);

    $this->get(route('home'))
        ->assertSuccessful()
        ->assertDontSee('Hidden question?');
});

/**
 * An untouched starter kit should not show an empty accordion under a heading
 * promising answers.
 */
test('the FAQ section is absent entirely when nothing is published', function () {
    $this->get(route('home'))
        ->assertSuccessful()
        ->assertDontSee('Frequently asked questions');
});

test('an answer is rendered as markdown, with raw html escaped', function () {
    $faq = Faq::query()->create([
        'question' => 'Is markdown supported?',
        'answer' => "Yes, **bold** works.\n\n<script>alert('x')</script>",
        'flow_order' => 1,
        'status' => StatusDefault::ACTIVE,
    ]);

    expect($faq->answerHtml())->toContain('<strong>bold</strong>')
        ->and($faq->answerHtml())->not->toContain('<script>');
});
