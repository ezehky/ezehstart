# routing.md

## Rule

One route file per workspace, plus `web.php`. Each workspace file is registered by
`bootstrap/app.php` with a URL prefix, a name prefix, and a role middleware.

| File | Prefix | Name prefix | Middleware |
| --- | --- | --- | --- |
| `routes/web.php` | `/` | *(none)* | `web` (+ `guest`/`auth` groups inside) |
| `routes/admin.php` | `/app-splash` | `admin.` | `web`, `auth`, `auth.session`, `AdminMiddleware` |
| `routes/user.php` | `/app` | `user.` | `web`, `auth`, `auth.session`, `UserMiddleware` |

```php
// bootstrap/app.php
then: function (Application $app) {
    Route::middleware(['web', 'auth', 'auth.session', AdminMiddleware::class])
        ->prefix('app-splash')
        ->name('admin.')
        ->group(__DIR__.'/../routes/admin.php');
    …
}
```

### `Route::livewire()` — the only way to route a page

```php
Route::livewire('/users', 'pages::admin.users.users')->name('users');
```

- The component string is the `pages::` namespace path with **dots**, no `⚡`, no
  extension.
- The name is **relative** — the group prefix is applied automatically. Writing
  `->name('admin.users')` inside `routes/admin.php` produces `admin.admin.users`.

### Route model binding

```php
Route::livewire('/user/{user}', 'pages::admin.users.user-view')->name('user');
Route::livewire('/email-verification/{user:email}', 'pages::auth.email-verification')->name('email.verification');
```

- `{model}` binds by id — used for `User` in the admin workspace.
- `{model:slug}` for anything carrying a slug.
- `{model:column}` for any other natural key — `{user:email}` above.
- `{model:slug?}` optional — the page then filters by it, or shows everything.
- Nested parameters keep the parent first: `/parent/{parent:slug}/children/{child}`.

The Livewire page receives them as typed public properties:

```php
public User $user;
```

**Always re-verify the relationship** between two bound models:

```php
abort_unless($this->cohort->is($this->classSession->cohort), 404);
```

### Sub-resource naming

A record with facets nests under the parent's **singular** name:

```php
Route::livewire('/cohort/{cohort:slug}', …)->name('cohort');
Route::livewire('/cohort/{cohort:slug}/students', …)->name('cohort.students');
Route::livewire('/cohort/{cohort:slug}/trainers', …)->name('cohort.trainers');
Route::livewire('/cohort/{cohort:slug}/schedule', …)->name('cohort.schedule');
Route::livewire('/cohort/{cohort:slug}/classes', …)->name('cohort.sessions');
Route::livewire('/cohort/{cohort:slug}/classes/{classSession}/attendance', …)->name('cohort.attendance');
```

Note the URL segment and the route name may differ where the domain word differs
(`/classes` → `cohort.sessions`).

Grouped listings use a `config.` / feature prefix:

```php
Route::livewire('/site-config/faqs', 'pages::admin.configs.faqs')->name('config.faqs');
Route::livewire('/site-config/policies', 'pages::admin.configs.policies')->name('config.policies');
```

### Controllers — invokable only, and only for these four cases

```php
Route::get('/', LandingPageController::class)->name('home');
Route::get('/'.$policyType->value, PolicyPageController::class)->defaults('type', $policyType->value)->name($policyType->routeName());
Route::get('/payment/{vendor}/{transaction:reference}', Callback\PaymentCallbackController::class)->name('payment');
Route::post('/payment/{vendor}', Webhook\PaymentWebhookController::class)->name('payment');
```

Controllers are imported by **namespace**, not class, when several live in a folder:

```php
use App\Http\Controllers\Callback;
use App\Http\Controllers\Webhook;

Route::get('/{provider}/callback', Callback\SocialiteCallbackController::class)->name('social.callback');
Route::post('/payment/{vendor}', Webhook\PaymentWebhookController::class)->name('payment');
```

### Enum-driven routes

Public policy pages are generated from the enum, so adding a policy type adds a route:

```php
foreach (PolicyTypeEnum::cases() as $policyType) {
    // The default is the backing value, not the enum, so implicit binding resolves it.
    Route::get('/'.$policyType->value, PolicyPageController::class)
        ->defaults('type', $policyType->value)
        ->name($policyType->routeName());
}
```

String enums also bind directly as route parameters:

