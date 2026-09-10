# testing.md

## Rule

Pest 5. Feature tests in `tests/Feature/`, named `{Area}{Subject}Test.php`. Livewire
pages are tested through `Livewire::test('pages::…')`. `tests/Unit/` holds tests for
the pure functions — the `k*()` helpers and enum behaviour — and nothing else.

### `tests/Pest.php` sets up every feature test

`RefreshDatabase` is applied globally, and a `beforeEach` resets the things
`RefreshDatabase` cannot:

```php
pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->beforeEach(function () {
        // The site configuration is a JSON file on the local disk, not a table.
        Storage::fake('local');

        // config('_site-config') is injected once at boot.
        config(['_site-config' => []]);
        cache()->forget('site_configuration');
    })
    ->in('Feature');
```

So a test file does **not** declare `uses(RefreshDatabase::class)` — it is already on.

**A test that needs site configuration must lay it down itself**, since every test
starts from an unconfigured install:

```php
app(SiteConfigurationService::class)->update(initials: true);
```

### File shape

```php
<?php

use App\Enums\FaqTypeEnum;
use App\Enums\StatusDefault;
use App\Enums\UserTypeEnum;
use App\Models\Faq;
use Livewire\Livewire;

beforeEach(function () {
    $this->actingAs(userOfType(UserTypeEnum::ADMIN));
});

/**
 * A question already on the site.
 */
function seededFaq(array $overrides = []): Faq
{
    return Faq::query()->create([
        'faq_type' => FaqTypeEnum::GENERAL,
        'question' => 'Do I need any design experience to join?',
        'answer' => 'No. Our programmes start from the fundamentals.',
        'flow_order' => 1,
        'status' => StatusDefault::ACTIVE,
        ...$overrides,
    ]);
}

test('a new question is added to the bottom of its group', function () {
    …
});
```

Order: imports → `beforeEach()` → file-local helper functions → tests.

### Test names are sentences

Lowercase, descriptive of the **behaviour**, not the method:

```
'a new question is added to the bottom of its group'
'an existing question is loaded into the form and updated in place'
'two questions cannot be asked the same way'
'markdown in an answer is compiled for the public page'
'raw html in an answer is escaped rather than rendered'
'a hidden question stays in the admin but leaves the landing page'
'active questions render on the landing page in flow order'
```

Never `'test_save_method'` or `'it works'`.

### Setting up an authenticated role

There is only a `UserFactory`, so roles are wired by two global helpers defined in
`tests/Pest.php`:

```php
userOfType(UserTypeEnum::ADMIN);               // active; admins land on the protected role
userOfType(UserTypeEnum::USER, ['email_verified_at' => now()]);
userOfType(UserTypeEnum::ADMIN, [], $role);    // an admin narrowed to one role
adminWithoutRole();                            // an admin who reaches nothing
roleWithGates('Media', ['content' => 'full']); // a role to put somebody on
```

**An admin reaches no gated screen without a live role**, so a plain
`User::factory()->create()` is a member and can only ever assert a redirect or a 404 on
the admin side. Reach for `userOfType()` first.

```php
beforeEach(function () {
    $this->admin = userOfType(UserTypeEnum::ADMIN);
});
```

### File-local factory helpers

Instead of model factories, each test file defines a named helper with an
`array $overrides` spread:

```php
/**
 * A question already on the site.
 */
function seededFaq(array $overrides = []): Faq
{
    return Faq::query()->create([
        'faq_type' => FaqTypeEnum::GENERAL,
        'question' => 'Do I need any design experience to join?',
        …
        ...$overrides,
    ]);
}
```

Called as `seededFaq(['flow_order' => 4])`. Give it a docblock.

### Testing a Livewire page

The component name is the `pages::` path:

