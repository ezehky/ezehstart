# middleware.md

## Rule

There is one application middleware **per workspace** — `AdminMiddleware` and
`UserMiddleware` ship with the kit — and they are **identical except for the enum
they pass**. All the logic lives in `UserService::middlewareGeneralCheck()`.

A new workspace means a new middleware that is a copy of `UserMiddleware` with a
different enum case. Never put logic in one: if the check is workspace-specific, it
belongs in `middlewareGeneralCheck()` behind a `match` on the role.

```php
<?php

namespace App\Http\Middleware;

use App\Enums\UserRoleEnum;
use App\Services\UserService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AdminMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Check if the user is authenticated and has the required role
        $result = app(UserService::class)->middlewareGeneralCheck(UserRoleEnum::ADMIN);

        // If the result is a string, it means we need to redirect the user to a specific route with an error message
        if (\is_string($result)) {
            return redirect()->route('login')->with('message', $result);
        }

        // If the result is an array, it means we need to redirect the user to a specific route with a message
        if (\is_array($result)) {
            return redirect()->to($result['redirect'])->with($result['with'] ?? []);
        }

        return $next($request);
    }
}
```

### The `string|null|array` contract

`middlewareGeneralCheck(UserRoleEnum $role): string|null|array`

| Return | Meaning | Middleware does |
| --- | --- | --- |
| `string` | Hard stop with a message | redirect to `login` with `message` flash |
| `array` | Redirect elsewhere | `redirect()->to($result['redirect'])->with($result['with'] ?? [])` |
| `null` | Allowed | `$next($request)` |

### What `middlewareGeneralCheck()` does, in order

1. Not authenticated → `'You must be logged in to access this page.'`
2. Suspended → log out, `'Your account has been suspended. Please contact support.'`
3. **Wrong role → `abort_unless($user->hasRole($role), 404)`** — a 404, not a 403, so
   the existence of the workspace is not confirmed
4. Members only: strict email verification → redirect array to the verification page.
   The `email-settings` keys are read with `data_get()` defaults, so an install whose
   site config has not been seeded still serves the workspace
5. `updateLastSeen()` (throttled to once a minute)
6. Build the available-dashboard links for multi-role accounts
7. `View::share()` the workspace nav data:

```php
View::share([
    'dashboardRoute' => match ($role) {
        UserRoleEnum::ADMIN => route('admin.dashboard'),
        default => route('user.dashboard'),
    },
    'currentRole' => $role,
    'navigationLinks' => kPageNavigationLinks($role->value),
    'dashboardLinks' => $dashboardLinks,
]);
```

8. Return `null`

**Step 7 is why pages never pass nav data to the layout.** Anything the workspace shell
needs is shared here.

### Registration

Never in a route file — always in `bootstrap/app.php`:

```php
then: function (Application $app) {
    Route::middleware(['web', 'auth', 'auth.session', AdminMiddleware::class])
        ->prefix('app-splash')
        ->name('admin.')
        ->group(__DIR__.'/../routes/admin.php');

    Route::middleware(['web', 'auth', 'auth.session', UserMiddleware::class])
        ->prefix('app')
        ->name('user.')
        ->group(__DIR__.'/../routes/user.php');
}
```

Classes are referenced directly — **there are no middleware aliases**.

### Global middleware configuration

```php
->withMiddleware(function (Middleware $middleware): void {
    // Redirect guests to the login page
    $middleware->redirectGuestsTo(fn () => route('login'));

    // Redirect users to their respective dashboards based on their roles
    $middleware->redirectUsersTo(function (Request $request) {
        if ($request->user()->isAdmin()) {
            return route('admin.dashboard');
        }

        return route('user.dashboard');
    });

    // Prevent CSRF for webhooks
    $middleware->preventRequestForgery(except: [
        'webhooks/*',
    ]);
})
```

### Exceptions

```php
->withExceptions(function (Exceptions $exceptions): void {
    $exceptions->shouldRenderJsonWhen(
        fn (Request $request) => $request->is('api/*'),
    );
})
```

### Per-page authorization

Middleware guards the **workspace**. Finer authorization happens in the page:

```php
// admin page access from the role's JSON access map
app(UserService::class)->pageAccess('config', 'faqs');

// two bound models must be related
abort_unless($this->cohort->is($this->classSession->cohort), 404);

// a record must exist for this user
abort_unless((bool) $this->admission, 404);

// record lock
$this->ensureCohortIsEditable();
```

See [policies.md](policies.md).

## Why

- One shared check means a new workspace middleware is 30 lines of boilerplate and zero
  new logic — and a change to the suspension or verification rule applies everywhere at
  once.
- Returning data instead of a response from the service keeps `UserService` free of
  HTTP concerns and testable without a request.
- `abort_unless(..., 404)` rather than 403 avoids confirming that
  `/app-splash` is a real admin area to a probing student account.
- Sharing nav from middleware guarantees the sidebar is built from the **same role
  check** that authorised the request — they cannot disagree.
- Registering in `bootstrap/app.php` makes it impossible to add an unguarded route to a
  workspace file.

## Example

The three files differ only here:

```php
// AdminMiddleware
$result = app(UserService::class)->middlewareGeneralCheck(UserRoleEnum::ADMIN);

// UserMiddleware
$result = app(UserService::class)->middlewareGeneralCheck(UserRoleEnum::USER);

// UserMiddleware
$result = app(UserService::class)->middlewareGeneralCheck(UserRoleEnum::STUDENT);
```

## Template

A fourth workspace (e.g. a partner portal) would be:

```php
<?php

namespace App\Http\Middleware;

use App\Enums\UserRoleEnum;
use App\Services\UserService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class PartnerMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Check if the user is authenticated and has the required role
        $result = app(UserService::class)->middlewareGeneralCheck(UserRoleEnum::PARTNER);

        // If the result is a string, it means we need to redirect the user to a specific route with an error message
        if (\is_string($result)) {
            return redirect()->route('login')->with('message', $result);
        }

        // If the result is an array, it means we need to redirect the user to a specific route with a message
        if (\is_array($result)) {
            return redirect()->to($result['redirect'])->with($result['with'] ?? []);
        }

        return $next($request);
    }
}
```

Plus: a `PARTNER` case on `UserRoleEnum` (with its `isPartner()`), a `partner` branch in
`kPageNavigationLinks()`, a `routes/partner.php`, the `match` arms in
`middlewareGeneralCheck()`, and the group in `bootstrap/app.php`.

## Avoid

- Putting role logic in the middleware body — it belongs in `middlewareGeneralCheck()`.
- Middleware aliases; reference the class.
- `->middleware(AdminMiddleware::class)` on an individual route.
- Returning a `Response` from `middlewareGeneralCheck()` — return
  `string|null|array`.
- `abort(403)` for a wrong workspace; 404 is the project's choice.
- Sharing view data from a page instead of the middleware.
- New global middleware without a strong reason.
- Broadening the CSRF exemption list beyond `webhooks/*`.
- Relying on middleware alone for record-level rules — re-check ownership and locks in
  the page.
