# Ezeh Start

A Laravel 13 + Livewire 4 starter kit with the batteries a project actually needs on
day one, and a written house style that keeps everything added after day one looking
like it was there from the start.

## What ships

**Authentication** — password sign-in, registration, password reset, email verification
by six-digit code, and a passwordless flow that both registers and signs in from an
emailed code (throttled, attempt-limited, hashed at rest).

**Sign-in hardening** — a login throttle keyed on the email *and* the address together,
so nobody can lock out an address they know. Configurable password strength and a
password-history check that refuses one this account has already used. TOTP two-factor
with a QR code, single-use recovery codes and a "remember this device" token stored only
as a hash. Social sign-in through Socialite, matching on the provider id first so
somebody who changes their Google email is not stranded with a second account, and
refusing to disconnect the last way into an account. Every one of these is a site-config
switch that closes its route, not just its button.

**Image library** — upload several at once into a folder tree, search by name, rename the
title without ever changing the stored URL, and set who can see each image: private, a
role, or everybody. Deletion is guarded by an `image_usages` table with a restricting
foreign key, so an image on a published post refuses to go rather than leaving a broken
picture behind. Members have a configurable quota; administrators do not. Uploads run
through `spatie/laravel-image-optimizer` where its binaries are installed.

**Blog** — posts written in a tiptap editor that inserts images straight from the
library, with polymorphic categories and tags: one `categories` table serves every kind
of category, separated by `CategoryGroupEnum`, so products can reuse it later without a
second table and a second admin screen. Posts can be scheduled, featured and archived;
the HTML is sanitised on the way in. Public index and post pages with related reading.

**Transaction ledger** — `transactions` with gateway, meta, evidence, balance and charge
rows alongside. Balances are derived from confirmed rows rather than stored, under a row
lock, so a balance can never silently disagree with the ledger that explains it. Money is
integer minor units throughout. Administrators get a review queue and a manual
adjustment that records who moved what and why.

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
guards (you cannot revoke your own admin role, or the last one). Blog posts, categories
and tags. The transaction ledger and its review queue. Site configuration covers
branding, social handles, the legal pages, the FAQs, notification types, and the
sign-in and upload switches.

**Member workspace** — dashboard with a next-steps checklist, profile, preferences,
security settings (password, email, sessions, two-factor, connected accounts), their own
image library, their transaction statement, and account deletion that anonymises rather
than destroys when there is history worth keeping.

**Legal pages and consent** — `/terms`, `/privacy` and `/cookies`, written in markdown
from the admin and served as plain crawlable pages with a contents sidebar. A policy is
versioned: published text is superseded by a new draft rather than edited, and the old
version stays readable at `?version=`, because a consent record points at it. Terms and
privacy are marked as requiring consent, so registration asks for it and records the
version accepted, with timestamp, IP and user agent. Publish a replacement and everyone
is outstanding again until they accept it.

**Public site** — a landing page with an FAQ section whose questions and markdown
answers are editable from the admin, and a footer that links the legal pages straight
off the enum.

**Cross-cutting** — an audit trail that records what changed and what it changed from,
database notifications whose *types* are rows rather than code, so a new one can be added
without a deploy and every account picks up a switch for it on their next visit. A site
configuration editable from the admin rather than from a deploy, 250 countries seeded
from a file in the repository rather than a network call, and a styled confirmation modal
in front of every destructive write.

## Getting started

```bash
composer setup     # install, key, migrate, npm install, npm run build
composer dev       # server + queue + vite together
```

Then seed the roles, the notification types, the countries, the site configuration, an
admin, the FAQs, and v1.0 of the three legal pages:

```bash
php artisan db:seed
```

That creates `admin@example.test` / `password`. **Change it** in
`database/seeders/UserSeeder.php` before this becomes a real project.

The seeded policies are published live, and terms and privacy are marked as requiring
consent — so registration starts asking for them immediately. The copy is boilerplate to
show the shape of a policy, **not legal advice**: replace it with your own before you
launch. A published version is superseded rather than edited, so that means drafting
v2.0 from Admin → Site configuration → Policies and publishing it.

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
43 files covering where code goes, how it is named, and what this project deliberately
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
- Every destructive action confirms through `<x-dashboard.confirm-modal>`, never
  `wire:confirm` — the browser dialog cannot be styled, cannot carry a consequence, and
  is suppressed outright in some in-app browsers.
- There is no `app/Actions`, `app/Repositories`, `app/Jobs`, `app/Events`,
  `app/Policies`, or `app/Http/Requests` — each has a file in the library explaining
  what this project does instead.

## Stack

Laravel 13 · PHP 8.3+ · Livewire 4 · Flux UI free v2 · Tailwind v4 · Alpine · Pest 5 ·
Pint · Larastan.

## Releases

[`CHANGELOG.md`](CHANGELOG.md) records what changed in each version, and what an
existing project has to do to take it.

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
