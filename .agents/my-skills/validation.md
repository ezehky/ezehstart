# validation.md

## Rule

Validation lives in **`protected function rules(): array`** on the Livewire component,
using **array-of-rules** syntax. There are no Form Request classes, no `$rules`
property, and no `#[Validate]` attributes.

```php
protected function rules(): array
{
    return [
        'faq_type' => ['required', Rule::enum(FaqTypeEnum::class)],
        'question' => [
            'required',
            'string',
            'max:255',
            Rule::unique(Faq::class, 'question')->ignore($this->faq?->id),
        ],
        'answer' => ['required', 'string'],
        'flow_order' => ['required', 'integer', 'min:1'],
        'status' => ['boolean'],
    ];
}
```

Trigger with `$this->validate();` as the first (or second, after a lock guard) line of
`save()`.

### Rule vocabulary in use

```php
'required' | 'nullable' | 'sometimes'
'string' | 'integer' | 'numeric' | 'boolean' | 'array' | 'date' | 'email' | 'url'
'max:255' | 'min:1' | 'max:190'
'after_or_equal:registration_starts_at'
Rule::enum(FaqTypeEnum::class)
Rule::unique(Faq::class, 'question')->ignore($this->faq?->id)
Rule::unique(Cohort::class, 'name')->where('training_id', $this->cohort->training_id)->ignore($this->cohort->id)
new ImageRule(required: false, size: 1024)
new MoneyRule(user: $user, min: 1000, max: 100000, percentFee: 2)
new EmailRule
```

### Uniqueness

Always `Rule::unique(Model::class, 'column')`, class-first, and always `->ignore()`
the current record on an edit form:

```php
Rule::unique(Training::class, 'name')->ignore($this->training?->id)
```

Scope it with `->where()` when uniqueness is per-parent:

```php
Rule::unique(Cohort::class, 'name')
    ->where('training_id', $this->cohort->training_id)
    ->ignore($this->cohort->id)
```

### Nested / dotted keys

Config-style forms validate array paths directly:

```php
'config.name' => ['required', 'string', 'max:150'],
'config.email' => ['nullable', 'email', 'max:190'],
'config.email-settings.verification' => ['required', 'boolean'],
'config.user.account-deletion-days' => ['required', 'integer'],
```

### Custom rules — `app/Rules/`

Three exist. Constructors use **promoted readonly properties with named arguments** at
the call site.

**`EmailRule`** — syntax + disposable-domain + MX check. No constructor arguments.

```php
'email' => ['required', new EmailRule],
```

**`ImageRule`** — required flag, KB size cap, extra mime types.

```php
'logoUpload' => [new ImageRule(required: false, size: 1024)],
'faviconUpload' => [new ImageRule(required: false, size: 512, addMimes: ['ico'])],
'avatar' => [new ImageRule(size: 300)],
```

Defaults: `required: true`, `size: 300` (KB), mimes `jpeg, png, jpg, webp`.

**`MoneyRule`** — min/max, flat or percentage fee, and wallet-balance sufficiency.

```php
'amount' => [
    'required',
    'numeric',
    new MoneyRule(
        user: auth()->user(),
        min: $config['min'],
        max: $config['max'],
        percentFee: $config['fee'],
        walletType: TransactionWalletEnum::BALANCE,
    ),
],
```

Custom rule shape:

```php
<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

class ThingRule implements ValidationRule
{
    public function __construct(
        private readonly bool $required = true,
        private readonly int $max = 100,
    ) {}

    /**
     * Run the validation rule.
     *
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($this->required && $value === null) {
            $fail('The :attribute field is required!');

            return;
        }

        // Skip checks if nullable and no value given
        if ($value === null) {
            return;
        }

        if ($value > $this->max) {
            $fail("The :attribute must not be greater than {$this->max}.");
        }
    }
}
```

Note: `return` after each `$fail()` so only one message per rule fires;
`:attribute` placeholder in generic messages, interpolated text where the message
carries a value. `MoneyRule` humanises the attribute itself with
`kBreakText($attribute, lowercase: true)`.

### Password strength

Use the shared trait rather than repeating the `Password` rule:

```php
use App\Traits\WithPasswordTools;

'password' => ['required', 'confirmed', $this->passwordStrengthRule()],
```

```php
trait WithPasswordTools
{
    public string $passwordNote = 'Password min 8 chars, a symbol, a number, with both uppercase and lowercase chars.';

    protected function passwordStrengthRule()
    {
        return Password::min(8)->symbols()->mixedCase()->numbers();
    }
}
```

