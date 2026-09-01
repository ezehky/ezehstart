# activity-logging.md

## Rule

**Every admin create, update, delete, and status change is logged** through
`ActivityLogService`. This is not optional — the audit trail is a product feature
(`/app-splash/activity-logs` and the per-cohort activity tab render it).

The sequence is fixed:

```php
// ||||||||
// Log Service
$serviceInstance = app(ActivityLogService::class);
$affectedColumns = $serviceInstance->affectedColumns($model);   // BEFORE save
// ||||||||

$model->save();

$serviceInstance->logActivity(
    $action,                       // ActivityActionEnum
    " thing: {$model->name}",      // note the LEADING SPACE
    $affectedColumns,
    model: $model,
);
```

**`affectedColumns()` must be called before `save()`.** After saving, the model is no
longer dirty and the before/after diff is gone.

### `affectedColumns()`

```php
public function affectedColumns(Model $model, array $exceptions = [], array $include = []): ?array
```

Returns `['original' => [...], 'changes' => [...]]`, or `null` when the model is clean
or new. Automatically excludes `id`, `password`, `kSlug`, `title_metaphone`,
`updated_at`, `created_at`, `slug`, `status`, plus anything in `$exceptions`.
String values are truncated to 1500 characters.

`status` is excluded by default because status changes get their own action
(`COHORT_STATUS_CHANGE`, `USER_STATUS_CHANGE`) with a written description. Pass it in
`$include` if a diff is genuinely wanted.

### `logActivity()`

```php
public function logActivity(
    ActivityActionEnum $action,
    ?string $description = null,
    ?array $affectedColumns = null,
    ?Model $model = null,
    ActivityPlatformEnum $platform = ActivityPlatformEnum::WEB,
    ?string $device = null,
    ?string $browser = null,
    ?string $os = null,
    ?string $userAgent = null,
    bool $prefixDescription = true
)
```

Behaviour:

- **No-ops when nobody is authenticated** (`if (! auth()->check()) return;`) — safe to
  call from a service that also runs in console context.
- Device, browser, and OS come from `jenssegers/agent`; IP from `request()->ip()`.
- `$model` populates `loggable_id` / `loggable_type` (the `nullableMorphs`).
- Description resolution:
  - `null` → `$action->defaultDescription()`
  - given, `prefixDescription: true` (default) → `$action->startDescription() . $description`
  - given, `prefixDescription: false` → used verbatim
- If the resolved description is empty, **nothing is logged**.

### The leading-space convention

Descriptions passed to `logActivity()` start with a space because
`startDescription()` supplies the verb:

```php
" FAQ: {$this->faq->label()}"        →  "Created FAQ: Do I need any design experience?"
" training: {$this->training->name}" →  "Updated training: Advanced Web Design"
```

`startDescription()` arms end with a trailing space (`'Created '`, `'Updated '`,
`'Deleted '`). Keep both halves consistent.

Verbatim descriptions pass `prefixDescription: false`:

```php
app(ActivityLogService::class)->logActivity(
    ActivityActionEnum::COHORT_STATUS_CHANGE,
    "Cohort {$this->cohort->name} status changed to: {$this->cohort->status->label()}",
    model: $this->cohort,
    prefixDescription: false,
);
```

### Adding a new action

Three edits in `app/Enums/ActivityActionEnum.php`:

1. Add the cases, dotted value, grouped by domain:

```php
// Invoice cases
case INVOICE_CREATE = 'invoice.create';
case INVOICE_UPDATE = 'invoice.update';
case INVOICE_DELETE = 'invoice.delete';
```

2. Add them to the matching `startDescription()` arm:

```php
self::CREATE,
self::USER_CREATE,
self::INVOICE_CREATE,
… => 'Created ',
```

3. Add a `defaultDescription()` arm **only** if the action has no subject
   (`LOGIN`, `LOGOUT`, `SESSION_LOGOUT_OTHERS`).

### Delete

There is no model to diff, so no `affectedColumns()`. Capture the description
**before** deleting:

```php
public function delete(Faq $faq): bool
{
    $description = " FAQ: {$faq->label()}";

    $faq->delete();

    app(ActivityLogService::class)->logActivity(ActivityActionEnum::FAQ_DELETE, $description);

    unset($this->grouped);

    return $this->respondSuccess('The question has been deleted.');
}
```

