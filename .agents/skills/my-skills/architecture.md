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
├── Console/Commands/ scheduled maintenance + reminder commands
├── Contracts/        GatewayAbstract — the payment gateway contract
├── Enums/            37 enums. Every status, type, category, provider.
├── Helpers/          4 autoloaded function files, all functions prefixed k
├── Http/
│   ├── Controllers/  6 controllers: landing, policy, 2 callbacks, 1 webhook, base
│   └── Middleware/   AdminMiddleware, TrainerMiddleware, UserMiddleware
├── Mail/             11 Mailables, all queued
├── Models/           40 models
├── Notifications/    GeneralNotification (database channel only)
├── Providers/        AppServiceProvider only
├── Rules/            EmailRule, ImageRule, MoneyRule
├── Services/         20 services, all #[Singleton]
└── Traits/           15 With* traits
```

```
resources/views/
├── components/
│   ├── dashboard/    avatar, stat-card, sidebar, tab-nav, workspace-no-record, …
│   ├── finance/      withdraw-button-card, withdraw-modal
│   ├── form/         file-field, image-field, markdown-field, number-field, …
│   ├── layouts/      base, email, email/*, site-master
│   ├── lv/           ⚡notifications, ⚡newsletter-form  (Livewire SFCs, not pages)
│   ├── site/         public marketing sections
│   ├── training/     cohort cards, status, callouts
│   └── status.blade.php
├── emails/           mail views, grouped by domain
├── flux/icon/        custom Flux icons (brand logos)
├── layouts/          app.blade.php, auth.blade.php  → the "layouts::" namespace
├── pages/            every routed screen → the "pages::" namespace
│   ├── admin/{configs,finance,training,users}/  + ⚡dashboard
│   ├── auth/
│   ├── shared/
│   ├── trainer/
│   └── user/{account,finance,membership,training}/ + ⚡dashboard
└── static/site/      landing + policy Blade views (controller-rendered)
```

### The three workspaces

Defined in `bootstrap/app.php`:

| Workspace | URL prefix | Route name prefix | Route file | Middleware |
| --- | --- | --- | --- | --- |
| Admin | `/app-splash` | `admin.` | `routes/admin.php` | `AdminMiddleware` |
| Student / affiliate | `/app` | `user.` | `routes/user.php` | `UserMiddleware` |
| Trainer | `/splash-trainer` | `trainer.` | `routes/trainer.php` | `TrainerMiddleware` |
| Public + auth | `/` | *(none)* | `routes/web.php` | `guest` / `auth` |

All three role groups also carry `['web', 'auth', 'auth.session']`.

## Why

- **No controllers for authenticated screens** — Livewire pages already own the state
  and the actions; a controller would be a second place to look.
- **Services are singletons** so cross-cutting state (site config cache, the current
  user on `UserService`) resolves once per request.
- **Enums everywhere** so status semantics (`->isConcluded()`, `->color()`,
  `->label()`) live in one place and never leak as magic strings into Blade.
- **Traits over inheritance** — a Livewire SFC cannot extend a project base class
  cleanly, so shared page behaviour is composed with `With*` traits.
- **Three separate URL prefixes**, deliberately non-obvious (`app-splash`,
  `splash-trainer`), keep the admin surface off the guessable path.

## Example

The full path of one feature — admin FAQ management:

```
routes/admin.php
  Route::livewire('/site-config/faqs', 'pages::admin.configs.faqs')->name('config.faqs');

app/Helpers/navigations.php
  'config' => ['children' => ['faqs' => ['label' => 'FAQs', 'link' => route('admin.config.faqs')]]]

resources/views/pages/admin/configs/⚡faqs.blade.php     ← the page (class + Blade)
app/Models/Faq.php                                       ← casts, answerHtml(), scopes
app/Enums/FaqTypeEnum.php                                ← faq_type
app/Enums/StatusDefault.php                              ← status
app/Services/MarkdownService.php                         ← markdown → HTML
app/Services/ActivityLogService.php                      ← the audit entry
database/migrations/..._create_faqs_table.php
database/seeders/FaqSeeder.php
tests/Feature/AdminFaqTest.php
```

The public side reads the same model through `LandingPageController::faqs()`.

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

## Avoid

- Creating `app/Actions/`, `app/Repositories/`, `app/DTOs/`, `app/Jobs/`,
  `app/Events/`, `app/Listeners/`, `app/Policies/`, `app/Observers/`,
  `app/Http/Requests/`, `app/Http/Resources/`, `app/Livewire/`, `app/View/Components/`.
- Adding a controller for an authenticated screen.
- Putting business logic in a model. Models hold casts, relationships, scopes, and
  small pure getters (`isLocked()`, `progress()`, `displayName()`) — nothing that
  writes, sends mail, or spans several tables.
- Putting business logic in a Blade component.
- Calling a Service from a model (the one exception is a read-only compile step, e.g.
  `Faq::answerHtml()` → `MarkdownService`; follow that only for pure formatting).
- Bypassing the workspace route files — never register an admin page in `web.php`.
