# models.md

## Rule

Every model follows the same skeleton, in this exact order:

```php
<?php

namespace App\Models;

use ...;                                  // alphabetical

#[Unguarded]                              // always
class Thing extends Model
{
    use WithDynamicModelFormatting;       // almost always

    protected function casts(): array     // method, never a $casts property
    {
        return [ … ];
    }

    // Getters

    // Relationships

    // Scopes
}
```

### `#[Unguarded]`

Mass-assignment protection is switched off per model with the attribute. **No model in
this project declares `$fillable` or `$guarded`.**

```php
#[Unguarded]
class Cohort extends Model
```

`User` additionally hides sensitive attributes with an attribute:

```php
#[Unguarded]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
```

### `casts()`

Always the method form. Every status/type column casts to its enum. Money casts to
`MoneyCast`.

**A `json` column casts to `AsArrayObject::class`, never to `'array'` or `'json'`.**
`AsCollection::class` is the alternative where the value is a *list* the code wants to
`filter`/`map`/`pluck` over; everything else takes `AsArrayObject`. This project uses
`AsArrayObject` throughout — `ActivityLog::original`, `ActivityLog::changes`,
`UserProfile::settings`, `TransactionMeta::content`, `Role::gates`, `UserRole::gates`.

The reason is not style. `'array'` decodes to a **new array on every access**, so
writing into it silently does nothing:

```php
$model->options['theme'] = 'dark';   // 'array'          → discarded, model stays clean
$model->options['theme'] = 'dark';   // AsArrayObject    → written, model is dirty
```

There is no error and no warning. The value is simply not saved, which surfaces later
as a bug report about a setting that will not stick. `AsArrayObject` returns a mutable
object the model keeps hold of, so the write lands and `isDirty()` sees it.

```php
protected function casts(): array
{
    return [
        'fee' => MoneyCast::class,
        'compare_fee' => MoneyCast::class,
        'registration_starts_at' => 'datetime',
        'training_starts_at' => 'datetime',
        'community_links' => AsArrayObject::class,
        'is_online' => StatusYes::class,
        'status' => StatusCohort::class,
    ];
}
```

**Reading one back out.** The cast returns an `ArrayObject`, not an array, and that
difference bites in three places — spreading (`[...$model->options]`), `count()`, and
`empty()`/truthiness, where *any* object is truthy however little it holds. Where a
model's JSON is merged or counted in more than one place, give it a getter and convert
there once:

```php
// Getters

/**
 * The stored map as a plain array, for the callers that merge or count it.
 */
public function gatesArray(): array
{
    return $this->gates?->toArray() ?? [];
}
```

Assigning is unchanged — hand it a plain array and the cast encodes it.

`User` adds framework casts:

```php
'email_verified_at' => 'datetime',
'password' => 'hashed',
'last_seen_at' => 'datetime',
'status' => StatusUser::class,
```

### Getters

Small, pure, no writes, no mail, no cross-aggregate work. Booleans are
`is*`/`has*`/`can*`/`allow*`; values are plain nouns.

```php
public function isOnline(): bool
public function delivery(): string
public function displayName(): string
public function locationLabel(): string
public function isLocked(): bool
public function allowUpdate(): bool
public function lockedReason(): string
public function canDelete(): bool
public function canEnroll(): bool
public function progress(): int
public function daysRemaining(): ?int
public function showCompareFee(): bool
```

Getters carry a docblock when the rule they encode is a business decision:

```php
/**
 * A concluded or cancelled cohort is closed for good — its dates, schedule,
 * classes and staffing are a historical record and must not be edited.
 */
public function isLocked(): bool
{
    return $this->status->isConcluded() || $this->status->isCancelled();
}
```

### Relationships

Return types are always declared. Constrained relations narrow in the definition.

