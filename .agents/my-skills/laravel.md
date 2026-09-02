# laravel.md

## Rule

This file records the **framework-level choices this project has already made**. Do not
re-decide them.

### Versions

| Package | Version |
| --- | --- |
| `laravel/framework` | ^13.8 |
| PHP | ^8.3 (runtime 8.4) |
| `livewire/livewire` | ^4.3 |
| `livewire/flux` | ^2.15 (free tier) |
| `laravel/socialite` | ^5.28 |
| `jenssegers/agent` | ^2.6 |
| `tailwindcss` | ^4.0 |
| `pestphp/pest` | ^4.7 |
| `laravel/pint` | ^1.27 |
| `laravel/boost` | ^2.2 (dev) |

Do not change dependencies without approval.

### Laravel 11+ skeleton

- **`bootstrap/app.php`** owns routing, middleware, and exceptions. There is no
  `app/Http/Kernel.php`, no `RouteServiceProvider`, no `AuthServiceProvider`,
  no `EventServiceProvider`.
- **`AppServiceProvider` is the only provider.** It registers the `searchMacro` builder
  macro and shares `$_configs` with every view.
- Config files are the framework defaults plus two project files:
  `config/_setups.php` (page title, metadata, status→colour map) and
  `config/_site-config.php`.

### Laravel 12/13 attributes this project uses

```php
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Attributes\Unguarded;
```

| Attribute | Applied to | Replaces |
| --- | --- | --- |
| `#[Singleton]` | every service | `$this->app->singleton()` in a provider |
| `#[Unguarded]` | every model | `$fillable` / `$guarded` |
| `#[Hidden([...])]` | `User` | `$hidden` |
| `#[Scope]` | model scopes | `scopeFoo()` |
| `#[Computed]` | Livewire computed props | `getFooProperty()` |
| `#[Url]` | Livewire filter props | manual query-string sync |
| `#[Layout]` | guest pages | `$layout` property |

### Container

Resolve with `app()`, never `new`:

```php
app(ActivityLogService::class)->logActivity(…);
app(UserService::class, ['user' => $user])->updateLastSeen();
```

`#[Singleton]` means the container returns the same instance for the request, unless
you pass constructor arguments.

### Eloquent

- `Model::query()->…` always.
- `casts()` method, enum casts on every status/type column.
- `#[Scope]` attribute scopes.
- `MoneyCast` / `TimeCast` for money and time-of-day.
- `AsArrayObject` for mutable JSON.
- Route binding by `slug` / `reference`.
- No observers, no `booted()`, no global scopes.

See [models.md](models.md).

### Queues

- Queue driver is configured in `.env`; `composer dev` runs
  `php artisan queue:listen --tries=1` alongside Vite and the server.
- **Every Mailable and `GeneralNotification` implements `ShouldQueue`.**
- **There are no Job classes.** Background work is queued mail/notifications, or a
  scheduled Artisan command. See [jobs.md](jobs.md).

### Scheduling

`routes/console.php`, `Schedule::command(...)->everyFiveMinutes()->withoutOverlapping()`.
See [commands.md](commands.md).

### Caching

- `Cache::rememberForever()` with a key that embeds `updated_at`, so an edit
  invalidates it without an observer:
  `"faq:{$this->id}:answer:{$this->updated_at?->getTimestamp()}"`.
- `SiteConfigurationService::cacheSiteConfig()` warms the site config once per request
  from `AppServiceProvider::boot()`.
- Request-scoped memoisation with a `static` inside a helper:

```php
// BUILD ONCE PER REQUEST: the tree resolves ~60 routes, so memoize it.
static $construct = null;
```

### Logging

```php
Log::channel('code')->error('Error creating user: '.$e->getMessage(), ['exception' => $e, 'data' => $data]);
Log::channel('code')->error('Class reminder failed to queue', ['class_session_id' => $session->id, …]);
Log::channel($vendor->value)->error("{$vendor->label()}: Transaction verification failed.", […]);
```

