# policies.md

## Rule

**This project does not use Laravel Policies.** `app/Policies/` does not exist. There
is no `Gate::define()`, no `$this->authorize()`, no `@can` directive, and no
`AuthServiceProvider`.

> Note: `App\Models\Policy` is a **legal document** (terms / privacy / cookies), not an
> authorization policy. Do not confuse them.

Authorization is four layers:

| Layer | Mechanism | Guards |
| --- | --- | --- |
| 1. Workspace | Role middleware in `bootstrap/app.php` | Can this user reach `/app-splash` at all? |
| 2. Page | `kPageGate()` + the sidebar filter — see [gates.md](gates.md) | Can this admin see *this* admin page, and how far in? |
| 3. Record | `abort_unless(...)` in `mount()` / actions | Does this record belong to this user / parent? |
| 4. Action | Model `can*()` + service `*BlockedReason()` + trait `ensure*()` | Is this write allowed *right now*? |

---

### Layer 1 — workspace

See [middleware.md](middleware.md). `abort_unless($user->isType($type), 404)`.

### Layer 2 — admin page access

**Gates.** Roles carry a JSON `gates` map keyed by navigation key; an individual
administrator may override single keys on their role assignment. Full treatment in
[gates.md](gates.md) — this is the short version.

One line in `mount()`, next to the title:

```php
public function mount(): void
{
    kSetSiteTitle('users', 'roles');
    kPageGate('users.roles');
}
```

```php
// app/Helpers/helper-functions.php
function kPageGate(string $resource, GateAccessEnum|string $required = GateAccessEnum::VIEW): void
{
    if (! request()->routeIs('admin.*')) {
        return;
    }

    abort_unless(kGate($resource, $required), 404);
}
```

The sidebar reads the **same** gate through `kGate()` inside
`kNavigationStrictAction()` — so a page a user cannot open is also a page they cannot
see, because both are asking one question of one stored value.

A 404 rather than a redirect-with-a-message, for the reason at the bottom of this file:
the shape of the admin surface stays private.

Access has a level as well as a yes/no, so the same gate answers the delete button too:

```php
kGate('content.blogs', GateAccessEnum::FULL)
```

### Layer 3 — record ownership

Plain `abort_unless` in `mount()` or at the top of the action. **Every time two
route-bound models appear together, their relationship is re-verified:**

```php
// pages/admin/training/⚡cohort-attendance.blade.php
abort_unless($this->cohort->is($this->classSession->cohort), 404);

// pages/trainer/⚡attendance.blade.php
abort_unless($this->cohort->is($this->classSession->cohort), 404);
abort_unless(array_key_exists($status, $this->statusOptions), 422);

// pages/user/training/⚡refund.blade.php
abort_unless((bool) $this->admission, 404);

// pages/user/account/⚡delete-account.blade.php
abort_unless($deletion, 404);
```

Ownership that needs a message rather than a 404 goes through a service:

```php
protected function cohortOwnership(Cohort $cohort, Model $model): void
{
    $checkOwnership = $this->serviceInstance()->ensureCohortOwnership($cohort, $model);
    $this->respondError($checkOwnership, \is_string($checkOwnership));
}
```

Scoped queries are the other half — a trainer's listing is built from
`TrainerService` scoped to `auth()->user()`, so an unowned record is never in the
result set to begin with.

### Layer 4 — action guards

**a) Model predicates** answer "is this allowed by the record's own state":

```php
public function isLocked(): bool
{
    return $this->status->isConcluded() || $this->status->isCancelled();
}

public function allowUpdate(): bool
{
    return ! $this->isLocked();
}

public function lockedReason(): string
{
    return "This cohort is {$this->status->label()} and can no longer be updated.";
}

public function canDelete(): bool { return $this->admissions()->count() === 0; }
public function canCancel(): bool { … }
public function canPause(): bool { … }
public function canEnroll(): bool { … }
```

**b) Trait `ensure*()` guards** turn a predicate into a refusal, and every mutating
method calls one **first**:

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

```php
public function save(): bool
{
    // Concluded and cancelled cohorts are a closed record.
    $this->ensureCohortIsEditable();

    $this->validate();
    …
}
```

**c) Service `*BlockedReason(): ?string`** — `null` means allowed, a string is the
reason. Used **twice**: once to render the disabled control with its explanation, once
to refuse the request.

```php
// building the UI
if ($entry['has']) {
    return [...$entry, 'action' => 'revoke', 'blocked' => $service->revokeBlockedReason($user, $role)];
}

// performing the action
$reason = $service->grantBlockedReason($user, $enum);
$this->respondError($reason ?? '', if: $reason !== null);
```