```php
public function training(): BelongsTo
{
    return $this->belongsTo(Training::class);
}

public function schedules(): HasMany
{
    return $this->hasMany(CohortSchedule::class);
}

/**
 * The admin role, or null. Members never have one.
 *
 * The explicit select() is load-bearing: GateService resolves access straight off
 * the loaded role, and a column left out of this list reads back as null.
 */
public function role(): BelongsTo
{
    return $this->belongsTo(Role::class)
        ->select('id', 'name', 'slug', 'gates', 'status', 'is_protected');
}

public function transactions(): HasMany
{
    return $this->hasMany(Transaction::class, 'transactionable_id', 'id')
        ->where('transactionable_type', self::class);
}
```

### Scopes — the `#[Scope]` attribute

Laravel 12+ attribute scopes. **Never** the `scopeFoo()` prefix convention.

```php
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;

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

`protected`, `void` return, `Builder $query` (or `$builder` — both appear; `$query`
is the majority). Call as `Faq::query()->inFlowOrder()->active()->get();`.

Scopes with arguments:

```php
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
    $builder->where('user_type', UserTypeEnum::ADMIN)
        ->whereDoesntHave('roles', fn (Builder $role) => $role->where('status', StatusDefault::ACTIVE));
}
```

Common scope names in the project: `active()`, `inFlowOrder()`, `isActive()`,
`admins()`, `members()`, `ofType()`, `withoutLiveRole()`, `holdingRole()`, `live()`.

Note `admins()` rather than `isAdmin()` on `User`: a scope and a getter of the same
name are two methods with one name, and PHP will not load the class at all.

### `WithDynamicModelFormatting`

Nearly every model uses it. It supplies magic accessors via `__call`:

| Call | Produces |
| --- | --- |
| `$model->amountMoney()` | `kMoneyFormat($model->amount)` → `&#8358;12,500` |
| `$model->countNumber()` | `number_format($model->count)` |
| `$model->avatarUrl()` | storage URL, or `kSafeImage()` fallback for image columns |
| `$model->createdAtHuman()` | `M jS, Y` |
| `$model->startsAtHumanDay()` | `D, jS M, Y` |
| `$model->startsAtDatetimeHuman()` | `M jS, Y • h:ia` |
| `$model->createdAtDiffForHumans()` | `2 hours ago` |
| `$model->pointsPv()` | `kPointFormat()` |
| `$model->startsAtDatetimeForUpdate()` | `Y-m-d\TH:i` for `<input type="datetime-local">` |
| `$model->startTimeForUpdate()` | `HH:MM` for `<input type="time">` |

Pass `true` as the first argument to render a date in the **authenticated user's**
timezone: `$model->createdAtHuman(true)`.

Use these instead of formatting in Blade.

### Query style

- Always start from `Model::query()`. `Faq::query()->where(...)`, never
  `Faq::where(...)`.
  (A handful of older seeders use `Role::updateOrCreate(...)`; new code uses
  `Role::query()->updateOrCreate(...)`.)
- Select only what is needed on listing queries:
  `Training::query()->select('id', 'name', 'slug', 'training_type', 'status')`.
- Eager-load with `with()`, count with `withCount()`, sum with `withSum()` — never a
  query inside a Blade loop.
- Constrain eager loads with closures:
  `->with(['topics' => fn ($query) => $query->active()->inFlowOrder()])`
- Conditional filters with `->when($condition, fn ($query) => …)`.
- Search with the `searchMacro` macro — see [search.md](search.md).
- `->latest()` for newest-first listings; `->oldest()` for queues.

### Caching on the model

Cache keys embed `updated_at` so an edit invalidates the entry with no observer:

```php
/**
 * The answer, compiled from markdown.
 *
 * Cached against the row's updated_at, so editing an answer changes the key and
 * the cache falls away on its own, with no observer to keep in sync.
 */
public function answerHtml(): string
{
    return Cache::rememberForever(
        "faq:{$this->id}:answer:{$this->updated_at?->getTimestamp()}",
        fn () => app(MarkdownService::class)->toHtml($this->answer)
    );
}
```

