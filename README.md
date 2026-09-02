# Ezeh Start

A Laravel 13 + Livewire 4 starter kit with the batteries a project actually needs on
day one — and a written house style that keeps everything added after day one looking
like it was there from the start.

## What ships

**Authentication** — password sign-in, registration, password reset, email verification
by six-digit code, and a passwordless flow that both registers and signs in from an
emailed code (throttled, attempt-limited, hashed at rest).

**Roles and workspaces** — a `Role`/`UserRole` pivot with active/inactive assignments, so
revoking a role keeps the history. Two workspaces behind their own middleware:

| Workspace | URL prefix | Route name | Route file |
| --- | --- | --- | --- |
| Admin | `/app-splash` | `admin.` | `routes/admin.php` |
| Member | `/app` | `user.` | `routes/user.php` |
| Public + auth | `/` | *(none)* | `routes/web.php` |

**Admin workspace** — dashboard with live metrics and a "waiting on you" queue, admin
and member listings with search and filters, an unassigned-accounts listing, a roles
page, a searchable activity log, a single-account view, and a manage-roles modal with
guards (you cannot revoke your own admin role, or the last one).

**Member workspace** — dashboard with a next-steps checklist, profile, preferences,
security settings, and account deletion that anonymises rather than destroys when there
is history worth keeping.

**Cross-cutting** — an audit trail that records what changed and what it changed from,
database notifications with a bell menu, and a site configuration editable from the
admin rather than from a deploy.

## Getting started

```bash
composer setup     # install, key, migrate, npm install, npm run build
composer dev       # server + queue + vite together
```

Then seed the roles, the site configuration, and an admin:

```bash
php artisan db:seed
```

That creates `admin@example.test` / `password`. **Change it** in
`database/seeders/UserSeeder.php` before this becomes a real project.

### Add-ons

The last step of `composer setup` asks which optional first-party packages you want:

```bash
php artisan kit:install                      # ask
php artisan kit:install --package=transaction  # or name them
php artisan kit:install --dry-run            # see what would be pulled in
```

| Add-on | Package | What it adds |
| --- | --- | --- |
| `transaction` | `ezehky/ezeh-transaction` | Wallets, transactions and withdrawal requests |

A run with nothing to answer the prompt — CI, `--no-interaction` — installs nothing and
exits clean, so the setup script is safe unattended. Adding a future add-on is one case
and one match arm per presenter in [`KitPackageEnum`](app/Enums/KitPackageEnum.php); the
matches are exhaustive, so PHP names anything you forget.

## Working on it

```bash
php artisan test --compact               # the suite
php artisan test --compact --filter=X    # one file or one test
vendor/bin/pint --dirty                  # after touching any PHP
vendor/bin/phpstan analyse --memory-limit=1G
composer test                            # all three, as CI runs them
```

## The house style

This kit is opinionated, and the opinions are written down in **`.agents/my-skills/`** —
44 files covering where code goes, how it is named, and what this project deliberately
does not use. Start at [`agent.md`](.agents/my-skills/agent.md), finish at
[`checklist.md`](.agents/my-skills/checklist.md), and use
[`README.md`](.agents/my-skills/README.md) as the index.

AI agents get the same rules automatically: [`CLAUDE.md`](CLAUDE.md) orients a session,
[`AGENTS.md`](AGENTS.md) carries the Laravel Boost guidelines, and the `house-style`
skill in `.claude/skills/` (mirrored to `.agents/` and `.github/`) routes into the
library on its own.

The short version:

- Every screen is a Livewire single-file component at
  `resources/views/pages/**/⚡name.blade.php`, routed with `Route::livewire()`.
  There is no `app/Livewire`, and no `render()` method.
- Every status is an enum using `WithEnumHelpers`, with one `is{CASE}()` per case.
- Every model is `#[Unguarded]` with a `casts()` method and `#[Scope]` scopes.
- Business logic lives in a `#[Singleton]` service, resolved with `app()`.
- Every admin write is logged through `ActivityLogService`.
- There is no `app/Actions`, `app/Repositories`, `app/Jobs`, `app/Events`,
  `app/Policies`, or `app/Http/Requests` — each has a file in the library explaining
  what this project does instead.

## Stack

Laravel 13 · PHP 8.3+ · Livewire 4 · Flux UI free v2 · Tailwind v4 · Alpine · Pest 5 ·
Pint · Larastan.

## Adding a third workspace

Three files and two `match` arms:

1. `app/Http/Middleware/{Role}Middleware.php` — a copy of `UserMiddleware` with the new
   enum case.
2. `routes/{role}.php`.
3. A route group in `bootstrap/app.php`.
4. The case in `UserRoleEnum`, then the `match` in
   `UserService::middlewareGeneralCheck()` and the branch in
   `WithAuthWorker::userDashboardRedirect()`. Both are exhaustive, so PHP will tell you
   if you miss one.
