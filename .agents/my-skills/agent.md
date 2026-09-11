# agent.md — Master Instructions

**Read this file before generating any code for this project.**

You are writing code that must be indistinguishable from the existing codebase. The
original developer has a specific, consistent style. Your job is to disappear into it.

---

## 0. What this project is

| Thing | Value |
| --- | --- |
| Framework | Laravel 13 (`laravel/framework: ^13.17`) |
| PHP | 8.3+ (runtime 8.4) |
| UI layer | Livewire 4 — **single-file components only** |
| Component library | Flux UI **free** v2 (`livewire/flux`) |
| CSS | Tailwind CSS v4 (CSS-first config in `resources/css/app.css`) |
| JS | Alpine (bundled with Livewire), no other framework |
| Tests | Pest 5 |
| Formatter | Laravel Pint (default `laravel` preset) |
| Types | PHPStan / Larastan, level 1 — read the note in `phpstan.neon` before raising it |
| Auth extras | OTP email verification, passwordless sign-in, TOTP two-factor, social sign-in, password history, login throttle |

This is a **starter kit**, not a finished application. Two workspaces — **admin** and
**member** — plus the guest auth screens and a public blog.

The kit ships four feature areas beyond authentication, all of them things any project
would keep: an **image library** (folders, per-image visibility, a delete guard), a
**blog** (posts with a tiptap editor, polymorphic categories and tags), a
**transaction ledger** (derived balances, charges, evidence, an admin queue), and
**countries** as reference data. None of them are domain-specific.

> **The examples in this library come from the project the style was extracted from**,
> a cohort-based training platform. Cohorts, trainings, admissions and transactions
> appear throughout. They illustrate the *pattern*; none of those models exist here.
> Copy the shape, substitute your domain.

---

## 1. The ten laws

### 1. Never invent architecture
`app/` contains exactly these folders:

```
Casts  Console  Contracts  Enums  Helpers  Http  Mail  Models
Notifications  Providers  Rules  Services  Traits
```

There is **no** `app/Actions`, `app/Repositories`, `app/Jobs`, `app/Events`,
`app/Listeners`, `app/Policies`, `app/DTOs`, `app/Livewire`, `app/View/Components`,
`app/Observers`, `app/Http/Requests`, `app/Http/Resources`.

Do **not** create any of them. If you think you need one, you need a **Service**, a
**Trait**, or a **method on the Livewire page**. See [actions.md](actions.md),
[repositories.md](repositories.md), [jobs.md](jobs.md), [events.md](events.md),
[policies.md](policies.md) for what this project does instead.

### 2. Always reuse existing services
Before writing any business logic, check `app/Services/`. Eighteen ship with the kit:
`ActivityLogService`, `AdminActionService`, `UserService`, `RoleService`,
`NotificationService`, `SiteConfigurationService`, `MarkdownService`,
`PolicyContentService`, `AccountDeletionService`, `AccountOtpService`,
`EmailVerificationOtpService`, `PasswordlessOtpService`, `PasswordSecurityService`,
`TwoFactorService`, `SocialAccountService`, `ImageLibraryService`, `BlogService`,
`TransactionService`. Resolve with `app(TheService::class)` — never `new`.

### 3. Follow naming conventions exactly
See [naming.md](naming.md). The two rules people get wrong:
- Status enums are `StatusThing` (**prefix**): `StatusCohort`, `StatusUser`.
- Everything else is `ThingEnum` (**suffix**): `UserTypeEnum`, `TransactionTypeEnum`.

### 4. Match existing formatting
- 4-space indent, LF endings, UTF-8, final newline (`.editorconfig`).
- One blank line between every public property in a Livewire class.
- Explanatory comments above non-obvious blocks — this codebase is heavily commented,
  and the comments explain **why**, not what.
- Banner comments `// ||||||||||||||||||||` separate logical regions in long files.
- Run `vendor/bin/pint --dirty` after touching any PHP file.

### 5. Reuse traits
`app/Traits/` holds 7 `With*` traits. `WithFormResponseMessage` is used by nearly every
form page. `WithEnumHelpers` is used by **every** enum. `WithDynamicModelFormatting` is
used by almost every model. See [traits.md](traits.md). Never re-implement their
behaviour inline. A capability shared by two or more pages becomes the eighth.

