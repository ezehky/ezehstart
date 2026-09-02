# traits.md

## Rule

Shared behaviour is composed with traits in `app/Traits/`, all named `With{Capability}`.
**Check this list before writing any shared logic.**

| Trait | Applied to | Gives you |
| --- | --- | --- |
| `WithEnumHelpers` | **every enum** | `label()`, `color()`, `boolValue()`, `values()`, `forSelect()` |
| `WithDynamicModelFormatting` | **almost every model** | magic `->fooMoney()`, `->fooNumber()`, `->fooUrl()`, `->fooHuman()`, `->fooDatetimeForUpdate()` |
| `WithFormResponseMessage` | **every form page** | `respondSuccess()`, `respondError()`, `respondPrimary()`, `createAttributes()` |
| `WithAuthWorker` | auth pages | `createUser()`, `loginUser()`, `userDashboardRedirect()`, `assignDefaultRole()`, `logActivity()` |
| `WithPasswordTools` | any page taking a password | `passwordStrengthRule()`, `$passwordNote` |
| `WithUserRoleManager` | admin user listings | the whole "manage roles" modal — `roleUser`, `roleMatrix`, `grantRole()`, `revokeRole()`, `switchRole()`, `afterRoleChange()` hook |
| `WithEmailResolver` | **every Mailable** | injects `$emailConfig` into the mail view |

Livewire's own traits used alongside them: `WithPagination`, `WithFileUploads`.

Those seven are the whole of `app/Traits/`. The eighth is the one you write the moment
two pages need the same state and the same handful of methods — a shared modal, a
shared editor, a shared set of guards. Name it `With{Capability}` and follow the rules
below.

### Composition order at the `use` line

Project traits, alphabetical-ish, with Livewire traits first where both appear:

```php
use WithPagination, WithUserRoleManager;
use WithFileUploads, WithFormResponseMessage;
use WithAuthWorker, WithPasswordTools;
```

### Traits that require other traits

A trait may `use` another. `WithUserRoleManager` and `WithAuthWorker` both pull in
`WithFormResponseMessage`, so a page using either does **not** need to list it again:

```php
trait WithCohortAdmin
{
    use WithFormResponseMessage;

    public Cohort $cohort;
    …
}
```

### Overridable hooks

A trait declares an empty `protected` hook the host page implements. This is how a
shared modal tells the page to refresh its own data:

```php
// in the trait
/**
 * Refresh whatever the host page renders once a role changed. Pages override this.
 */
protected function afterRoleChange(): void {}

// in the page
protected function afterRoleChange(): void
{
    unset($this->students, $this->metrics);
}
```

### Guard methods

Traits that own a record own its write guard, and it is named `ensure*`:

```php
/**
 * Refuse any write against a concluded or cancelled cohort. Every admin
 * action that mutates the cohort or its children calls this first, so the
 * lock holds even when a control is reached outside the rendered UI.
 */
protected function ensureCohortIsEditable(): void
{
    $this->respondError($this->cohort->lockedReason(), if: $this->cohort->isLocked());
}
```

Every mutating method in every tab calls it as its **first** statement.

### Service accessors

Traits wrap container resolution when a page needs the same service repeatedly:

```php
trait WithTrainerResource
{
    protected function trainerService()
    {
        return app(TrainerService::class, ['user' => auth()->user()]);
    }
}
```

### `@property-read` docs

Traits that add `#[Computed]` properties document them on the trait, so the host page's
IDE knows about them:

```php
/**
 * Drives the "manage roles" modal shared by the admins list and the user view.
 *
 * @property-read User|null $roleUser
 * @property-read array<int, array{role: string, label: string, description: string, has: bool, blocked: string|null}> $roleMatrix
 */
trait WithUserRoleManager
```

### Model formatting magic — `WithDynamicModelFormatting`

`__call` resolves these suffixes to the snake_case column:

| Suffix | Example | Result |
| --- | --- | --- |
| `Money` | `$t->amountMoney()` | `kMoneyFormat($t->amount)` |
| `Number` | `$c->seatsNumber()` | `number_format($c->seats)` |
| `Url` | `$u->avatarUrl()` | storage URL or `kSafeImage()` fallback |
| `Human` | `$m->createdAtHuman()` | `M jS, Y` |
| `HumanDay` | `$s->startsAtHumanDay()` | `D, jS M, Y` |
| `DatetimeHuman` | `$s->startsAtDatetimeHuman()` | `M jS, Y • h:ia` |
| `DiffForHumans` | `$m->createdAtDiffForHumans()` | `2 hours ago` |
| `FormatHuman` | `$m->createdAtFormatHuman(format: 'M Y')` | custom format |
| `Pv` | `$m->pointsPv()` | `kPointFormat()` |
| `DatetimeForUpdate` | `$c->trainingStartsAtDatetimeForUpdate()` | `Y-m-d\TH:i` |
| `TimeForUpdate` | `$s->startTimeForUpdate()` | `HH:MM` |

