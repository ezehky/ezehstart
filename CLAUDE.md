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

A starter kit, not an application. It ships authentication, roles, two workspaces,
account management, an image library, a blog, and a transaction ledger — and nothing
domain-specific. Every model, enum, service and page here is one you would keep in any
project.

| Area | What ships |
| --- | --- |
| Auth | Password, passwordless OTP, social sign-in (Socialite), TOTP two-factor with recovery codes, password history, login throttle |
| Image library | Folders, multiple upload, per-image visibility, rename-without-changing-the-URL, a delete guard backed by `image_usages` |
| Video library | The same again for embeds — folders, per-video visibility, a delete guard backed by `video_usages`. A row is a **reference** (provider + id), never a file; the player URL is rebuilt from the pair on every render |
| Blog | Posts with a tiptap editor, **polymorphic** categories (grouped by `CategoryGroupEnum`) and tags, public index and post pages. The seeded **Author** role narrows an account to the posts it wrote and gives it a public byline — bio and social handles on `user_profiles` |
| Money | `transactions` plus gateways, metas, evidence, balances and charges; balances derived from confirmed rows, never stored |
| Reference | 250 countries seeded from `database/data/countries.json` — no network call at seed time |
| Support | **Impersonation** — an admin views the site as a member, both ends logged, expiring after an hour, with every account-altering screen closed while it runs. Never admin→admin |
| Accounts | Deletion is a grace period, then anonymize-or-remove. Anonymized rows are soft-deleted and surface only on **Deleted accounts**, where they can be restored or purged. An account holder can also **download a copy** of what is held about them, behind `user.allow-data-download` |

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

Adding a third is three files: a `{Type}Middleware`, a `routes/{type}.php`, and a group
in `bootstrap/app.php`. Add the case to `UserTypeEnum` at the same time — every branch a
type decides (its dashboard route, its label, whether it carries a role) lives on the
case itself, and those `match` arms will fail loudly until you do.

**Type is not role.** `users.user_type` is the workspace an account signs in to, fixed
in code. A **role** is a row in `roles` an administrator creates from the dashboard, it
divides up the admin workspace only, and **only an admin has any**. An admin carries
**any number of them** — the assignment is the `role_user` pivot, and the gate maps
merge with the highest access winning each key, so a second role only ever widens what
somebody reaches. users carry no role at all.

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

- **No new base folders under `app/`.** Thirteen names are sanctioned — `Contracts` is
  one of them and is created on first use — and there is no `Actions`, `Repositories`,
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
- **An action button is gated twice.** `<x-dashboard.gate.button>` /
  `<x-dashboard.gate.menu-item>` hide it, and the method behind it re-checks with
  `kGate()`. Hiding a control is a courtesy; the method check is the boundary.
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
- **Never read an on/off site-config switch with `kSiteConfig()`.** Its `$default` fires
  on any falsy value, so a switch deliberately turned *off* reads back as its default.
  Use `kSiteFlag($group, $key, $default)`, which checks for the key's presence.
- **Impersonation swaps the signed-in account, so nothing about the request is the
  admin any more.** `ImpersonationService::isImpersonating()` is the only way to tell.
  While it runs the session passes `UserMiddleware`, never `AdminMiddleware` — which is
  why the hour expiry is checked there. Any new screen that *changes* an account rather
  than showing it needs `abort_if(app(ImpersonationService::class)->isImpersonating(), 404)`
  in `mount()`, the way the security, delete-account and download-data screens do.
- **`UserTypeEnum::dashboardRoute()` returns a URL, not a route name.** Pass it to
  `redirect()->to()`; `redirect()->route()` throws.
- **Anonymized accounts are soft-deleted, so every ordinary query misses them.** The
  users listing, its metrics and its search all read through the default scope. Reach
  them with `AccountDeletionService::trashedQuery()`, which is what the Deleted accounts
  screen does — a plain `User::find()` answers null for all of them.
- **A new gateable screen starts closed for narrow roles and open for broad ones.** A
  child key with no gate of its own inherits its parent, so `users.deleted-accounts`
  opens for a role holding `users`, and stays shut for one holding only
  `users.users-list`. Nothing needs granting for the protected role; a custom role does.
- **Activity log retention is off by default** (`security.activity-log-retention-days`
  at 0). Throwing away an audit trail is a decision, so the nightly `activity:prune-logs`
  does nothing until somebody sets a window, and anything above 0 is floored at 30 days.
- **Every emailed code has a guess allowance and a resend floor**, both from
  `WithOtpGuard`: five wrong tries destroy the code, and another cannot be asked for
  inside 60 seconds. A test that calls a resend twice gets a validation error, not a
  second email — advance time or clear the cache between the two.
- **Write a password only through `PasswordSecurityService`.** `updatePassword()` sets
  it and files the old hash away in one step; `record()` is the same filing on its own,
  for the admin screens that save a password inside a larger form. Assigning
  `$user->password` by hand leaves a gap in the history chain, and a gap is a password
  the account can quietly go back to.
- Two-factor and social sign-in are **off by default**; strong passwords, password
  history and passwordless sign-in are **on**. Every switch closes its route as well as
  hiding its button — a route left reachable behind a hidden link is not disabled.
- `laravel/socialite` holds guzzle at **7.x**. Laravel 13 ships guzzle 8, and
  `league/oauth1-client` caps below it, so installing Socialite downgraded it for the
  whole app. Removing Socialite is what lifts that.
- The image optimiser shells out to `jpegoptim`/`optipng`/`pngquant`. Where those are
  not installed it does nothing rather than failing — uploads still work, unoptimised.
- Post HTML is sanitised on the way **in** by `BlogService::sanitize()`. That is what
  makes `{!! $post->content !!}` safe on the public page; do not bypass it.
- **`sanitize()` rebuilds every `<iframe>` rather than cleaning it.** `strip_tags` keeps
  attributes on tags it allows, so the src is re-resolved through `VideoProviderEnum`
  and the tag written again from the provider and id that came out. An iframe pointing
  anywhere else is dropped. Adding a host means a case in that enum — nowhere else.
- Rich-text content is styled by `.rich-prose` in `app.css`, **not** by `prose`.
  `@tailwindcss/typography` is not a dependency, so `prose` classes compile to nothing.
