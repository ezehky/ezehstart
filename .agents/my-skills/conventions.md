# conventions.md

## Rule

### Formatting

From `.editorconfig` and Pint (default `laravel` preset, no `pint.json` override):

- 4-space indent, spaces not tabs
- LF line endings, UTF-8, final newline, no trailing whitespace
- 2-space indent in `.yml` / `.yaml`
- Run `vendor/bin/pint --dirty` after touching any PHP file. Never
  `pint --test`.

### Types

Every method declares an explicit return type. Every parameter is type-hinted.

```php
public function toggleStatus(Faq $faq): bool
protected function rules(): array
private function resetForm(): void
public function dateHuman(string $column = 'created_at', bool $user = false): ?string
public function requestWithdrawal(User $user, TransactionWalletEnum $wallet, float $amount): string|Transaction
```

Union returns are used deliberately where a method returns *either a failure message
or the result*: `string|Transaction`, `string|null|array`. See
[services.md](services.md).

Constructor property promotion, always:

```php
public function __construct(private ?User $user = null) {}

public function __construct(
    private readonly ?User $user = null,
    private readonly float|int $min = 0,
    private readonly bool $isRequired = true,
) {}
```

`readonly` on rule/value-object constructor properties; plain `private` on services
that mutate.

### Curly braces, always

```php
if (! $file) {
    return false;
}
```

Never a brace-less single-line `if`.

### Negation spacing

`! $value`, not `!$value`. This is Pint's Laravel preset and is used consistently.

### Fully-qualified core functions

Pint's Laravel preset applies `native_function_invocation`, so in-namespace calls to
certain PHP built-ins carry a leading backslash:

```php
if (\is_string($result)) {
if (\is_array($columns)) {
if (\in_array($column, ['avatar'])) {
$data['loggable_type'] = \get_class($model);
round(collect($fields)->filter()->count() / \count($fields) * 100);
```

Let Pint add these — write normally and run `pint --dirty`.

### PHPDoc

PHPDoc is used **only when it adds information the signature cannot carry**:

1. Array shapes and generics
2. The *why* behind a non-obvious method
3. Closure signatures in middleware

```php
/**
 * The roles this user actively carries, read from the loaded relation so a
 * listing can render them without a query per row.
 *
 * @return Collection<int, UserRoleEnum>
 */
public function activeRoles(): Collection

/**
 * @return Collection<int, array{topic: NotificationTopicEnum, message: string, url: string, since: Carbon|null}>
 */
public function pendingItems(int $limit = 6): Collection

/**
 * @param  Closure(Request): (Response)  $next
 */
public function handle(Request $request, Closure $next): Response

/**
 * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
 */
public function validate(string $attribute, mixed $value, Closure $fail): void
```

Helper functions in `app/Helpers/` get a **full** docblock with `@param` lines — they
are global and have no class context to explain them:

```php
/**
 * Get a safe image URL with fallback and optional storage or site URL prefix.
 *
 * @param  string|null  $name  Image path or URL from database.
 * @param  string|null  $altImage  Optional fallback type (e.g. 'user').
 * @return string A valid image URL (never broken).
 */
```

Livewire trait property docs use `@property-read`:

```php
/**
 * Drives the "manage roles" modal shared by the admins list and the user view.
 *
 * @property-read User|null $roleUser
 * @property-read array<int, array{role: string, label: string, has: bool}> $roleMatrix
 */
trait WithUserRoleManager
```

### Comments

Comments explain **decisions and consequences**, in full sentences, above the block.

Real examples:

```php
// BUILD ONCE PER REQUEST: the tree resolves ~60 routes, so memoize it.
static $construct = null;

// New questions go to the bottom of their group.
$this->flow_order = (int) Faq::query()->where('faq_type', $type)->max('flow_order') + 1;

// The default is the backing value, not the enum, so implicit binding resolves it.
Route::get('/'.$policyType->value, PolicyPageController::class)

// Users who never touched their settings have no row yet, so only an
// explicit opt-out excludes them.
->whereDoesntHave('notificationSubscriptions', …)

// One bad address must not stop the rest of the cohort.
} catch (\Throwable $exception) {
```

Short section markers inside long classes:

```php
    // Getters

    // Relationships

    // Scopes

    // Actions

    // Senders

    // Queues

    // Tools
```

Banner comments wrap a region that needs visual separation:

```php
    // ||||||||||||||||||||||||||||||||||||||||||||||||
    // COLOR

    …

    // COLOR
    // ||||||||||||||||||||||||||||||||||||||||||||||||
```

```php
        // ||||||||
        // Log Service
        $serviceInstance = app(ActivityLogService::class);
        $affectedColumns = $serviceInstance->affectedColumns($this->faq);
        // ||||||||
```

Uppercase inline markers appear in older helper/service code — match the surrounding
file if you are editing one:

```php
        // GENERATE NEW NAME
        // STORE FILE
        // RETURN REFERENCE ID
```

### Blank lines

- One blank line between every public property in a Livewire class — even
  single-line ones.
- One blank line before every `return`, unless the method body is a single line.
- One blank line between logical steps in a `save()` method.

