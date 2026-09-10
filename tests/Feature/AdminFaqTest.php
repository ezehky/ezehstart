<?php

use App\Enums\ActivityActionEnum;
use App\Enums\FaqTypeEnum;
use App\Enums\StatusDefault;
use App\Enums\UserTypeEnum;
use App\Models\ActivityLog;
use App\Models\Faq;
use Livewire\Livewire;

beforeEach(function () {
    $this->admin = userOfType(UserTypeEnum::ADMIN);
});

/**
 * A question with its answer, for the tests that need one to already exist.
 */
function seedQuestion(array $attributes = []): Faq
{
    return Faq::query()->create([
        'faq_type' => FaqTypeEnum::GENERAL,
        'question' => 'An existing question?',
        'answer' => 'An existing answer.',
        'flow_order' => 1,
        'status' => StatusDefault::ACTIVE,
        ...$attributes,
    ]);
}

test('the screen is closed to a member', function () {
    $this->actingAs(userOfType(UserTypeEnum::USER))
        ->get(route('admin.config.faqs'))
        ->assertNotFound();
});

test('a question is added and appears on the site straight away', function () {
    Livewire::actingAs($this->admin)
        ->test('pages::admin.configs.faqs')
        ->call('create', FaqTypeEnum::GENERAL->value)
        ->set('question', 'How do I sign in?')
        ->set('answer', 'With your email address and password.')
        ->call('save')
        ->assertHasNoErrors();

    expect(Faq::query()->count())->toBe(1);

    $this->get(route('home'))->assertSee('How do I sign in?');
});

/**
 * A new question landing on top of whatever is already at position one would
 * silently reorder the section every time somebody added one.
 */
test('a new question is queued behind the ones already there', function () {
    seedQuestion(['flow_order' => 4]);

    Livewire::actingAs($this->admin)
        ->test('pages::admin.configs.faqs')
        ->call('create', FaqTypeEnum::GENERAL->value)
        ->assertSet('flow_order', 5);
});

test('two questions cannot be worded identically', function () {
    seedQuestion(['question' => 'Can I delete my account?']);

    Livewire::actingAs($this->admin)
        ->test('pages::admin.configs.faqs')
        ->call('create', FaqTypeEnum::GENERAL->value)
        ->set('question', 'Can I delete my account?')
        ->set('answer', 'Yes.')
        ->call('save')
        ->assertHasErrors('question');
});

test('editing a question keeps its own wording available to it', function () {
    $faq = seedQuestion(['question' => 'Is this editable?']);

    Livewire::actingAs($this->admin)
        ->test('pages::admin.configs.faqs')
        ->call('edit', $faq->id)
        ->assertSet('question', 'Is this editable?')
        ->set('answer', 'It is now.')
        ->call('save')
        ->assertHasNoErrors();

    expect($faq->fresh()->answer)->toBe('It is now.');
});

/**
 * Hiding rather than deleting is the whole point of the status column: the
 * wording is worth keeping even when the answer is not currently true.
 */
test('hiding a question takes it off the site without losing it', function () {
    $faq = seedQuestion(['question' => 'Will this be hidden?']);

    Livewire::actingAs($this->admin)
        ->test('pages::admin.configs.faqs')
        ->call('toggleStatus', $faq->id)
        ->assertHasNoErrors();

    expect($faq->fresh()->status)->toBe(StatusDefault::INACTIVE);

    $this->get(route('home'))->assertDontSee('Will this be hidden?');

    Livewire::actingAs($this->admin)
        ->test('pages::admin.configs.faqs')
        ->call('toggleStatus', $faq->id);

    expect($faq->fresh()->status)->toBe(StatusDefault::ACTIVE);
});

test('a question is deleted only after the dialog is confirmed', function () {
    $faq = seedQuestion();

    $component = Livewire::actingAs($this->admin)
        ->test('pages::admin.configs.faqs')
        ->call('confirmDelete', $faq->id);

    // Opening the dialog changes nothing on its own.
    expect(Faq::query()->count())->toBe(1);

    $component->call('delete')->assertHasNoErrors();

    expect(Faq::query()->count())->toBe(0);
});

test('an answer is stored as written and compiled on the way out', function () {
    Livewire::actingAs($this->admin)
        ->test('pages::admin.configs.faqs')
        ->call('create', FaqTypeEnum::GENERAL->value)
        ->set('question', 'Does markdown work?')
        ->set('answer', 'Yes, **it does**.')
        ->call('save');

    $faq = Faq::query()->first();

    expect($faq->answer)->toBe('Yes, **it does**.')
        ->and($faq->answerHtml())->toContain('<strong>it does</strong>');
});

test('every write is recorded in the activity log', function () {
    $faq = seedQuestion();

    Livewire::actingAs($this->admin)
        ->test('pages::admin.configs.faqs')
        ->call('toggleStatus', $faq->id)
        ->call('confirmDelete', $faq->id)
        ->call('delete');

    expect(ActivityLog::query()->where('action', ActivityActionEnum::FAQ_UPDATE)->exists())->toBeTrue()
        // Captured before the row went, so the line still says what was deleted.
        ->and(ActivityLog::query()->where('action', ActivityActionEnum::FAQ_DELETE)->exists())->toBeTrue();
});
