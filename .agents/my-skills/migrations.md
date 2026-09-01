# migrations.md

## Rule

Anonymous class migrations. Enum defaults written as **enum cases**. Timestamps written
explicitly with `useCurrent()` / `useCurrentOnUpdate()` — **never `$table->timestamps()`**.

```php
<?php

use App\Enums\StatusCohort;
use App\Enums\StatusYes;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('cohorts', function (Blueprint $table) {
            $table->id();
            …
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('cohorts');
    }
};
```

The two docblocks (`Run the migrations.` / `Reverse the migrations.`) are present in
every migration — keep them.

### Column order

1. `$table->id();`
2. Foreign keys
3. Identity columns (`name`, `slug`, `reference`)
4. Money / numerics
5. Dates / windows
6. Text / long content
7. Optional details
8. Enum flags and `status` (always last before timestamps)
9. Timestamps, then `softDeletes()` if used

### Timestamps

```php
$table->timestamp('created_at')->useCurrent();
$table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();
```

Log-style tables that never update carry only `created_at`:

```php
$table->timestamp('created_at')->useCurrent();
```

### Foreign keys

```php
$table->foreignId('training_id')->constrained()->cascadeOnDelete();
$table->foreignId('user_id')->constrained()->cascadeOnDelete();
$table->foreignId('country_id')->nullable()->constrained()->nullOnDelete();
$table->foreignId('transaction_id')->nullable()->constrained()->nullOnDelete();
```

Default is **cascade**. Use `nullOnDelete()` only when the row must outlive its parent
(profile location, an admission whose transaction was purged).

Polymorphic:

```php
$table->nullableMorphs('transactionable');
$table->nullableMorphs('loggable');
```

### Enum columns

**Int-backed enums** → `tinyInteger`, default written as the case:

```php
$table->tinyInteger('status')->default(StatusCohort::CREATED);
$table->tinyInteger('status')->default(StatusUser::ACTIVE);
$table->boolean('status')->default(StatusDefault::ACTIVE);
$table->boolean('is_online')->default(StatusYes::YES);
```

`boolean` is used where the enum has exactly two int cases (`StatusDefault`,
`StatusYes`); `tinyInteger` for anything wider.

**String-backed enums** → sized `string`, `->index()` when it is filtered on:

```php
$table->string('transaction_type', 20)->default(TransactionTypeEnum::CREDIT)->index();
$table->string('transaction_group', 100)->index();          // TransactionGroupEnum
$table->string('transaction_wallet', 50)->default(TransactionWalletEnum::BALANCE)->index();
$table->string('via', 50)->default(TransactionViaEnum::PLATFORM)->index();
$table->string('faq_type')->default(FaqTypeEnum::GENERAL)->index();
```

When the column stores an enum but has no default, note the enum in a trailing comment:
`// TransactionGroupEnum`.

Never `$table->enum('status', ['a', 'b'])` — the database enum type is not used
anywhere in this project.

### Money

Stored as **minor units** in `unsignedBigInteger`, read through `MoneyCast`.

```php
$table->unsignedBigInteger('fee');
$table->unsignedBigInteger('compare_fee')->default(0);
$table->unsignedBigInteger('amount');
```

### Strings

Size the column when a natural bound exists:

```php
$table->string('email', 50)->unique();
$table->string('phone_number', 20)->nullable();
$table->string('reference', 200)->unique();
$table->string('ip_address', 45)->nullable();
$table->string('transaction_type', 20);
```

Unsized `string()` (255) for names, titles, paths, and links.

### Ordering

```php
$table->unsignedInteger('flow_order')->default(0);
```

### Other column types in use

```php
$table->id();
$table->slug — n/a: use $table->string('slug')->unique();
$table->text('description')->nullable();
$table->longText('payload');
$table->json('community_links')->nullable();
$table->ipAddress()->nullable();
$table->rememberToken();
$table->softDeletes();
$table->timestamp('email_verified_at')->nullable();
```

### Altering a table

Separate migration, descriptive name, real `down()`:

```
2026_07_31_090000_change_faqs_answer_to_text.php
```

### Indexes

Index every column that appears in a `where`, `orderBy`, or filter:
enum/type columns, foreign keys (automatic with `constrained()`), `last_activity`.
Composite uniqueness for "once per parent" rules:

```php
$table->unique(['class_session_id', 'reminder']);
```

That index is load-bearing — `ClassSessionReminder::insertOrIgnore()` relies on it so
two overlapping scheduler runs cannot both send the same reminder.

## Why

- `useCurrent()`/`useCurrentOnUpdate()` puts the timestamp default in the **database**,
  so rows inserted outside Eloquent (`insertOrIgnore`, raw seeds, imports) still get
  correct timestamps.
- Enum-case defaults mean renumbering an enum is a single-file change and the migration
  cannot drift from the code.
- Minor-unit money in `unsignedBigInteger` avoids float rounding entirely; `MoneyCast`
  makes it invisible to application code.
- No database `enum` type: adding a case would need a schema change on MySQL, and the
  PHP enum is already the source of truth.

## Example

`database/migrations/2026_07_15_025859_create_transactions_table.php`:

```php
Schema::create('transactions', function (Blueprint $table) {
    $table->id();
    $table->foreignId('user_id')->constrained()->cascadeOnDelete();
    $table->string('reference', 200)->unique();

    $table->string('transaction_type', 20)->default(TransactionTypeEnum::CREDIT)->index();
    $table->string('transaction_group', 100)->index(); // TransactionGroupEnum
    $table->string('transaction_wallet', 50)->default(TransactionWalletEnum::BALANCE)->index();

    $table->unsignedBigInteger('amount');
    $table->string('description');
    $table->string('via', 50)->default(TransactionViaEnum::PLATFORM)->index();
    $table->nullableMorphs('transactionable');

    $table->tinyInteger('status')->default(StatusTransaction::CONFIRMED);

    $table->timestamp('created_at')->useCurrent();
    $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();
});
```

## Template

```php
<?php

use App\Enums\InvoiceChannelEnum;
use App\Enums\StatusInvoice;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('cohort_id')->nullable()->constrained()->nullOnDelete();

            $table->string('reference', 200)->unique();
            $table->string('title');

            $table->unsignedBigInteger('amount');

            $table->timestamp('issued_at')->nullable();
            $table->timestamp('due_at')->nullable();

            $table->text('notes')->nullable();

            $table->string('channel', 30)->default(InvoiceChannelEnum::EMAIL)->index();
            $table->tinyInteger('status')->default(StatusInvoice::DRAFT)->index();

            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('invoices');
    }
};
```

Create with `php artisan make:migration create_invoices_table --no-interaction`, then
rewrite the body to match.

## Avoid

- `$table->timestamps()`.
- `$table->enum('status', [...])`.
- `->default(1)` / `->default('credit')` — write the enum case.
- `decimal`/`float` for money.
- `$table->uuid()` / `HasUuids` — this project uses auto-increment `id()` everywhere.
  Public identifiers are human `reference` strings from `kReferenceId()` and `slug`s
  from `kSlug()`.
- Unindexed status/type columns that the admin filters on.
- Editing an existing migration that has shipped — write a new one.
- An empty or `//` `down()` method.
- `Schema::table()` inside a `create` migration file.