Always with a context array. Application errors go to the `code` channel
(`storage/logs/code.log`). Only the surfaces with a channel of their own opt out:
site configuration writes to `site-config`, payment vendors to their vendor channel.

### Storage

`public` disk for uploads (`kStoreFile` / `kDeleteFile` / `kSafeImage`);
`storage/app/private` for `site-configuration.json` and `disposable_domains.txt`.

### Frontend build

Vite 8 + `laravel-vite-plugin` + `@tailwindcss/vite`. Entry points:
`resources/css/app.css`, `resources/js/app.js`.

```bash
npm run dev       # or
composer dev      # serve + queue:listen + vite, concurrently
npm run build
```

If a frontend change is not visible, tell the user to run one of these.

### Artisan

Generate with `make:` and `--no-interaction`, then rewrite the body to match the
project's conventions:

```bash
php artisan make:model Invoice --no-interaction
php artisan make:migration create_invoices_table --no-interaction
php artisan make:command SendInvoiceReminderCommand --no-interaction
php artisan make:test --pest AdminInvoiceTest
php artisan make:class Services/InvoiceService --no-interaction
```

There is **no** `make:livewire` usage — pages are hand-created as
`resources/views/pages/**/⚡name.blade.php`. (`livewire.make_command.type` is `sfc`
with `emoji: true`, so `make:livewire` would produce the right shape if used.)

### Formatting

`vendor/bin/pint --dirty --format agent` after any PHP change. Never `pint --test`.

### Laravel Boost

`laravel/boost` is installed and provides MCP tools (`search-docs`, `database-query`,
`database-schema`, `get-absolute-url`, `browser-logs`). `AGENTS.md` at the project root
holds the Boost guidelines and is authoritative on framework usage; **this skills
library is authoritative on project-specific style**. Where they overlap, follow the
skills library — it describes what the code actually does.

### Herd

The site is served by Laravel Herd at `https://webdesign-training.test`. Never start a
dev server; it is always running.

## Why

- Laravel 11+ attributes (`#[Singleton]`, `#[Unguarded]`, `#[Scope]`) put configuration
  next to the thing it configures, removing the provider indirection this project never
  needed.
- No Job classes because every unit of background work here is either an email or a
  periodic sweep — a queued Mailable and a scheduled command cover both with less
  machinery.
- Cache keys embedding `updated_at` avoid an observer layer entirely: the key changes
  when the row does, so stale entries simply become unreachable.
- Keeping to the framework defaults everywhere else means an upgrade is a
  `composer update` and a `git diff` of `config/`, not an archaeology exercise.

## Example

`AppServiceProvider::boot()` — the whole of this project's framework customisation:

```php
public function boot(): void
{
    Builder::macro('searchMacro', function ($columns, $search) {
        …
    });

    // Site Configuration Service
    $serviceInstance = app(SiteConfigurationService::class);

    // INITIATE SITE CONFIG
    if (! app()->runningInConsole() || app()->runningUnitTests()) {
        $serviceInstance->cacheSiteConfig(true);

        $configs = kSiteConfig(keys: ['logo', 'logo-dark', 'name', 'favicon', 'email', 'phone', 'address', 'social-handles']);

        View::share(['_configs' => $configs]);
    }
}
```

## Avoid

- Recreating `app/Http/Kernel.php`, `RouteServiceProvider`, `AuthServiceProvider`, or
  `EventServiceProvider`.
- A second service provider — register in `AppServiceProvider`.
- `$this->app->singleton()` — use `#[Singleton]`.
- Adding a Composer or NPM dependency without approval.
- API resources, API versioning, or `routes/api.php` — there is no API.
- Broadcasting, Echo, Reverb, Horizon, Telescope, Scout, Sanctum, Passport,
  Spatie packages — none are installed.
- `Str::slug()`, `number_format()` on money, `Carbon::parse()->format()` in Blade —
  helpers exist for all three.
- Starting a dev server (Herd serves the site).
- `pint --test`.
- Trusting `AGENTS.md` over this skills library on questions of project style.