`$passwordNote` is rendered as the field description so the message and the rule cannot
drift.

### Attribute names

`WithFormResponseMessage::createAttributes()` derives human attribute names from the
rule keys — `config.email-settings.verification` becomes "verification". Use it when a
dotted-key form produces ugly messages:

```php
$this->validate($this->rules(), attributes: $this->createAttributes($this->rules()));
```

### Business-rule validation — not `rules()`

Rules that depend on state rather than input are enforced with `respondError()` /
`respondPrimary()` after `validate()`, or with a service guard:

```php
// lock
$this->ensureCohortIsEditable();

// service guard
$reason = $service->grantBlockedReason($user, $enum);
$this->respondError($reason ?? '', if: $reason !== null);

// inline field error
$this->respondError('That code is already taken.', if: $taken, field: 'affiliate_code');
```

### Server-side authorization checks

Ownership and existence checks use `abort_unless`, not validation:

```php
abort_unless($this->cohort->is($this->classSession->cohort), 404);
abort_unless(array_key_exists($status, $this->statusOptions), 422);
abort_unless((bool) $this->admission, 404);
```

### Displaying errors

Flux inputs render their own error. Add `<flux:error name="field" />` explicitly when:

- the field is inside a grid where the message would be clipped,
- the field is a custom `x-form.*` component,
- several fields share one error line.

```blade
<flux:input wire:model="question" label="Question" placeholder="…" />
<flux:error name="question" />
```

## Why

- `rules()` as a method (not a property) lets rules reference component state —
  `->ignore($this->faq?->id)`, `->where('training_id', $this->cohort->training_id)` —
  which a static array cannot.
- Array syntax (not pipe strings) is required anyway for `Rule::` objects and custom
  rule instances, so the project uses it everywhere for consistency.
- Custom rules with promoted readonly constructor args read as configuration at the
  call site (`new ImageRule(required: false, size: 1024)`) and keep the message text
  next to the check that produces it.
- Business rules stay out of `rules()` so the validation array remains a pure
  description of *input shape*, and state-dependent refusals get a toast rather than an
  inline field error.

## Example

`⚡cohort-editor.blade.php` — the widest `rules()` in the project:

```php
protected function rules(): array
{
    return [
        'name' => [
            'required',
            'string',
            'max:255',
            Rule::unique(Cohort::class, 'name')
                ->where('training_id', $this->cohort->training_id)
                ->ignore($this->cohort->id),
        ],
        'fee' => ['required', 'numeric', 'min:0'],
        'compare_fee' => ['nullable', 'numeric', 'min:0'],
        'registration_starts_at' => ['required', 'date'],
        'registration_ends_at' => ['required', 'date', 'after_or_equal:registration_starts_at'],
        'training_starts_at' => ['required', 'date'],
        'training_ends_at' => ['required', 'date', 'after_or_equal:training_starts_at'],
        'description' => ['nullable', 'string'],
        'location' => ['nullable', 'string', 'max:255'],
        'meeting_platform' => ['nullable', 'string', 'max:255'],
        'meeting_link' => ['nullable', 'url', 'max:255'],
        'is_online' => ['boolean'],
    ];
}
```

## Template

```php
protected function rules(): array
{
    return [
        'reference' => [
            'required',
            'string',
            'max:200',
            Rule::unique(Invoice::class, 'reference')->ignore($this->invoice?->id),
        ],
        'channel' => ['required', Rule::enum(InvoiceChannelEnum::class)],
        'amount' => ['required', 'numeric', 'min:0'],
        'issued_at' => ['required', 'date'],
        'due_at' => ['nullable', 'date', 'after_or_equal:issued_at'],
        'notes' => ['nullable', 'string'],
        'attachment' => [new ImageRule(required: false, size: 1024, addMimes: ['pdf'])],
        'status' => ['boolean'],
    ];
}

public function save(): bool
{
    $this->validate();
    …
}
```

## Avoid

- `app/Http/Requests/` — none exist, do not create one.
- `public array $rules = [...]` or `#[Validate('required')]`.
- Pipe-string rules (`'required|string|max:255'`).
- `Rule::unique('table_name', 'column')` with a table string — pass the model class.
- Forgetting `->ignore()` on an edit form (the record fails its own uniqueness check).
- Encoding business state in `rules()` — that belongs in `respondError()` or a service
  guard.
- Custom rule classes without `implements ValidationRule` and the `$fail` docblock.
- Repeating the `Password::min(8)->…` chain instead of `WithPasswordTools`.
- Trusting the UI: a control hidden because a record is locked must **still** be
  refused server-side.