```php
Livewire::test('pages::admin.configs.faqs')
    ->call('create', FaqTypeEnum::GENERAL->value)
    ->assertSet('flow_order', 5)
    ->set('question', 'How do I pay for a cohort?')
    ->set('answer', 'Pay at checkout. See [the terms](/terms#payments).')
    ->call('save')
    ->assertHasNoErrors();
```

Assertions in use:

```php
->assertSet('flow_order', 5)
->assertHasNoErrors()
->assertHasErrors(['question' => 'unique'])
->assertHasErrors(['answer' => 'required'])
->assertSee('…')
->assertDontSee('…')
```

Enum arguments are passed by **value**: `->call('create', FaqTypeEnum::GENERAL->value)`.
Model arguments by **id**: `->call('edit', $faq->id)`.

Pages with route parameters take them as the second argument:

```php
Livewire::test('pages::admin.users.user-view', ['user' => $account]);
```

To act as somebody, `Livewire::actingAs($admin)->test(…)`.

### Expectations

Chained `expect()` with `->and()`:

```php
expect($faq->faq_type)->toBe(FaqTypeEnum::GENERAL)
    ->and($faq->flow_order)->toBe(5)
    ->and($faq->status)->toBe(StatusDefault::ACTIVE);

expect(Faq::query()->count())->toBe(1)
    ->and($faq->fresh()->answer)->toBe('No. **Complete beginners** are welcome.');

expect($faq->answerHtml())->toContain('<a href="/terms#payments">the terms</a>');
expect($faq->answerHtml())->not->toContain('<script>');
expect(Faq::query()->find($faq->id))->toBeNull();
```

Always `->fresh()` before asserting on a model the component wrote to.

### HTTP assertions in the same file

Feature tests cross the Livewire/HTTP boundary freely — that is the point:

```php
test('a hidden question stays in the admin but leaves the landing page', function () {
    $faq = seededFaq();

    Livewire::test('pages::admin.configs.faqs')
        ->call('toggleStatus', $faq->id);

    expect($faq->fresh()->status)->toBe(StatusDefault::INACTIVE);

    $this->get(route('home'))->assertDontSee($faq->question);
});

test('active questions render on the landing page in flow order', function () {
    seededFaq(['question' => 'Second question?', 'answer' => 'Second.', 'flow_order' => 2]);
    seededFaq(['question' => 'First question?', 'answer' => 'First.', 'flow_order' => 1]);

    $html = $this->get(route('home'))->assertSuccessful()->getContent();

    expect(strpos($html, 'First question?'))->toBeLessThan(strpos($html, 'Second question?'));
});
```

### What to cover

For any new feature, one test per row:

| Case | Example |
| --- | --- |
| Create | "a new question is added to the bottom of its group" |
| Edit in place | "an existing question is loaded into the form and updated in place" |
| Each validation rule that matters | "two questions cannot be asked the same way", "an answer is required" |
| Each state transition | "a hidden question … leaves the landing page" |
| Delete | "a deleted question is removed" |
| Security boundary | "raw html in an answer is escaped rather than rendered" |
| Record lock | see `AdminCohortLockTest` |
| Public consequence | "active questions render on the landing page in flow order" |

### Running

```bash
php artisan test --compact --filter=AdminFaqTest
php artisan test --compact tests/Feature/AdminFaqTest.php
composer test          # config:clear then the full suite
```

Run the narrowest filter that proves your change.

### Existing suites

`AdminCohortLockTest`, `AdminCohortTabsTest`, `AdminCurriculumTest`, `AdminFaqTest`,
`AdminManualAdmissionTest`, `AdminTransactionsTest`, `AdminUserManagementTest`,
`AuthEmailsTest`, `AuthenticationTest`, `ClassSessionCurriculumTest`,
`CohortRefundTest`, `FaqSeederTest`, `FlutterwaveServiceTest`, `KoraWebhookTest`,
`LandingPageTest`, `LegalPagesTest`, `NotificationCenterTest`, `PasswordlessAuthTest`,
`PaystackServiceTest`, `PolicyConsentTest`, `TrainerPortalTest`.