### Where logging happens

| Context | Who logs |
| --- | --- |
| Livewire admin page | the page's `save()` / `delete()` / `toggleStatus()` |
| Shared page behaviour | the trait (`WithCohortAdmin::statusAction()`) |
| Domain process | the service (`UserService::resetPassword()`, `TrainingService`) |
| Auth flow | `WithAuthWorker::logActivity()` shorthand |

`WithAuthWorker` provides a two-argument shorthand for actions with no model:

```php
protected function logActivity(ActivityActionEnum $action, string $description = ''): void
{
    app(ActivityLogService::class)->logActivity($action, $description);
}

// used as
$this->logActivity(ActivityActionEnum::REGISTER);
```

### Reading logs

```php
app(ActivityLogService::class)->getActivityLogsForUser($user, limit: 6);
```

Selects a narrow column set and eager-loads `user:id,name,avatar`.

### What is NOT logged

- Reads.
- Student/trainer self-service that already has its own record (an admission, a
  transaction) — those *are* the audit trail.
- No-op saves — `respondPrimary(if: $model->isClean())` fires before the log block for
  exactly this reason.

## Why

- The dirty-diff is only available before `save()`; capturing it after would silently
  log nothing.
- Excluding `status` from the diff avoids two competing records of the same change —
  the dedicated status action carries a written sentence instead.
- Verb-prefix + subject means a log line reads as a sentence with no per-call
  duplication of the verb, and the verb can be corrected in one place.
- No-op'ing without an authenticated user lets the same service be called from console
  commands and seeders without a guard at every call site.
- Storing `loggable_type`/`loggable_id` lets the cohort activity tab filter the global
  log down to one record.

## Example

`⚡faqs.blade.php::toggleStatus()` — the complete shape for a status flip:

```php
/**
 * Show or hide a question without deleting it.
 */
public function toggleStatus(Faq $faq): bool
{
    $faq->status = $faq->status->isActive() ? StatusDefault::INACTIVE : StatusDefault::ACTIVE;

    $serviceInstance = app(ActivityLogService::class);
    $affectedColumns = $serviceInstance->affectedColumns($faq);

    $faq->save();

    $serviceInstance->logActivity(
        ActivityActionEnum::FAQ_UPDATE,
        " FAQ: {$faq->label()}",
        $affectedColumns,
        model: $faq,
    );

    unset($this->grouped);

    return $this->respondSuccess(
        $faq->status->isActive()
            ? 'The question is now shown on the site.'
            : 'The question is now hidden from the site.'
    );
}
```

## Template

```php
public function save(): bool
{
    $this->validate();

    $action = ActivityActionEnum::INVOICE_UPDATE;

    if (! $this->invoice) {
        $this->invoice = Invoice::make();
        $action = ActivityActionEnum::INVOICE_CREATE;
    }

    $this->invoice->fill([
        'title' => $this->title,
        'amount' => $this->amount,
        'channel' => $this->channel,
        'status' => StatusInvoice::tryFrom((int) $this->status),
    ]);

    // Check if is clean (no changes) and return early
    $this->respondPrimary(if: $this->invoice->isClean());

    // ||||||||
    // Log Service
    $serviceInstance = app(ActivityLogService::class);
    $affectedColumns = $serviceInstance->affectedColumns($this->invoice);
    // ||||||||

    $this->invoice->save();

    $serviceInstance->logActivity(
        $action,
        " invoice: {$this->invoice->reference}",
        $affectedColumns,
        model: $this->invoice,
    );

    Flux::modal('invoiceModal')->close();
    $this->resetForm();

    unset($this->invoices);

    return $this->respondSuccess('The invoice has been saved.');
}
```

## Avoid

- An admin write with no log entry.
- Calling `affectedColumns()` after `save()`.
- Omitting the leading space in the description (`"FAQ: …"` renders as
  `"CreatedFAQ: …"`).
- Using a generic `ActivityActionEnum::UPDATE` when a domain-specific case exists or
  should be added.
- Adding an enum case without wiring `startDescription()`.
- Logging inside the `if ($isClean)` path.
- Passing the password, token, or full user-agent into the diff.
- Instantiating `new ActivityLogService()`.
- Logging reads.
- Duplicating a log entry in both the page and the service it calls — pick one, and it
  is the one closest to the write.