**d) UI reflection** — the same predicate drives the control:

```blade
@if (! $this->canUpdateCohort)
    <x-training.cohort-locked-callout :cohort="$cohort" />
@endif

<flux:button :disabled="! $this->canUpdateCohort" wire:click="save">Save</flux:button>
```

**The UI check is a courtesy. The method check is the security boundary.** Both are
required.

## Why

- Laravel Policies map cleanly to `Model` + `ability` + `User`, but this project's rules
  are mostly **record-state** rules ("a concluded cohort is frozen", "an invoice with
  admissions cannot be deleted"), not identity rules. Those belong on the model, where
  the state lives.
- The `?string` reason contract lets one sentence serve as both the disabled-button
  tooltip and the refusal toast — a Policy's `bool` return cannot carry that.
- Sharing one gate map between the page guard and the sidebar builder means a page can
  never be visible-but-forbidden or accessible-but-hidden.
- 404 rather than 403 keeps the shape of the admin surface private.

## Example

The cohort lock, end to end:

```php
// app/Models/Cohort.php
/**
 * A concluded or cancelled cohort is closed for good — its dates, schedule,
 * classes and staffing are a historical record and must not be edited.
 */
public function isLocked(): bool
{
    return $this->status->isConcluded() || $this->status->isCancelled();
}

public function allowUpdate(): bool
{
    return ! $this->isLocked();
}

public function lockedReason(): string
{
    return "This cohort is {$this->status->label()} and can no longer be updated.";
}
```

```php
// app/Traits/WithCohortAdmin.php
#[Computed]
public function canUpdateCohort(): bool
{
    return $this->cohort->allowUpdate();
}

protected function ensureCohortIsEditable(): void
{
    $this->respondError($this->cohort->lockedReason(), if: $this->cohort->isLocked());
}

public function statusAction(StatusCohort $status): bool
{
    // A concluded or cancelled cohort cannot be moved back into circulation.
    $this->ensureCohortIsEditable();
    …
}
```

```blade
{{-- every cohort tab --}}
@unless ($this->canUpdateCohort)
    <x-training.cohort-locked-callout :cohort="$cohort" />
@endunless
```

Tested in `tests/Feature/AdminCohortLockTest.php`.

## Template

```php
// 1. Model — the state rule
/**
 * A paid invoice is a settled record and must not be edited.
 */
public function isSettled(): bool
{
    return $this->status->isPaid();
}

public function allowUpdate(): bool
{
    return ! $this->isSettled();
}

public function lockedReason(): string
{
    return "This invoice is {$this->status->label()} and can no longer be updated.";
}

public function canDelete(): bool
{
    return $this->lines()->count() === 0;
}
```

```php
// 2. Trait — the guard every write calls first
protected function ensureInvoiceIsEditable(): void
{
    $this->respondError($this->invoice->lockedReason(), if: $this->invoice->isSettled());
}
```

```php
// 3. Service — the reason a specific action is unavailable
public function voidBlockedReason(Invoice $invoice): ?string
{
    if ($invoice->status->isPaid()) {
        return 'A paid invoice cannot be voided.';
    }

    if ($invoice->status->isVoid()) {
        return 'This invoice is already void.';
    }

    return null;
}
```

```php
// 4. Page — ownership, then the guard, then the work
public function mount(): void
{
    kSetSiteTitle('finance', 'invoices', $this->invoice->reference);

    abort_unless($this->invoice->user->is(auth()->user()), 404);
}

public function void(): bool
{
    $this->ensureInvoiceIsEditable();

    $reason = app(InvoiceService::class)->voidBlockedReason($this->invoice);
    $this->respondError($reason ?? '', if: $reason !== null);

    …

    return $this->respondSuccess('The invoice has been voided.');
}
```

## Avoid

- Creating `app/Policies/`, an `AuthServiceProvider`, `Gate::define()`,
  `$this->authorize()`, `can()` middleware, or `@can` / `@cannot` directives.
- Confusing `App\Models\Policy` (legal copy) with authorization.
- Relying on a hidden or disabled control as the only guard.
- `abort(403)` — this project returns 404 for wrong-workspace and wrong-record.
- Trusting a route-bound model's parent without `->is()` verification.
- Duplicating a lock rule in five pages instead of one `ensure*()` in a trait.
- A guard that returns `bool` where the UI needs to explain **why** — return `?string`.
- Checking an account type by string instead of `isType(UserTypeEnum::X)`.
