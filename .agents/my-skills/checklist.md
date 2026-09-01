# checklist.md — Pre-completion review gate

Walk this list before reporting any task complete. Every line must be a genuine ✓.
If a line does not apply, it is ✓ by default — but read it first.

---

## Architecture

- [ ] ✓ No new folder was created under `app/` (only the 13 existing ones)
- [ ] ✓ No `app/Actions`, `app/Repositories`, `app/Jobs`, `app/Events`, `app/Policies`,
      `app/Http/Requests`, `app/Livewire` was created
- [ ] ✓ Business logic lives in a Service, not in a controller or duplicated in a page
- [ ] ✓ Shared page behaviour lives in a `With*` trait, not copy-pasted
- [ ] ✓ Follows architecture — see [architecture.md](architecture.md)

## Reuse

- [ ] ✓ Uses existing services (`app(TheService::class)`), no `new Service()`
- [ ] ✓ Uses existing enums; no bare string/int status columns were introduced
- [ ] ✓ Uses existing traits (`WithFormResponseMessage`, `WithEnumHelpers`,
      `WithDynamicModelFormatting`, …)
- [ ] ✓ Uses existing UI components (`x-dashboard.*`, `x-form.*`, `x-status`, Flux)
      before creating a new one
- [ ] ✓ Uses existing `k*()` helpers for slugs, money, dates, files, pluralisation
- [ ] ✓ Uses existing layouts (`layouts::app` by default, `layouts::auth` for guests)
- [ ] ✓ No duplicated logic anywhere in the diff

## Naming

- [ ] ✓ Status enums prefixed `Status*`; other enums suffixed `*Enum`
- [ ] ✓ Services suffixed `*Service`; traits prefixed `With*`; rules suffixed `*Rule`
- [ ] ✓ Livewire page file is `⚡kebab-case.blade.php` in the right `pages/` subfolder
- [ ] ✓ Route name is dot-scoped and matches the page path
- [ ] ✓ Public Livewire props are `snake_case` when they map to DB columns,
      `camelCase` otherwise
- [ ] ✓ Methods are `camelCase` and descriptive (`toggleStatus`, not `toggle`)
- [ ] ✓ Table loop variable is `$item`; `wire:key="{singular}-{{ $item->id }}"`

## Code style

- [ ] ✓ Explicit return types on every method
- [ ] ✓ Type hints on every parameter
- [ ] ✓ Constructor property promotion where a constructor exists
- [ ] ✓ Curly braces on every control structure
- [ ] ✓ PHPDoc only where it adds information (array shapes, generics, the *why*)
- [ ] ✓ Comments explain **why**, in full sentences — no `// set the name`
- [ ] ✓ Blank line between every public property in a Livewire class
- [ ] ✓ 4-space indent, LF, final newline
- [ ] ✓ `vendor/bin/pint --dirty` has been run and is clean
- [ ] ✓ No `dd()`, `dump()`, `ray()`, `logger()->debug()` left behind

## Models & database

- [ ] ✓ Model has `#[Unguarded]`, `casts()` method, and `// Getters` /
      `// Relationships` / `// Scopes` section comments
- [ ] ✓ Scopes use `#[Scope] protected function name(Builder $query): void`
- [ ] ✓ Every status/type column is cast to an enum
- [ ] ✓ Money columns are `unsignedBigInteger` + `MoneyCast`
- [ ] ✓ Migration uses `foreignId()->constrained()->cascadeOnDelete()` (or an
      explicit, justified `nullOnDelete()`)
- [ ] ✓ Migration uses the `useCurrent()` / `useCurrentOnUpdate()` timestamp pair,
      not `$table->timestamps()`
- [ ] ✓ Enum defaults in migrations are written as the enum case, not a literal
- [ ] ✓ Columns that get filtered on are `->index()`ed

## Livewire pages

- [ ] ✓ Single-file component; `new class extends Component` … `};` `?>` then Blade
- [ ] ✓ `mount()` calls `kSetSiteTitle(...)`
- [ ] ✓ Derived data is `#[Computed]`, not assigned in `mount()`
- [ ] ✓ Computed caches are invalidated with `unset($this->name)` after writes
- [ ] ✓ Validation is `protected function rules(): array`
- [ ] ✓ Save path: `validate()` → fill → `respondPrimary(if: $model->isClean())` →
      `affectedColumns()` → `save()` → `logActivity()` → close modal → reset →
      `unset()` computed → `return $this->respondSuccess('…')`
- [ ] ✓ Write methods return `bool` and end in `return $this->respondSuccess(...)`
- [ ] ✓ Destructive actions carry `wire:confirm="…"`
- [ ] ✓ Root Blade element is a single `<div class="space-y-6">` (or a `<form>`)

## UI

- [ ] ✓ Flux components used before hand-rolled markup
- [ ] ✓ Every colour has a `dark:` counterpart
- [ ] ✓ Status badges use `<x-status :status="$item->status" />`
- [ ] ✓ Empty states use `<x-dashboard.workspace-no-record>` or the inline
      `colspan` row pattern
- [ ] ✓ Tables use `flux:table` / `flux:table.columns` / `flux:table.rows`
- [ ] ✓ Paginated tables pass `:paginate="$this->collection"`
- [ ] ✓ Internal links use `route()` + `wire:navigate`
- [ ] ✓ Tone palette is one of `lime` / `emerald` / `sky` / `amber` / `rose` / `slate`

## Authorization & safety

- [ ] ✓ The route sits in the correct role-scoped route file
- [ ] ✓ Cross-model ownership is re-checked server-side (`abort_unless(...)`)
- [ ] ✓ Locked records (`$cohort->isLocked()`) are refused in the **method**, not just
      hidden in the UI
- [ ] ✓ Multi-write operations are wrapped in `DB::transaction()`
- [ ] ✓ User-authored HTML is escaped (`MarkdownService`), never `{!! !!}` on raw input

## Audit & feedback

- [ ] ✓ Admin creates/updates/deletes are logged via `ActivityLogService`
- [ ] ✓ `affectedColumns()` is captured **before** `save()`
- [ ] ✓ A new `ActivityActionEnum` case was added if the action is new, with its
      `startDescription()` wired up
- [ ] ✓ The user gets feedback: toast for in-page, flash for redirects

## Tests

- [ ] ✓ A Pest feature test was added or updated
- [ ] ✓ `uses(RefreshDatabase::class)` is declared in the test file
- [ ] ✓ Livewire pages are tested via `Livewire::test('pages::…')`
- [ ] ✓ Test names read as sentences ("a hidden question leaves the landing page")
- [ ] ✓ `php artisan test --compact --filter=…` passes
- [ ] ✓ Pre-existing unrelated failures were **not** silently absorbed into the report

## Production readiness

- [ ] ✓ No N+1 queries — relations are eager-loaded (`with`, `withCount`, `withSum`)
- [ ] ✓ Nothing hard-codes `APP_URL`, a currency, or a site name — `kSiteConfig()`
- [ ] ✓ Error paths are handled and produce a human message, not an exception page
- [ ] ✓ Frontend change? The user was told to run `npm run dev` / `npm run build`
- [ ] ✓ Production ready
