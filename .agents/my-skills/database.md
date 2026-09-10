# database.md

## Rule

### Keys

- **Auto-increment `$table->id()` on every table.** There is no UUID or ULID anywhere.
- Public identifiers are human strings, not keys:
  - `slug` (unique) — `kSlug($name)`, used for `{cohort:slug}` route binding
  - `reference` (unique, 200) — `kReferenceId('WTH-')`, used for
    `{transaction:reference}` binding
- Composite unique constraints express "once per parent":
  `$table->unique(['class_session_id', 'reminder']);`
- The only string primary key is `password_reset_tokens.email` (framework default).

### Foreign keys

```php
$table->foreignId('user_id')->constrained()->cascadeOnDelete();          // default
$table->foreignId('country_id')->nullable()->constrained()->nullOnDelete();
$table->foreignId('transaction_id')->nullable()->constrained()->nullOnDelete();
```

`cascadeOnDelete()` is the default. `nullOnDelete()` when the child must outlive the
parent — a user's profile keeps its city after a country row is removed; an admission
keeps its history after its transaction is purged.

Polymorphic:

```php
$table->nullableMorphs('transactionable');   // transactions → cohorts, bank accounts
$table->nullableMorphs('loggable');          // activity_logs → any model
```

Read back with:

```php
$transaction->transactionable;

public function transactions(): HasMany
{
    return $this->hasMany(Transaction::class, 'transactionable_id', 'id')
        ->where('transactionable_type', self::class);
}
```

### Indexes

Index anything filtered, sorted, or joined on:

- every enum/type column the admin filters by — `transaction_type`,
  `transaction_group`, `transaction_wallet`, `via`, `faq_type`
- `sessions.last_activity`
- composite uniques that guard concurrency

Two rules about **how** to declare one:

- **On the column, not as a separate statement** — `$table->string('slug')->unique()`,
  never `$table->unique(['slug'])`. The array form is for composites; on one column it
  builds the same index while reading like an unfinished composite key.
- **Foreign keys are already indexed.** `constrained()` creates the index with the
  constraint. Adding `$table->index('user_id')` next to it is a duplicate index the
  database maintains on every insert, update and delete for nothing.

See [migrations.md](migrations.md) for the examples.

### Timestamps

Explicit, never `$table->timestamps()`:

```php
$table->timestamp('created_at')->useCurrent();
$table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();
```

Append-only log tables carry only `created_at` (`activity_logs`, `affiliate_clicks`).

Domain timestamps are `*_at`, nullable when the event may not have happened:
`email_verified_at`, `last_seen_at`, `registration_starts_at`, `training_ends_at`,
`accepted_at`, `effective_at`, `sent_at`, `issued_at`.

### Money

**Minor units in `unsignedBigInteger`.** Never `decimal`, never `float`.

```php
$table->unsignedBigInteger('fee');
$table->unsignedBigInteger('amount');
$table->unsignedBigInteger('compare_fee')->default(0);
```

`MoneyCast` divides by 100 on read and multiplies on write, so application code always
sees major units:

```php
protected function casts(): array
{
    return ['fee' => MoneyCast::class];
}
```

Consequence: a raw `sum('amount')` returns minor units, so aggregate queries divide
explicitly:

```php
$revenue = (int) Transaction::query()->…->sum('amount') / 100;
{!! kMoneyFormat(($item->amount_paid_sum ?? 0) / 100) !!}
```

A cast attribute (`$cohort->fee`) is already major units — do **not** divide again.

### Status and enum columns

`status` is the last column before timestamps on every table.

```php
$table->tinyInteger('status')->default(StatusCohort::CREATED);   // wide int enum
$table->boolean('status')->default(StatusDefault::ACTIVE);       // two-case int enum
$table->boolean('is_online')->default(StatusYes::YES);
$table->string('faq_type')->default(FaqTypeEnum::GENERAL)->index();
```

Never the MySQL `enum` type.

### JSON

```php
$table->json('community_links')->nullable();     // AsArrayObject
$table->json('settings')->nullable();            // user profile preferences
$table->json('gates')->nullable();               // role / assignment access maps
$table->json('original'); $table->json('changes'); // activity log diffs
$table->json('content');                         // transaction meta
```

**Every one of these casts to `AsArrayObject::class`** — or `AsCollection::class` where
the value is a list the code wants to `filter`/`map`/`pluck`. Never `'array'` or
`'json'`: those decode to a fresh array on each access, so writing into one is
discarded without an error and the model never goes dirty. Full reasoning in
[models.md](models.md).

Assign a plain array; the cast encodes it. Read it back with the model's `*Array()`
getter wherever it is merged, counted, or spread — an `ArrayObject` is not an array and
is truthy even when empty:

```php
$settings = $profile->settingsArray();
$settings[$key] ??= $value;
$profile->settings = $settings;
$profile->save();
```

A single nested key can also be written straight through, which is what the cast is
for:

```php
$profile->settings[$key] = $value;
$profile->save();
```

### Soft deletes

Only `users` (`$table->softDeletes()` + `use SoftDeletes`). Add it only where restore or
audit genuinely requires it — the project's preferred pattern is a **status enum**
(`INACTIVE`, `CANCELLED`, `VOID`) rather than a delete flag.

