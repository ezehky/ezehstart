# architecture.md

## Rule

The application is a **four-layer, service-centred Laravel app with no controller
layer for authenticated work**. Every authenticated screen is a Livewire 4 single-file
component that talks directly to a Service. Controllers exist only for the public
marketing site, payment callbacks, and webhooks.

```
Route (Route::livewire)
  → Livewire SFC page  (resources/views/pages/**/⚡name.blade.php)
      → Service        (app/Services/*Service.php)         business logic, transactions
      → Model          (app/Models/*.php)                  data + tiny domain getters
      → Enum           (app/Enums/*.php)                   every status/type/category
  → Trait              (app/Traits/With*.php)              shared page + model behaviour
  → Helper             (app/Helpers/k*.php)                formatting, nav, config
  → Blade component    (resources/views/components/**)     presentation
```

### The folder map — this is the whole of `app/`

```
app/
├── Casts/            MoneyCast, TimeCast — attribute casts only
├── Console/Commands/ scheduled maintenance commands (empty in the starter)
├── Contracts/        interfaces and abstracts (empty in the starter)
├── Enums/            every status, type, category. 10 ship with the kit.
├── Helpers/          4 autoloaded function files, all functions prefixed k
├── Http/
│   ├── Controllers/  the base Controller only — authenticated screens use no controller
│   └── Middleware/   AdminMiddleware, UserMiddleware
├── Mail/             6 Mailables, all queued
├── Models/           User, UserProfile, Role, UserRole, ActivityLog,
│                     NotificationSubscription
├── Notifications/    GeneralNotification (database channel only)
├── Providers/        AppServiceProvider only
├── Rules/            EmailRule, ImageRule, MoneyRule
├── Services/         11 services, all #[Singleton]
└── Traits/           7 With* traits
```

`Console/Commands` and `Contracts` ship empty. They are part of the map so a scheduled
command or a gateway interface has an obvious home — creating them is not "inventing
architecture", creating a thirteenth sibling is.

```
resources/views/
├── components/
│   ├── dashboard/    avatar, stat-card, mini-stat, icon-box, sidebar, tab-nav,
│   │                 top-navigation, user-roles, workspace-no-record, …
│   ├── form/         file-field, image-field, markdown-field, number-field,
│   │                 password, phone-field
│   ├── layouts/      base, email, email/*
│   ├── lv/           ⚡notifications  (a Livewire SFC used as a component, not a page)
│   └── util/         e-badge, countdown, floating-actions
├── emails/auth/      mail views
├── flux/icon/        custom Flux icons (brand logos)
├── layouts/          app.blade.php, auth.blade.php  → the "layouts::" namespace
├── pages/            every routed screen → the "pages::" namespace
│   ├── admin/{configs,users}/ + ⚡dashboard
│   ├── auth/         ⚡login, ⚡register, ⚡forgot-password, ⚡passwordless,
│   │                 ⚡email-verification
│   ├── shared/       ⚡profile — mounted by both workspaces
│   └── user/account/ + ⚡dashboard
└── welcome.blade.php the public landing page
```

### The workspaces

Defined in `bootstrap/app.php`:

| Workspace | URL prefix | Route name prefix | Route file | Middleware |
| --- | --- | --- | --- | --- |
| Admin | `/app-splash` | `admin.` | `routes/admin.php` | `AdminMiddleware` |
| Member | `/app` | `user.` | `routes/user.php` | `UserMiddleware` |
| Public + auth | `/` | *(none)* | `routes/web.php` | `guest` / `auth` |

Both role groups also carry `['web', 'auth', 'auth.session']`.

### Adding a third workspace

Three files, plus two `match` arms:

1. `app/Http/Middleware/{Type}Middleware.php` — a copy of `UserMiddleware` with the
   new enum case.
2. `routes/{type}.php`.
3. A `Route::middleware(...)->prefix(...)->name(...)->group(...)` block in
   `bootstrap/app.php`.
4. The case in `UserTypeEnum`. Every branch a type decides — its dashboard route, its
   label, whether it carries a role — lives on the case itself, and those `match`
   arms are exhaustive, so PHP tells you what is missing.

A **type** is a workspace. A **role** is not: roles are rows an administrator creates
from the dashboard, they only ever divide up the admin workspace, and adding one costs
no code at all.

## Why

- **No controllers for authenticated screens** — Livewire pages already own the state
  and the actions; a controller would be a second place to look.
- **Services are singletons** so cross-cutting state (site config cache, the current
  user on `UserService`) resolves once per request.
- **Enums everywhere** so status semantics (`->isConcluded()`, `->color()`,
  `->label()`) live in one place and never leak as magic strings into Blade.
- **Traits over inheritance** — a Livewire SFC cannot extend a project base class
  cleanly, so shared page behaviour is composed with `With*` traits.
- **Separate URL prefixes**, deliberately non-obvious (`app-splash`), keep the admin
  surface off the guessable path.

## Example

The full path of one feature that ships — the admin users listing:

```
routes/admin.php
  Route::livewire('/users', 'pages::admin.users.users')->name('users');

app/Helpers/navigations.php
  'users' => ['children' => ['users' => ['label' => 'users', 'link' => route('admin.users')]]]

resources/views/pages/admin/users/⚡users.blade.php    ← the page (class + Blade)
app/Models/User.php                                      ← casts, users() scope
app/Enums/UserTypeEnum.php                               ← the workspace vocabulary
app/Enums/StatusUser.php                                 ← status
app/Models/Role.php                                      ← an admin role, as a row
app/Traits/WithUserRoleManager.php                       ← the account-access modal
app/Services/RoleService.php                             ← role CRUD, assignment, guards
app/Services/ActivityLogService.php                      ← the audit entry
tests/Feature/AdminWorkspaceTest.php
```

`⚡user-view.blade.php` reads the same model as a single record, and reuses the same
trait for its access modal.

## Template

When adding a feature, create files in this order and no others:

```
1. app/Enums/StatusThing.php            (only if a new status vocabulary is needed)
2. database/migrations/..._create_things_table.php
3. app/Models/Thing.php
4. app/Services/ThingService.php        (only if logic is shared or transactional)
5. resources/views/pages/{workspace}/{group}/⚡things.blade.php
6. routes/{workspace}.php               — one Route::livewire line
7. app/Helpers/navigations.php          — nav entry, if it needs a sidebar link
8. app/Enums/ActivityActionEnum.php     — THING_CREATE/UPDATE/DELETE cases
9. database/seeders/ThingSeeder.php     (only if it ships with default rows)
10. tests/Feature/{Workspace}ThingTest.php
```

Steps 1-4 are optional; steps 5, 6 and 10 never are.

## Avoid

- Creating `app/Actions/`, `app/Repositories/`, `app/DTOs/`, `app/Jobs/`,
  `app/Events/`, `app/Listeners/`, `app/Policies/`, `app/Observers/`,
  `app/Http/Requests/`, `app/Http/Resources/`, `app/Livewire/`, `app/View/Components/`.
- Adding a controller for an authenticated screen.
- Putting business logic in a model. Models hold casts, relationships, scopes, and
  small pure getters (`isLocked()`, `progress()`, `displayName()`) — nothing that
  writes, sends mail, or spans several tables.
- Putting business logic in a Blade component.
- Calling a Service from a model. The one exception is a read-only compile step —
  a model method that renders stored markdown through `MarkdownService`, say. Follow
  that only for pure formatting, never for anything that writes.
- Bypassing the workspace route files — never register an admin page in `web.php`.
