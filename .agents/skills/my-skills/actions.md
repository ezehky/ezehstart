# actions.md

## Rule

**This project does not use Action classes.** There is no `app/Actions/` directory, no
single-method invokable command objects, and no `handle()`/`execute()` convention.

The equivalent responsibilities are distributed as follows:

| If the action is… | It goes in… |
| --- | --- |
| A single screen's write | a **method on the Livewire page** — `save()`, `delete()`, `toggleStatus()` |
| Shared by several pages | a **`With*` trait** — `WithCohortAdmin::statusAction()`, `WithUserRoleManager::grantRole()` |
| Domain logic, transactional, or cross-aggregate | a **Service method** — `TrainingService::requestRefund()`, `TransactionService::requestWithdrawal()` |
| Periodic / bulk | an **Artisan command** — `SendClassReminderCommand` |

## Why

- A Livewire page action already **is** a single-purpose object with typed input,
  validation, and a return contract. Wrapping it in a second class would add a hop
  without adding a boundary.
- The services here are cohesive by domain (`TrainingService` owns cohorts, admissions,
  payments and refunds together) because those operations share transactions, locks,
  and status rules. Splitting them into 40 Action classes would scatter one invariant
  across many files.
- Traits already give reuse where two pages need the same write, with the added ability
  to declare an overridable hook (`afterRoleChange()`) that an Action class cannot.

## Example

The write that an Action class would own, living on the page instead:

```php
// resources/views/pages/admin/configs/⚡faqs.blade.php
public function save(): bool
{
    $this->validate();

    $action = ActivityActionEnum::FAQ_UPDATE;

    if (! $this->faq) {
        $this->faq = Faq::make();
        $action = ActivityActionEnum::FAQ_CREATE;
    }

    $this->faq->fill([…]);

    $this->respondPrimary(if: $this->faq->isClean());

    $serviceInstance = app(ActivityLogService::class);
    $affectedColumns = $serviceInstance->affectedColumns($this->faq);

    $this->faq->save();

    $serviceInstance->logActivity($action, " FAQ: {$this->faq->label()}", $affectedColumns, model: $this->faq);

    Flux::modal('faqModal')->close();
    $this->resetForm();
    unset($this->grouped);

    return $this->respondSuccess('The question has been saved.');
}
```

The same write shared by two pages, living in a trait:

```php
// app/Traits/WithCohortAdmin.php
public function statusAction(StatusCohort $status): bool
{
    // A concluded or cancelled cohort cannot be moved back into circulation.
    $this->ensureCohortIsEditable();

    $this->cohort->status = $status;
    $this->cohort->save();

    app(TrainingService::class)->updateCohortStatus($this->cohort);

    app(ActivityLogService::class)->logActivity(
        ActivityActionEnum::COHORT_STATUS_CHANGE,
        "Cohort {$this->cohort->name} status changed to: {$this->cohort->status->label()}",
        model: $this->cohort,
        prefixDescription: false,
    );

    $this->cohort->refresh();

    unset($this->canUpdateCohort);

    return $this->respondSuccess('Cohort status updated successfully');
}
```

Domain logic with a transaction, living in a service:

```php
// app/Services/TransactionService.php
public function requestWithdrawal(
    User $user,
    TransactionWalletEnum $wallet,
    TransactionGroupEnum $group,
    float $amount,
    BankAccount $bankAccount,
    float $fee = 0
): string|Transaction {
    …
    $transaction = DB::transaction(function () use (…) {
        $user->lockForUpdate();
        $bankAccount->lockForUpdate();
        …
    });
    …
}
```

## Template

Decision procedure for any new write:

```
Is it used by exactly one page?
├── yes → a public method on that Livewire page (see forms.md)
└── no
    ├── Is it page behaviour (form state, modals, computed invalidation)?
    │   └── yes → a With* trait (see traits.md)
    └── Is it domain logic (transactions, money, cross-aggregate, external API)?
        └── yes → a Service method (see services.md)

Is it periodic or bulk?
└── yes → an Artisan command (see commands.md)
```

## Avoid

- Creating `app/Actions/`.
- Invokable single-method classes (`__invoke()` "action objects").
- A `handle()` or `execute()` convention.
- Command/handler or CQRS layering.
- A separate class per CRUD verb (`CreateFaq`, `UpdateFaq`, `DeleteFaq`).
- Splitting a cohesive service into one class per method.
- Suggesting this architecture as an "improvement" while working on an unrelated task.