> **Known state:** there are pre-existing failures in the auth suites on `main` that
> predate current work. Before reporting a regression, confirm the failure is new by
> running the same filter on a clean checkout — and never absorb a pre-existing failure
> silently into a "tests pass" report.

## Why

- Per-file `RefreshDatabase` lets read-only tests (landing page, service unit checks)
  skip the migration cost.
- File-local seed helpers over factories: most models have no factory, and a helper
  named for the scenario (`seededFaq`) documents the fixture better than
  `Faq::factory()->general()->active()`.
- Testing through `Livewire::test('pages::…')` exercises validation, authorization, the
  audit log, and the model write in one call — the same path a user takes.
- Sentence test names make `--compact` output a readable specification.
- Crossing into HTTP in the same test proves the admin action had the intended public
  effect, which is the thing that actually matters.

## Template

```php
<?php

use App\Enums\StatusInvoice;
use App\Enums\UserTypeEnum;
use App\Models\Invoice;
use Livewire\Livewire;

beforeEach(function () {
    $this->actingAs(userOfType(UserTypeEnum::ADMIN));
});

/**
 * An invoice already issued to a customer.
 */
function seededInvoice(array $overrides = []): Invoice
{
    return Invoice::query()->create([
        'user_id' => User::factory()->create()->id,
        'reference' => 'INV-ABC12320260731',
        'title' => 'Cohort enrolment',
        'amount' => 5000000,
        'status' => StatusInvoice::ISSUED,
        'issued_at' => now(),
        ...$overrides,
    ]);
}

test('a new invoice is issued with a generated reference', function () {
    Livewire::test('pages::admin.finance.invoices')
        ->call('create')
        ->set('title', 'Cohort enrolment')
        ->set('amount', 50000)
        ->call('save')
        ->assertHasNoErrors();

    expect(Invoice::query()->count())->toBe(1)
        ->and(Invoice::query()->first()->status)->toBe(StatusInvoice::DRAFT);
});

test('an amount is required', function () {
    Livewire::test('pages::admin.finance.invoices')
        ->call('create')
        ->set('title', 'No amount')
        ->set('amount', '')
        ->call('save')
        ->assertHasErrors(['amount' => 'required']);
});

test('a paid invoice can no longer be edited', function () {
    $invoice = seededInvoice(['status' => StatusInvoice::PAID]);

    Livewire::test('pages::admin.finance.invoice', ['invoice' => $invoice])
        ->set('title', 'Changed')
        ->call('save')
        ->assertHasErrors();

    expect($invoice->fresh()->title)->toBe('Cohort enrolment');
});
```

Create with `php artisan make:test --pest AdminInvoiceTest`.

## Avoid

- Re-declaring `uses(RefreshDatabase::class)` — `tests/Pest.php` already applies it.
- Wiring a role by hand instead of calling `userOfType()` or `roleWithGates()`.
- Forgetting that a lockout guard needs a *second* full-access admin around before
  it will let a test move the first one off its role.
- Assuming the site configuration exists. Every feature test starts from an
  unconfigured install; lay it down with `update(initials: true)` if the code under
  test reads it.
- Writing to the real `storage/app/private/site-configuration.json` from a test.
  `Storage::fake('local')` in `tests/Pest.php` prevents it — do not undo that.
- PHPUnit class-style tests — this project is Pest functions.
- `it('…')` — the project uses `test('…')`.
- Test names that describe the method rather than the behaviour.
- Asserting on a stale model instance — call `->fresh()`.
- Passing an enum object where the component expects its value.
- Mocking Eloquent — use the real database.
- Skipping the security test (escaping, locks, ownership) for a feature that has one.
- Deleting or weakening an existing test to make a change pass.
- Reporting "tests pass" while a pre-existing failure is in the output — say which
  failures are pre-existing and which are yours.