### 6. Prefer existing components over creating new ones
Check `resources/views/components/` first: `dashboard/`, `form/`, `layouts/`, `lv/`,
plus the top-level `x-util.status`. Then check Flux (`flux:card`, `flux:table`,
`flux:modal`, `flux:input`, …). Only create a Blade component when a pattern is used in
**three or more** places.

### 7. Never duplicate business logic
If two pages need it → Service or Trait. If two enums need it → `WithEnumHelpers`.
If two models need it → `WithDynamicModelFormatting` or a shared method. If it formats
a string, a date, money, or a file path → it is a `k*()` helper, and one probably
already exists. See [helpers.md](helpers.md).

### 8. Keep consistency over cleverness
If the codebase does something in a slightly long-winded way, do it that way. Do not
"modernise", "optimise", or "clean up" surrounding code you were not asked to change.

### 9. Every change is tested
Pest feature tests, `Livewire::test('pages::admin.configs.faqs')`. See
[testing.md](testing.md). Run `php artisan test --compact --filter=...`.

### 10. Generate code that looks handwritten by the original developer
Voice check: comments are in plain sentences that explain a decision
("*A concluded or cancelled cohort is closed for good — its dates … must not be
edited.*"). Never generate `// Set the name` or `// Loop through the array`.

---

## 2. Hard bans

Never generate any of the following in this project:

| Banned | Use instead |
| --- | --- |
| `app/Livewire/*.php` class components | SFC at `resources/views/pages/**/⚡name.blade.php` |
| `Route::get(..., SomeLivewireClass::class)` | `Route::livewire('/path', 'pages::group.name')` |
| `render()` method in a Livewire component | The Blade below the `?>` in the SFC |
| `$rules` public array property | `protected function rules(): array` |
| `#[Validate]` attributes | `protected function rules(): array` |
| `$fillable` / `$guarded` | `#[Unguarded]` on the model |
| `protected $casts = [...]` | `protected function casts(): array` |
| `public function scopeActive($q)` | `#[Scope] protected function active(Builder $query): void` |
| `$table->timestamps()` | `useCurrent()` / `useCurrentOnUpdate()` pair |
| A column named `type`, `group`, `order`, `key`, `value`, `index`, `action` — any SQL keyword | `{singular}_type`, `{singular}_group` — prefix with the table's singular name |
| `Gate::`, `$this->authorize()`, `app/Policies` | Middleware + service guard + `abort_unless` |
| `session()->flash('success')` for form feedback | `$this->respondSuccess('…')` (Flux toast) |
| `dd()`, `dump()`, `ray()`, `var_dump()` | Remove before finishing |
| `new SomeService()` | `app(SomeService::class)` |
| Raw `number_format($money)` for currency | `kMoneyFormat()` / `->fooMoney()` |
| `Carbon::parse($x)->format(...)` in Blade | `->fooHuman()` / `kDatetimeConverter()` |
| `Str::slug()` | `kSlug()` |
| `@livewire('…')` | `<livewire:… />` or the page route |
| New base folders under `app/` | One of the 13 existing folders |
| New Composer/NPM dependencies | Ask first |
| Markdown docs alongside code | Only when explicitly requested |

---

## 3. The generation procedure

Follow this order every time.

1. **Locate the closest sibling.** Find the file in the project that does the most
   similar thing. A settings form? Open
   `resources/views/pages/admin/configs/⚡site-config.blade.php`. A listing with
   filters and pagination? Open `resources/views/pages/admin/users/⚡members.blade.php`.
   A single-record view? `⚡user-view.blade.php`. Copy its shape.
2. **Check for an existing enum** for any status/type/category field. Twenty-four ship
   with the kit; `StatusDefault` and `StatusYes` cover most on/off columns.
3. **Check for an existing service** for the business logic.
4. **Check for an existing trait** for shared page behaviour.
5. **Check for an existing Blade component** for the UI.
6. **Check for an existing `k*()` helper** for the formatting.
7. Write the code.
8. **Log the activity** if it is an admin write — `ActivityLogService`. See
   [activity-logging.md](activity-logging.md).
9. **Register the route** in the right file (`routes/admin.php`, `routes/user.php`,
   `routes/web.php`) and the **nav tree** in `app/Helpers/navigations.php` if it needs
   a sidebar entry.
10. **Write the Pest test.**
11. `vendor/bin/pint --dirty` then `php artisan test --compact --filter=…`.
12. Walk [checklist.md](checklist.md) before you report done.

---

## 4. Skill index

| Skill | Covers |
| --- | --- |
| [architecture.md](architecture.md) | Folder map, layering, request lifecycle |
| [naming.md](naming.md) | Every naming rule in the project |
| [conventions.md](conventions.md) | Formatting, comments, PHPDoc, types |
| [laravel.md](laravel.md) | Framework-level choices this project made |
| [livewire.md](livewire.md) | SFC anatomy, props, computed, lifecycle |
| [pages.md](pages.md) | Page templates: CRUD, index, settings, tab, dashboard |
| [routing.md](routing.md) | Route files, prefixes, binding, nav tree |
| [components.md](components.md) | Blade components, props, attribute merging |
| [layouts.md](layouts.md) | `layouts::app`, `layouts::auth`, base, email |
| [models.md](models.md) | Model anatomy, casts, scopes, relationships |
| [migrations.md](migrations.md) | Column style, enum defaults, timestamps |
| [database.md](database.md) | Keys, indexes, money, morphs, soft deletes |
| [enums.md](enums.md) | Enum template, `WithEnumHelpers`, colours |
| [services.md](services.md) | Service template, `#[Singleton]`, return style |
| [traits.md](traits.md) | Every trait and when to use it |
| [helpers.md](helpers.md) | Every `k*()` helper |
| [validation.md](validation.md) | `rules()`, custom Rule classes, messages |
| [forms.md](forms.md) | Form pages, save flow, `respond*()` |
| [ui.md](ui.md) | Flux usage, Tailwind patterns, tone palette, dark mode |
| [tables.md](tables.md) | `flux:table` structure, actions dropdown, empty state |
| [search.md](search.md) | `searchMacro` |
| [filters.md](filters.md) | `#[Url]` filters, `updatedX()`, reset |
| [pagination.md](pagination.md) | `WithPagination`, `:paginate` |
| [dashboard.md](dashboard.md) | Metric cards, charts, activity feeds |
| [file_uploads.md](file_uploads.md) | `WithFileUploads`, `ImageRule`, `kStoreFile` |
| [auth.md](auth.md) | Login, register, OTP, passwordless, socialite, roles |
| [middleware.md](middleware.md) | The three role middlewares |
| [policies.md](policies.md) | Authorization without Laravel Policies |
| [notifications.md](notifications.md) | Database notifications, toasts, admin actions |
| [mail.md](mail.md) | Mailables, email layout, `WithEmailResolver` |
| [commands.md](commands.md) | Artisan commands and the schedule |
| [activity-logging.md](activity-logging.md) | The audit trail, mandatory for admin writes |
| [factories.md](factories.md) | Factory conventions (and why there is only one) |
| [seeders.md](seeders.md) | Idempotent seeder template |
| [testing.md](testing.md) | Pest structure, Livewire testing |
| [actions.md](actions.md) | **Not used** — what to do instead |
| [repositories.md](repositories.md) | **Not used** — what to do instead |
| [jobs.md](jobs.md) | **Not used** — queued mail/notifications instead |
| [events.md](events.md) | **Not used** — Livewire + browser events instead |
| [templates.md](templates.md) | All copy-paste templates in one place |
| [checklist.md](checklist.md) | Pre-completion review gate |

---

## 5. When you are unsure

1. Grep the codebase for the closest analogue and copy it.
2. If two existing patterns conflict, prefer the one the shipped starter files use —
   `⚡members.blade.php` for a listing, `⚡user-view.blade.php` for a single record,
   `⚡site-config.blade.php` for a settings form.
3. If still unsure, ask. Do not invent.
