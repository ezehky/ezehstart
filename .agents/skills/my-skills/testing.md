# testing.md

## Rule

Pest 4. Feature tests only, in `tests/Feature/`, named `{Area}{Subject}Test.php`.
Livewire pages are tested through `Livewire::test('pages::…')`.

### `RefreshDatabase` is declared per file, not globally

`tests/Pest.php` deliberately leaves it commented out:

```php
pest()->extend(TestCase::class)
 // ->use(RefreshDatabase::class)
    ->in('Feature');
```

So every test file that touches the database declares it itself:

```php
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);
```

### File shape

```php
<?php

use App\Enums\FaqTypeEnum;
use App\Enums\StatusDefault;
use App\Enums\StatusUser;
use App\Enums\UserRoleEnum;
use App\Models\Faq;
use App\Models\Role;
use App\Models\User;
use App\Models\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $admin = User::factory()->create(['status' => StatusUser::ACTIVE]);
    $role = Role::query()->firstOrCreate(['name' => UserRoleEnum::ADMIN]);
    UserRole::query()->create(['user_id' => $admin->id, 'role_id' => $role->id]);

    $this->actingAs($admin);
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

Order: imports → `uses()` → `beforeEach()` → file-local helper functions → tests.

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

There is only a `UserFactory` — roles are wired manually:

```php
$admin = User::factory()->create(['status' => StatusUser::ACTIVE]);
$role = Role::query()->firstOrCreate(['name' => UserRoleEnum::ADMIN]);
UserRole::query()->create(['user_id' => $admin->id, 'role_id' => $role->id]);

$this->actingAs($admin);
```

`firstOrCreate` on the role so several `beforeEach` runs share it.

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
Livewire::test('pages::admin.training.cohort-students', ['cohort' => $cohort]);
```

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
use App\Enums\StatusUser;
use App\Enums\UserRoleEnum;
use App\Models\Invoice;
use App\Models\Role;
use App\Models\User;
use App\Models\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $admin = User::factory()->create(['status' => StatusUser::ACTIVE]);
    $role = Role::query()->firstOrCreate(['name' => UserRoleEnum::ADMIN]);
    UserRole::query()->create(['user_id' => $admin->id, 'role_id' => $role->id]);

    $this->actingAs($admin);
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

- `uses(RefreshDatabase::class)` missing from a file that writes to the database.
- Un-commenting the global `->use(RefreshDatabase::class)` in `tests/Pest.php`.
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