### Slugs

Generated in the page on save, never by an observer, and **guarded by `isDirty()` so
an existing record keeps the slug its links already point at**:

```php
// If the name changed, the slug has to follow it.
if ($this->category->isDirty('name')) {
    $this->category->slug = kSlug($this->name);
}

$this->category->save();
```

Column: `$table->string('slug')->unique();`

**A slug is never a form field** unless the screen is deliberately offering to let
somebody choose one. It is derived from the name, and a second input that has to agree
with the first is a way to get them out of step. See [forms.md](forms.md).

### Transactions and locking

Multi-table writes are wrapped; balance writes lock:

```php
DB::transaction(function () use ($user, $bankAccount) {
    // Lock the user row to prevent race conditions
    $user->lockForUpdate();
    $bankAccount->lockForUpdate();
    …
    return $transaction;
});

DB::transaction(function () use (…) { … }, 3);   // retries where contention is expected
```

Mail and notifications go **after** the commit (or via `$this->afterCommit()` in the
Mailable constructor).

Concurrency-safe insert, backed by the unique index:

```php
return ClassSessionReminder::insertOrIgnore([
    'class_session_id' => $session->id,
    'reminder' => $reminder->value,
    'sent_at' => now(),
]) > 0;
```

### Cross-driver SQL

SQLite in tests, MySQL/PostgreSQL in production. Branch explicitly:

```php
$this->monthExpression = match (DB::connection()->getDriverName()) {
    'sqlite' => "strftime('%Y-%m', created_at)",
    'pgsql' => "to_char(created_at, 'YYYY-MM')",
    default => "date_format(created_at, '%Y-%m')",
};
```

Bind parameters in `orderByRaw`:

```php
->orderByRaw('CASE WHEN status = ? THEN 0 ELSE 1 END', [StatusCohort::ADMITTING->value])
```

### Query performance

- `select()` only the needed columns on listings
- `with()` / `withCount()` / `withSum()` — never a query in a loop
- Constrain eager loads with closures
- Subqueries via `whereIn(..., Builder)` rather than `pluck()` round-trips:

```php
User::query()->whereIn('id', Admission::query()
    ->where('cohort_id', $cohortId)
    ->where('status', StatusAdmission::ENROLLED)
    ->select('user_id'));
```

### Schema reference

`database/tables.md` holds a generated snapshot of every table, its columns, and its
foreign keys with `ON DELETE` actions. **Read it before writing a migration** — and
regenerate/extend it when the schema changes materially.

## Why

- Auto-increment ids keep indexes small and joins fast; slugs and references give users
  something stable and readable without exposing row counts.
- Minor-unit integers make money arithmetic exact — no float drift, no `decimal`
  rounding surprises across drivers.
- Database-level timestamp defaults keep rows inserted outside Eloquent
  (`insertOrIgnore`, imports) correct.
- `insertOrIgnore` + a unique index is the reminder scheduler's whole concurrency
  strategy: two overlapping runs cannot both send.
- Preferring status enums over soft deletes keeps every row visible to the audit trail
  and to foreign keys.

## Example

`class_session_reminders` — the concurrency pattern end to end:

```php
// migration
$table->unique(['class_session_id', 'reminder']);

// command
/**
 * Reserve this reminder before mailing. The unique index on
 * (class_session_id, reminder) means two overlapping runs cannot both win,
 * so nobody gets the same reminder twice.
 */
protected function claim(ClassSession $session, ClassReminderEnum $reminder): bool
{
    return ClassSessionReminder::insertOrIgnore([
        'class_session_id' => $session->id,
        'reminder' => $reminder->value,
        'sent_at' => now(),
    ]) > 0;
}
```

## Template

```php
Schema::create('invoices', function (Blueprint $table) {
    $table->id();

    $table->foreignId('user_id')->constrained()->cascadeOnDelete();
    $table->foreignId('cohort_id')->nullable()->constrained()->nullOnDelete();

    $table->string('reference', 200)->unique();
    $table->string('title');

    $table->unsignedBigInteger('amount');

    $table->timestamp('issued_at')->nullable();
    $table->timestamp('due_at')->nullable();

    $table->json('meta')->nullable();

    $table->string('channel', 30)->default(InvoiceChannelEnum::EMAIL)->index();
    $table->tinyInteger('status')->default(StatusInvoice::DRAFT)->index();

    $table->timestamp('created_at')->useCurrent();
    $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();

    $table->unique(['user_id', 'reference']);
});
```

## Avoid

- UUID / ULID primary keys, `HasUuids`.
- `decimal` or `float` for money.
- `$table->timestamps()`.
- `$table->enum(...)`.
- Dividing an already-cast money attribute by 100 again.
- Forgetting to divide a raw `sum('amount')`.
- Unindexed columns used in admin filters.
- Multi-table writes outside a transaction; balance writes without `lockForUpdate()`.
- Sending mail inside a transaction closure.
- Raw SQL that only works on one driver — the test suite runs SQLite.
- `pluck()` then `whereIn($ids)` where a subquery builder works.
- `$model->relation()->count()` inside a loop.
- Soft deletes as a default.
- Editing a shipped migration instead of writing a new one.
