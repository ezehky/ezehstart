# enums.md

## Rule

**Every** status, type, category, provider, role, and vocabulary in this project is a
backed PHP enum in `app/Enums/`. There are 37. A bare string or int in a status column
is a bug.

Every enum:

1. Lives in `App\Enums`
2. Is **backed** — `: int` for statuses and yes/no flags, `: string` for vocabularies
3. `use WithEnumHelpers;` as the very first line of the body
4. Declares **one `is{CASE}(): bool` predicate per case**, in case order
5. Adds presenters (`label()` comes free; `defaultTitle()`, `icon()`, `color()`
   overrides, `url()`, `driver()`, …) after the predicates

### Backing type choice

| Use | Backing | Examples |
| --- | --- | --- |
| Lifecycle / status | `int` | `StatusDefault`, `StatusUser`, `StatusCohort`, `StatusTransaction`, `StatusAdmission`, `StatusPolicy`, `StatusClassSession`, `StatusAttendance` |
| Boolean-ish flag stored as a column | `int` with `YES = 1` / `NO = 0` | `StatusYes` |
| Vocabulary / identifier | `string` | `UserRoleEnum`, `PolicyTypeEnum`, `FaqTypeEnum`, `TransactionTypeEnum`, `DayOfWeekEnum`, `SocialProviderEnum`, `VendorEnum`, `ActivityActionEnum` |

`StatusDefault` (`ACTIVE = 1`, `INACTIVE = 0`) is the **shared default** — use it for
any generic on/off column rather than inventing a new enum.

### What `WithEnumHelpers` gives you for free

```php
$case->label();          // kBreakText($this->name) → "IN_REVIEW" becomes "In Review"
$case->boolValue();      // (bool) $this->value
$case->color();          // 'green'|'red'|'amber'|'blue'|'zinc', from config('_setups.status-color-map')
Enum::values();          // array of backing values
Enum::forSelect();       // [value => label] for <option> loops
```

`color()` reads `config('_setups.status-color-map')` keyed by `kSlug($case->name)`.
**When you add a new status case, add its colour to `config/_setups.php`** under
`success` / `danger` / `warning` / `primary`, or it silently falls back to `zinc`.

`forSelect()` supports exclusions and exclusives:

```php
FaqTypeEnum::forSelect();                          // all cases
StatusUser::forSelect(['deleted']);                // all except 'deleted'
StatusUser::forSelect(['active', 'suspended'], true); // only these two
```

An enum can pin its own default by overriding:

```php
protected static function forSelectValuesArg(): array
{
    return ['deleted', 'banned'];
}
```

### Enums in the database

- Cast on the model: `'status' => StatusCohort::class`
- Default in the migration written as the **case**, not a literal:
  `$table->tinyInteger('status')->default(StatusCohort::CREATED);`
- `tinyInteger` for int enums, `string` (sized) for string enums
- Index any enum column that is filtered on

### Enums in validation

```php
'faq_type' => ['required', Rule::enum(FaqTypeEnum::class)],
```

### Enums in routes

String enums bind implicitly:

```php
Route::get('/{provider}/redirect', function (SocialProviderEnum $provider) { … });
Route::post('/payment/{vendor}', Webhook\PaymentWebhookController::class);
```

### Enums in Blade

```blade
{{ $item->training_type->label() }}
<x-status :status="$item->status" />
{{ $item->status->isActive() ? 'Hide from site' : 'Show on site' }}
:icon="$item->status->isActive() ? 'eye-slash' : 'eye'"
```

Never `{{ $item->status->value }}` in user-facing text.

## Why

- One `is*()` per case makes conditionals read as English and survives refactors —
  renaming a case is a compiler-visible change, not a string search.
- `label()` derived from the case **name** (not a hand-written map) means adding a case
  costs one line.
- Centralising colour in `config/_setups.php` lets 9 different status enums share one
  visual language, so `<x-status>` works for all of them with no per-enum code.
- `forSelect()` means every `<select>` in the app is built the same way and can never
  drift from the enum.
- Backing statuses with `int` keeps status columns as `tinyInteger` — small, indexable,
  and orderable by lifecycle position.

## Example

`app/Enums/StatusCohort.php` — the canonical int status enum:

```php
<?php

namespace App\Enums;

use App\Traits\WithEnumHelpers;

enum StatusCohort: int
{
    use WithEnumHelpers;

    case CREATED = 0;
    case SCHEDULED = 1;
    case ADMITTING = 2;
    case ONGOING = 3;
    case CONCLUDED = 4;
    case CANCELLED = 5;
    case PAUSED = 6;

    public function isCreated(): bool
    {
        return $this === self::CREATED;
    }

    public function isScheduled(): bool
    {
        return $this === self::SCHEDULED;
    }

    // … one per case, in case order …

    public function isPaused(): bool
    {
        return $this === self::PAUSED;
    }
}
```

`app/Enums/PolicyTypeEnum.php` — a string enum with presenters:

```php
enum PolicyTypeEnum: string
{
    use WithEnumHelpers;

    case TERMS = 'terms';
    case PRIVACY = 'privacy';
    case COOKIES = 'cookies';

    public function isTerms(): bool
    {
        return $this === self::TERMS;
    }

    public function isPrivacy(): bool
    {
        return $this === self::PRIVACY;
    }

    public function isCookies(): bool
    {
        return $this === self::COOKIES;
    }

    /**
     * The public route that renders this policy. Route names are unchanged from when
     * the pages were static, so existing links keep working.
     */
    public function routeName(): string
    {
        return $this->value;
    }

    public function url(): string
    {
        return route($this->routeName());
    }

    /**
     * The heading a policy of this type falls back to before one is published.
     */
    public function defaultTitle(): string
    {
        return match ($this) {
            self::TERMS => 'Terms of Service',
            self::PRIVACY => 'Privacy Policy',
            self::COOKIES => 'Cookie Policy',
        };
    }
}
```

`SocialProviderEnum` shows the static-filter pattern:

```php
public static function activeCases(): array
{
    return collect(self::cases())
        ->filter(fn ($case) => $case->status())
        ->map(fn ($case) => $case)
        ->toArray();
}
```

`ActivityActionEnum` shows the dotted-value convention for namespaced actions:

```php
case USER_ROLE_GRANT = 'user-role.grant';
case COHORT_STATUS_CHANGE = 'cohort.status-change';
case FAQ_CREATE = 'faq.create';
```

…with two presenters that build log copy: `startDescription()` (verb prefix, grouped
`match` arms) and `defaultDescription()` (full sentence for actions with no subject).

## Template

### Status enum

```php
<?php

namespace App\Enums;

use App\Traits\WithEnumHelpers;

enum StatusInvoice: int
{
    use WithEnumHelpers;

    case DRAFT = 0;
    case ISSUED = 1;
    case PAID = 2;
    case VOID = 3;

    public function isDraft(): bool
    {
        return $this === self::DRAFT;
    }

    public function isIssued(): bool
    {
        return $this === self::ISSUED;
    }

    public function isPaid(): bool
    {
        return $this === self::PAID;
    }

    public function isVoid(): bool
    {
        return $this === self::VOID;
    }
}
```

Then in `config/_setups.php` → `status-color-map`:

```php
'draft'  => 'warning',   // already present
'issued' => 'primary',
'paid'   => 'success',   // already present
'void'   => 'danger',
```

### Vocabulary enum with presenters

```php
<?php

namespace App\Enums;

use App\Traits\WithEnumHelpers;

enum InvoiceChannelEnum: string
{
    use WithEnumHelpers;

    case EMAIL = 'email';
    case POST = 'post';

    public function isEmail(): bool
    {
        return $this === self::EMAIL;
    }

    public function isPost(): bool
    {
        return $this === self::POST;
    }

    /**
     * The heading this channel appears under in the admin.
     */
    public function defaultTitle(): string
    {
        return match ($this) {
            self::EMAIL => 'Emailed invoices',
            self::POST => 'Posted invoices',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::EMAIL => 'envelope',
            self::POST => 'inbox-stack',
        };
    }
}
```

### Wiring an enum into a feature

```php
// migration
$table->string('channel', 30)->default(InvoiceChannelEnum::EMAIL)->index();
$table->tinyInteger('status')->default(StatusInvoice::DRAFT);

// model
protected function casts(): array
{
    return [
        'channel' => InvoiceChannelEnum::class,
        'status' => StatusInvoice::class,
    ];
}

// livewire prop + rules
public InvoiceChannelEnum $channel = InvoiceChannelEnum::EMAIL;

'channel' => ['required', Rule::enum(InvoiceChannelEnum::class)],

// blade
<flux:select label="Channel" wire:model="channel">
    @foreach (App\Enums\InvoiceChannelEnum::forSelect() as $value => $label)
        <flux:select.option value="{{ $value }}">{{ $label }}</flux:select.option>
    @endforeach
</flux:select>

<x-status :status="$item->status" />
```

## Avoid

- Pure (unbacked) enums.
- Enums without `use WithEnumHelpers;`.
- Skipping the `is{CASE}()` predicates, or writing only some of them.
- Hand-writing a `label()` `match` when `kBreakText($this->name)` already produces the
  right text — override `label()` only when the derived text is wrong.
- Comparing with `==` or the backing value: write `$status->isActive()`, not
  `$status === StatusDefault::ACTIVE` in application code and never
  `$status->value === 1`.
- `$model->status->value` in Blade — use `label()` or `<x-status>`.
- New status enums where `StatusDefault` or `StatusYes` already fits.
- Adding a status case without adding its colour to `config/_setups.php`.
- `->cases()` loops in Blade — build the option list in `mount()` or a `#[Computed]`
  and pass it down (`$this->faqCases = FaqTypeEnum::cases();`).