Pass `true` as the first argument for the **authenticated user's** timezone:
`$m->createdAtHuman(true)`.

Image columns are detected by name (`image`, `avatar`, `photo`, `banner`, `thumbnail`,
`logo`, `cover`, `picture`, `background`, `poster`, `evidence`, `flag`) and fall back to
`kSafeImage()` — so `avatarUrl()` never returns a broken link.

## Why

- A Livewire SFC cannot cleanly extend a project base class, so composition is the only
  reuse mechanism available for page behaviour.
- Putting the cohort lock in `WithCohortAdmin` means all ten cohort tabs enforce it
  identically and a new tab inherits the rule by using the trait.
- Overridable hooks (`afterRoleChange()`) let a shared modal live in one trait while
  each host page invalidates only its own computed caches.
- `WithDynamicModelFormatting` removes ~15 accessor methods from every model and
  guarantees every date and every amount in the app formats identically.
- `WithEnumHelpers` means adding an enum case costs one line and the badge, the select
  option, and the label all follow.

## Example

`app/Traits/WithFormResponseMessage.php` — the most-used trait:

```php
<?php

namespace App\Traits;

use Flux\Flux;
use Illuminate\Validation\ValidationException;

trait WithFormResponseMessage
{
    private $errorExceptionCatchStopper = '';

    public function respondPrimary(
        string $message = 'You have made no changes to save!!',
        bool $if = false,
        ?callable $callback = null,
        bool $flash = false,
        string $heading = ''
    ): bool {
        if ($if) {
            if (! $flash) {
                Flux::toast(heading: $heading, text: $message, variant: 'warning');
            }

            if ($flash) {
                session()->flash('primary', $message);
            }

            if (is_callable($callback)) {
                $callback();
            }

            throw ValidationException::withMessages(['errorExceptionCatchStopper' => 'Stop Code']);
        }

        return true;
    }

    public function respondSuccess(string $message = 'Saved!!', bool $flash = false, string $heading = ''): bool
    {
        if ($flash) {
            session()->flash('success', $message);

            return true;
        }

        Flux::toast(heading: $heading, text: $message, variant: 'success');

        return true;
    }
}
```

## Template

```php
<?php

namespace App\Traits;

use App\Models\Invoice;
use App\Services\InvoiceService;
use Livewire\Attributes\Computed;

/**
 * Shared by every admin invoice tab: owns the record, its lock, and the guard
 * every write goes through.
 *
 * @property-read bool $canUpdateInvoice
 */
trait WithInvoiceAdmin
{
    use WithFormResponseMessage;

    public Invoice $invoice;

    /**
     * Load what every invoice tab renders in its header.
     */
    protected function loadInvoice(): void
    {
        $this->invoice->load('user');
        $this->invoice->loadCount('lines');
    }

    #[Computed]
    public function canUpdateInvoice(): bool
    {
        return ! $this->invoice->status->isPaid();
    }

    /**
     * Refuse any write against a settled invoice, so the lock holds even when a
     * control is reached outside the rendered UI.
     */
    protected function ensureInvoiceIsEditable(): void
    {
        $this->respondError('A paid invoice can no longer be changed.', if: $this->invoice->status->isPaid());
    }

    /**
     * Refresh whatever the host page renders once the invoice changed. Pages override this.
     */
    protected function afterInvoiceChange(): void {}

    protected function invoiceService(): InvoiceService
    {
        return app(InvoiceService::class);
    }
}
```

## Avoid

- A trait not named `With*`.
- Duplicating `use WithFormResponseMessage;` on a page whose other trait already pulls
  it in.
- Putting business *processes* in a trait — traits hold page/model behaviour;
  transactions and cross-aggregate work belong in a Service.
- A trait with a constructor, or one that assumes properties it does not declare.
- Re-implementing money/date formatting instead of `WithDynamicModelFormatting`.
- Re-implementing `label()`/`forSelect()` instead of `WithEnumHelpers`.
- Calling `Flux::toast()` directly instead of `respondSuccess()`/`respondError()`.
- Skipping the `ensure*()` guard because the button is already hidden.
- Abstract classes or interfaces as a substitute for a trait.