```php
Route::get('/{provider}/redirect', function (SocialProviderEnum $provider) {
    return Socialite::driver($provider->driver())->redirect();
})->name('social.redirect');
```

### Closure routes

Only three, all trivial and all in `web.php`: `set-timezone`, `logout`, and the
socialite redirect. Anything with logic gets a controller or a page.

### Group structure in `web.php`

```php
Route::middleware('guest')->group(function (): void { … });     // register, login, passwordless
Route::prefix('auth')->group(function () { … });                // socialite
Route::livewire('/email-verification/{user:email}', …);         // reachable either way
Route::middleware('auth')->group(function (): void { … });      // logout
Route::prefix('callback')->name('callback.')->group(function () { … });
Route::prefix('webhook')->name('webhook.')->group(function () { … });
```

Closures passed to `->group()` declare `: void`.

### Redirect targets

`bootstrap/app.php` owns the global redirects:

```php
$middleware->redirectGuestsTo(fn () => route('login'));

$middleware->redirectUsersTo(function (Request $request) {
    if ($request->user()->isAdmin()) {
        return route('admin.dashboard');
    }

    return route('user.dashboard');
});

$middleware->preventRequestForgery(except: ['webhooks/*']);
```

### Linking

Always `route()`. Internal links carry `wire:navigate`.

```blade
<flux:button :href="route('admin.user', $item)" wire:navigate icon="eye" variant="primary" size="sm" />
<flux:menu.item icon="list-bullet" href="{{ route('admin.activity-logs', ['q' => $item->email]) }}">Activity</flux:menu.item>
```

### The navigation tree

A new page that needs a sidebar entry must be added to
`kPageNavigationLinks()` in `app/Helpers/navigations.php`, under the key matching its
workspace — `admin` or `user`:

```php
'config' => [
    'label' => 'Configuration',
    'children' => [
        'social-handles' => [
            'label' => 'Social handles',
            'link' => route('admin.config.social-handles'),
        ],
    ],
    'icon' => 'cog-6-tooth',
],
```

**The array keys must match the `kSetSiteTitle()` segments** on the page —
`kSetSiteTitle('config', 'social-handles')` is what lights up `config › social handles`
in the sidebar (`kCheckActiveTitle()` slugs and compares them). Get this wrong and the
page still works, but nothing in the sidebar highlights.

A branch carrying a falsy `check` key is dropped, and a parent left with no children
disappears with them — that is how a feature behind a site-config switch hides its own
menu entry.

## Why

- Non-obvious prefixes (`app-splash`) keep the staff surface off guessable paths
  without needing subdomains.
- Registering role groups in `bootstrap/app.php` means the middleware can never be
  forgotten on an individual route.
- `Route::livewire()` with the `pages::` namespace makes the route file a readable
  sitemap — path, page, name, one line each.
- Slug and reference binding keeps ids out of URLs users share.
- Generating policy routes from the enum means the route, the page, the footer link and
  the consent record all derive from one list.

## Template

```php
// routes/admin.php

// Invoices
Route::livewire('/invoices', 'pages::admin.finance.invoices')->name('invoices');
Route::livewire('/invoice/{invoice:reference}', 'pages::admin.finance.invoice')->name('invoice');
Route::livewire('/invoice/{invoice:reference}/lines', 'pages::admin.finance.invoice-lines')->name('invoice.lines');
```

```php
// app/Helpers/navigations.php → 'admin' → 'finance' → 'children'
'invoices' => [
    'label' => 'Invoices',
    'link' => route('admin.invoices'),
],
```

```php
// resources/views/pages/admin/finance/⚡invoices.blade.php
public function mount(): void
{
    kSetSiteTitle('finance', 'invoices');   // matches the nav keys above
}
```

## Avoid

- `Route::get('/x', SomeLivewireClass::class)` — there are no Livewire classes.
- Repeating the group prefix in `->name()`.
- Registering an authenticated page in `routes/web.php`.
- `url('/app-splash/trainings')` or any hard-coded path — use `route()`.
- Binding by id when the model has a `slug` or `reference`.
- Trusting two bound models are related without `abort_unless(...->is(...))`.
- Adding a page without adding its nav entry (it becomes unreachable) or without
  matching `kSetSiteTitle()` keys (the sidebar never highlights).
- Route closures containing business logic.
- Adding CSRF exemptions beyond the existing `webhooks/*`.
