# CLAUDE.md

A Laravel 13 + Livewire 4 starter kit carrying a specific house style. Read the style
before writing code, not after.

## Read first

| File | Why |
| --- | --- |
| `.agents/my-skills/agent.md` | **Before generating any code.** The ten laws, the hard bans, the generation procedure |
| `.agents/my-skills/README.md` | The index — maps a task to the file that covers it |
| `.agents/my-skills/checklist.md` | **Before reporting done.** The review gate |
| `AGENTS.md` | Laravel Boost guidelines — authoritative on **framework** usage |

Where the two overlap, `.agents/my-skills/` wins: it describes this project, Boost
describes Laravel. The `house-style` skill in `.claude/skills/` routes into the library
and activates on its own.

## What this is

A starter kit, not an application. It ships authentication, roles, two workspaces, and
account management — and nothing domain-specific. Every model, enum, service and page
here is one you would keep in any project.

| | |
| --- | --- |
| Framework | Laravel 13, PHP 8.3+ |
| UI | Livewire 4 — **single-file components only** (`⚡name.blade.php`) |
| Components | Flux UI free v2 |
| CSS | Tailwind v4, CSS-first config, lime accent, `zinc`→`slate` remap |
| JS | Alpine (bundled with Livewire) |
| Tests | Pest 5 |
| Format | Laravel Pint, default preset |
| Types | PHPStan level 1 (see the note in `phpstan.neon` before raising it) |

Two workspaces, defined in `bootstrap/app.php`:

| Workspace | URL prefix | Route name | Route file | Middleware |
| --- | --- | --- | --- | --- |
| Admin | `/app-splash` | `admin.` | `routes/admin.php` | `AdminMiddleware` |
| Member | `/app` | `user.` | `routes/user.php` | `UserMiddleware` |
| Public + auth | `/` | *(none)* | `routes/web.php` | `guest` / `auth` |

Adding a third is three files: a `{Role}Middleware`, a `routes/{role}.php`, and a group
in `bootstrap/app.php`. Add the case to `UserRoleEnum` and the branch tables in
`UserService::middlewareGeneralCheck()` and `WithAuthWorker::userDashboardRedirect()`
at the same time — both `match` on every case and will fail loudly until you do.

## Commands

```bash
composer setup                       # install, key, migrate, npm, build
composer dev                         # server + queue + vite together
php artisan test --compact           # the whole suite
php artisan test --compact --filter=X
vendor/bin/pint --dirty              # after touching any PHP file
vendor/bin/phpstan analyse --memory-limit=1G
```

The seeded admin is `admin@example.test` / `password` (`database/seeders/UserSeeder.php`).
Change it before the kit becomes a real project.

## The rules people break most

- **No new base folders under `app/`.** There are 13 and no `Actions`, `Repositories`,
  `Jobs`, `Events`, `Policies`, `Observers`, `Http/Requests`, or `Livewire`. See
  `.agents/my-skills/actions.md`, `repositories.md`, `jobs.md`, `events.md`,
  `policies.md` for what to do instead.
- **No class-based Livewire components.** Screens are SFCs under
  `resources/views/pages/`, routed with `Route::livewire('/path', 'pages::group.name')`.
- **Validation lives in `protected function rules(): array`** — never `#[Validate]`,
  never a `$rules` property.
- **Models are `#[Unguarded]`** with `casts()` and `#[Scope]`, never `$fillable` or
  `scopeFoo()`.
- **Every admin write is logged.** Call `ActivityLogService::affectedColumns()` *before*
  `save()`, then `logActivity()` after.
- **Services are `#[Singleton]`** and resolved with `app()`, never `new`.
- **Ask before adding a dependency.**

## Things that will bite you

- `EmailRule` does a live DNS MX lookup. It is off under test; pass
  `new EmailRule(verifyMailServer: true)` to exercise it.
- The site configuration is a JSON file on the local disk, not a table — `RefreshDatabase`
  does not reset it. `tests/Pest.php` fakes the disk for every feature test.
- `kSiteConfig()` is never empty once the app has booted: the cache layer injects
  resolved logo and support keys. To ask whether a config has actually been saved, use
  `app(SiteConfigurationService::class)->getConfigs(raw: true)`.
- `kMoneyFormat()` returns an HTML entity, so Blade needs `{!! !!}`. Pass
  `decodeHtml: true` anywhere HTML is not rendered.
