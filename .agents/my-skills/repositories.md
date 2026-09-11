# repositories.md

## Rule

**This project does not use the repository pattern.** There is no `app/Repositories/`,
no repository interface, and no container binding of a data-access abstraction.

Data access is:

| Need | Where it lives |
| --- | --- |
| A query for one screen | a `#[Computed]` on the Livewire page |
| A reusable query fragment | a `#[Scope]` on the model |
| A query several screens share | a **getter method on a Service** |
| Cross-cutting text search | the `searchMacro` Builder macro |

Every query starts from `Model::query()`.

## Why

- Eloquent's query builder is already the data-access abstraction; a repository over it
  either leaks the builder (no isolation gained) or re-implements it (large surface, no
  benefit).
- This project has **no second data source** — no API-backed models, no read replica
  requiring a different client. The swap-ability a repository buys is not needed.
- `#[Scope]` methods give named, composable query fragments **on the model**, where the
  columns are, and they chain with everything else.
- Tests run against a real SQLite database with `RefreshDatabase`, so there is nothing
  to mock — which removes the other common reason to introduce repositories.

## Example

**Screen-specific query → a computed on the page:**

```php
#[Computed]
public function students()
{
    return User::query()
        ->users()
        ->with('role')
        ->withCount(['admissions as enrolled_count' => fn ($query) => $query->where('status', StatusAdmission::ENROLLED)])
        ->when($this->search !== '', fn ($query) => $query->searchMacro(['name', 'email', 'phone_number'], $this->search))
        ->latest()
        ->paginate(12);
}
```

**Reusable fragment → a scope on the model:**

```php
// app/Models/User.php
#[Scope]
protected function ofType(Builder $builder, UserTypeEnum $type): void
{
    $builder->where('type', $type);
}

/**
 * Admins who cannot reach the workspace: no role, or one that is switched off.
 * A real state — an account promoted before a role was picked, or a whole role
 * suspended — so the admins listing can call it out rather than show a blank.
 */
#[Scope]
protected function withoutLiveRole(Builder $builder): void
{
    $builder->where('type', UserTypeEnum::ADMIN)
        ->where(fn (Builder $query) => $query
            ->whereNull('role_id')
            ->orWhereHas('role', fn (Builder $role) => $role->where('status', StatusDefault::INACTIVE)));
}
```

```php
// app/Models/Faq.php
#[Scope]
protected function inFlowOrder(Builder $query): void
{
    $query->orderBy('flow_order')->orderBy('id');
}

#[Scope]
protected function active(Builder $query): void
{
    $query->where('status', StatusDefault::ACTIVE);
}
```

**Shared query → a service getter under a `// Getters` marker:**

```php
// app/Services/PolicyContentService.php
/**
 * The version of a policy currently in force, if any.
 */
public function getCurrent(PolicyTypeEnum $type): ?Policy
{
    return Policy::query()
        ->where('policy_type', $type)
        ->live()
        ->latest('effective_at')
        ->latest('id')
        ->first();
}
```

```php
// app/Services/NotificationService.php
/**
 * Active admin accounts, optionally without one of their own.
 *
 * @return Collection<int, User>
 */
public function admins(?User $except = null): Collection
{
    return User::query()
        ->admins()
        ->where('status', StatusUser::ACTIVE)
        ->when($except, fn ($query) => $query->whereKeyNot($except->getKey()))
        ->get();
}
```

```php
// app/Services/ActivityLogService.php
public function getActivityLogsForUser(User $user, ?array $columns = null, int $limit = 6)
{
    $columns ??= ['id', 'user_id', 'action', 'description', 'created_at'];

    return ActivityLog::query()
        ->with('user:id,name,avatar')
        ->select($columns)
        ->where('user_id', $user->id)
        ->latest()
        ->limit($limit)
        ->get();
}
```

**Paired query methods** — `AdminActionService` shares one builder between the listing
and the count, which is the project's answer to "the same query in two places":

```php
public function pendingCount(): int
{
    return $this->withdrawalQuery()->count()
        + $this->refundQuery(StatusTransaction::QUEUED)->count()
        + …;
}

private function withdrawalRequests(): array
{
    return $this->withdrawalQuery()
        ->with('user:id,name')
        ->oldest()
        ->limit(10)
        ->get()
        ->map(fn (Transaction $transaction) => […])
        ->all();
}
```

## Template

```
Where does this query go?

Used by one page only
└── #[Computed] on the page

A predicate or ordering reused across queries
└── #[Scope] on the model

The same full query needed by 2+ pages, or by a page and a command
└── a getter on the owning Service, under the "// Getters" marker,
    with a @return Collection<int, Model> docblock

Needed as both a listing and a count
└── a private *Query(): Builder on the service, called by both
```

```php
// app/Services/InvoiceService.php
#[Singleton]
class InvoiceService
{
    // Getters

    /**
     * Invoices a user still owes on, oldest first.
     *
     * @return Collection<int, Invoice>
     */
    public function outstandingFor(User $user): Collection
    {
        return $this->outstandingQuery($user)->oldest()->get();
    }

    public function outstandingCountFor(User $user): int
    {
        return $this->outstandingQuery($user)->count();
    }

    // Tools

    private function outstandingQuery(User $user): Builder
    {
        return Invoice::query()
            ->where('user_id', $user->id)
            ->where('status', StatusInvoice::ISSUED);
    }
}
```

## Avoid

- Creating `app/Repositories/`, `InvoiceRepositoryInterface`, or an
  `EloquentInvoiceRepository`.
- Binding a data-access interface in `AppServiceProvider`.
- A "DAO" or "query object" layer.
- Wrapping a single Eloquent call in a service method that adds nothing — put it in the
  page's computed.
- Mocking Eloquent in tests instead of using `RefreshDatabase`.
- `Model::where(...)` without `query()`.
- Duplicating the same non-trivial query in two places rather than lifting it to a
  scope or a service getter.
- Suggesting the repository pattern as an "improvement" while working on an unrelated
  task.