### Soft deletes

Only `User` uses `SoftDeletes`. Add it only when the record must survive deletion for
audit or restore, and add `$table->softDeletes()` to the migration.

### Slugs

Slugs are generated in the **page**, not the model, with `kSlug()`, and only when the
name they are derived from actually changed:

```php
// If the name changed, the slug has to follow it.
if ($this->category->isDirty('name')) {
    $this->category->slug = kSlug($this->name);
}
```

Route binding then uses `{training:slug}`. The column is `->unique()`.

The `isDirty()` guard is the point: rewriting the slug on every save churns the URL of
a record whose name nobody touched, and any link already pointing at it breaks.

A slug is **not a form field**. Nothing binds `wire:model` to it and nothing validates
it, unless a screen is deliberately built to let somebody choose their own — which is
the developer's call to make, not the default.

## Why

- `#[Unguarded]` + `casts()` keeps every model file short and uniform; the write path
  is guarded by explicit `rules()` in the page instead of a duplicate `$fillable`.
- `#[Scope]` attributes give IDE-visible, refactor-safe scopes without the `scope`
  prefix noise.
- `WithDynamicModelFormatting` means no model declares 15 accessor methods for money
  and dates, and every date in the app formats identically.
- Keeping business *decisions* (`isLocked()`, `canEnroll()`) on the model but business
  *processes* in services means a single source of truth for rules without models that
  send mail.

## Example

`app/Models/Faq.php` in full:

```php
<?php

namespace App\Models;

use App\Enums\FaqTypeEnum;
use App\Enums\StatusDefault;
use App\Services\MarkdownService;
use App\Traits\WithDynamicModelFormatting;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

#[Unguarded]
class Faq extends Model
{
    use WithDynamicModelFormatting;

    protected function casts(): array
    {
        return [
            'faq_type' => FaqTypeEnum::class,
            'status' => StatusDefault::class,
        ];
    }

    // Getters

    public function answerHtml(): string { … }

    public function label(): string
    {
        return Str::limit($this->question, 60);
    }

    // Scopes

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
}
```

## Template

```php
<?php

namespace App\Models;

use App\Casts\MoneyCast;
use App\Enums\StatusDefault;
use App\Traits\WithDynamicModelFormatting;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Unguarded]
class Invoice extends Model
{
    use WithDynamicModelFormatting;

    protected function casts(): array
    {
        return [
            'amount' => MoneyCast::class,
            'issued_at' => 'datetime',
            'status' => StatusInvoice::class,
        ];
    }

    // Getters

    /**
     * One sentence on the business rule this encodes.
     */
    public function isSettled(): bool
    {
        return $this->status->isPaid();
    }

    public function label(): string
    {
        return "Invoice {$this->reference}";
    }

    // Relationships

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(InvoiceLine::class);
    }

    // Scopes

    #[Scope]
    protected function active(Builder $query): void
    {
        $query->where('status', StatusInvoice::ISSUED);
    }

    #[Scope]
    protected function forUser(Builder $query, User $user): void
    {
        $query->where('user_id', $user->id);
    }
}
```

## Avoid

- `protected $fillable = [...]` / `protected $guarded = [...]` — use `#[Unguarded]`.
- `protected $casts = [...]` — use the `casts()` method.
- `public function scopeActive($query)` — use `#[Scope] protected function active()`.
- Accessors/mutators (`getFooAttribute`, `Attribute::make`) for money and dates —
  `WithDynamicModelFormatting` already covers them.
- Model observers or `booted()` hooks — none exist; do the work in the page or service.
- Sending mail, dispatching notifications, or writing to other aggregates from a model.
- `Model::where(...)` without `query()` in new code.
- Formatting inside Blade (`{{ number_format($m->amount / 100, 2) }}`) instead of
  `{!! $m->amountMoney() !!}`.
- `$model->relation()->count()` inside a loop — use `withCount()`.
- Adding `SoftDeletes` by default.