```php
public ?Faq $faq = null;

public FaqTypeEnum $faq_type = FaqTypeEnum::GENERAL;

public string $question = '';

public string $answer = '';
```

### Imports

- Alphabetically sorted (Pint enforces `ordered_imports`).
- Import classes; never use fully-qualified class names inline (except `\Throwable`,
  `\InvalidArgumentException`, and the Pint-added `\is_string()` family).
- Group `use A, B;` for traits inside a class body:
  `use WithPagination, WithUserRoleManager;`

### Strings

- Single quotes by default.
- Double quotes only for interpolation: `" FAQ: {$this->faq->label()}"`.
- Always brace-wrap interpolated expressions: `"{$user->name}"`, not `"$user->name"`.
- Concatenate with `.` and no spaces around it in short expressions:
  `'/'.$policyType->value`, `route('home').'#organization'`.

### Arrays

- Short syntax `[]`, always.
- Trailing comma on multi-line arrays.
- Spread merging over `array_merge`: `[...$currentData, ...$rest]`,
  `[...$affectedColumns, ...$data]`, `[...$faq, 'flow_order' => $order + 1]`.

### Match over switch

`match` is the default for mapping. `switch` appears only in one older Blade component.

```php
return match ($role) {
    UserRoleEnum::STUDENT => $this->isStudent(),
    UserRoleEnum::TRAINER => $this->isTrainer(),
    UserRoleEnum::ADMIN => $this->isAdmin(),
};
```

Omit `default` when the match is exhaustive over an enum — PHP will then fail loudly
if a case is added later. Include `default` only when the input is open-ended.

### Blade

- `{{ }}` for text, `{!! !!}` only for content that is already-compiled HTML from
  `MarkdownService` or a component slot.
- `@php(...)` inline for one-liners; `@php … @endphp` blocks for setup at the top of a
  component.
- `@class([...])` and `$attributes->class([...])` for conditional classes.
- `@forelse` / `@empty` for every collection loop that can be empty.
- Attributes on their own line once an element exceeds ~100 characters.

## Why

- Explicit types and Pint keep diffs mechanical and reviewable.
- Comments-as-decisions means a future reader (human or agent) never has to
  reverse-engineer intent from the code; the banner style makes the audit-log and
  colour-mapping blocks findable by eye in long files.
- Blank lines between props keep the long Livewire property blocks scannable.
- Spread merging reads left-to-right as "defaults, then overrides".

## Example

A method that shows the whole convention set at once
(`resources/views/pages/admin/configs/⚡faqs.blade.php`):

```php
public function save(): bool
{
    $this->validate();

    $action = ActivityActionEnum::FAQ_UPDATE;

    if (! $this->faq) {
        $this->faq = Faq::make();
        $action = ActivityActionEnum::FAQ_CREATE;
    }

    $this->faq->fill([
        'faq_type' => $this->faq_type,
        'question' => $this->question,
        'answer' => $this->answer,
        'flow_order' => $this->flow_order,
        'status' => StatusDefault::tryFrom((int) $this->status),
    ]);

    // Check if is clean (no changes) and return early
    $this->respondPrimary(if: $this->faq->isClean());

    // ||||||||
    // Log Service
    $serviceInstance = app(ActivityLogService::class);
    $affectedColumns = $serviceInstance->affectedColumns($this->faq);
    // ||||||||

    $this->faq->save();

    $serviceInstance->logActivity(
        $action,
        " FAQ: {$this->faq->label()}",
        $affectedColumns,
        model: $this->faq,
    );

    Flux::modal('faqModal')->close();
    $this->resetForm();

    // Refresh the list
    unset($this->grouped);

    return $this->respondSuccess('The question has been saved.');
}
```

Note: **named arguments** are used freely for boolean and optional parameters —
`if:`, `model:`, `flash:`, `default:`, `format:`, `required:`, `size:`,
`prefixDescription:`, `navigate:`. This is a strong project habit; a bare `true`
argument is always suspect.

## Template

```php
    /**
     * One sentence saying why this exists, if it is not obvious from the name.
     *
     * @return array<int, array{key: string, label: string}>
     */
    public function doTheThing(Model $model, bool $force = false): array
    {
        if (! $model->allowUpdate()) {
            return [];
        }

        // Explain the non-obvious decision here, not the mechanics.
        $result = collect($model->children)
            ->filter(fn (Child $child) => $child->status->isActive())
            ->map(fn (Child $child) => ['key' => $child->slug, 'label' => $child->label()])
            ->values()
            ->all();

        return $result;
    }
```

## Avoid

- `// Set the name` / `// Loop through items` — mechanical comments.
- Docblocks that restate the signature (`@param User $user The user`).
- Missing return types.
- `!$x` without the space.
- `array_merge()` where a spread reads better.
- `switch` in new code.
- `else` after a `return` — this codebase returns early (with the exception of the
  three role middlewares, which mirror each other deliberately).
- Reformatting untouched lines. Pint `--dirty` only.
- Leaving `dd()`, `dump()`, `ray()`, or debug `logger()` calls in the diff.
