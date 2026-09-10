# auth.md

## Rule

Authentication is hand-built on Laravel's session guard — **no Breeze, Jetstream, or
Fortify**. Every auth screen is a Livewire page in `resources/views/pages/auth/` using
`#[Layout('layouts::auth')]` and the `WithAuthWorker` trait.

### The screens

| Route | Page | Purpose |
| --- | --- | --- |
| `register` | `⚡register` | Email + password sign-up, optional affiliate code, policy consent |
| `login` | `⚡login` | Email + password |
| `passwordless` | `⚡passwordless` | Register **and** sign in with a six-digit emailed code |
| `password.request` | `⚡forgot-password` | OTP-based password reset |
| `email.verification` | `⚡email-verification` | Six-digit email verification |
| `social.redirect` / `social.callback` | closure + `SocialiteCallbackController` | Google (Facebook and X are defined but disabled) |
| `logout` | closure in `web.php` | `UserService::logoutUser()` |

### Types and roles

Two different things, and the distinction is the whole model.

**Type** — which workspace an account signs in to. `users.type`, cast to
`UserTypeEnum`, fixed in code because a workspace is a middleware, a routes file and a
group in `bootstrap/app.php`. The starter ships `USER` and `ADMIN`; a project adds a
case when it grows a third workspace.

**Role** — how the *admin* workspace is divided up. A row in `roles`, created from the
dashboard: "Administrator", "Media", "Support". **Only an admin has one, and exactly
one** (`users.role_id`). A member has none — the member workspace is not gated, so
there would be nothing for a role to say.

```php
$user->isAdmin();          // $user->type->isAdmin()
$user->isUser();
$user->isType(UserTypeEnum::ADMIN);
$user->hasLiveRole();      // admin, on a role, and that role is switched on
$user->role;               // ?Role — null for every member
```

Query scopes:

```php
User::query()->admins();            // type = admin
User::query()->members();           // type = user
User::query()->ofType($type);
User::query()->withoutLiveRole();   // admins with no role, or a switched-off one
```

Role and type changes go through `RoleService` + the `WithUserRoleManager` trait —
`create()`, `update()`, `delete()`, `assign()`, `changeType()`, each with a
`*BlockedReason(): ?string` guard. Never write `users.role_id` or `users.type`
directly.

An admin with no live role is a **holding state, not a bug**: they sign in, land on the
dashboard, and reach nothing else. The admins listing and the dashboard both call it
out.

### Registration — `WithAuthWorker::createUser()`

One transaction creates the user, the profile, and a consent record for every current
policy. Mail is queued **after** the commit. Self-registration only ever makes a
member — `users.type` defaults to `UserTypeEnum::USER` and there is no role to
assign.

```php
$user = DB::transaction(function () use (…) {
    $user = User::query()->create([...$data, 'ip_address' => request()->ip()]);

    $this->assignStudentRole($user);

    $user->userProfile()->create([...$profileData, 'settings' => $userService->profileDefaultSettings()]);

    $user->affiliateProfile()->create([
        'affiliate_code' => $userService->generateUsername($data['name']),
        'referred_by' => $referred_by?->id,
    ]);

    $getCurrentRequiringConsent->each(fn (Policy $policy) => $userService->recordConsent($policy, $user));

    return $user;
});

// Send welcome email after the transaction is committed
app(EmailVerificationOtpService::class)->sendWelcomeEmail($user, $sendOtp);

$this->logActivity(ActivityActionEnum::REGISTER);

Auth::login($user, true);
session()->regenerate();
```

Failures are caught, logged with context, and return `null`.

### Login — `WithAuthWorker::loginUser()`

```php
protected function loginUser(?string $description = null)
{
    $user = auth()->user();

    app(ActivityLogService::class)->logActivity(ActivityActionEnum::LOGIN, $description);

    // Regenerate the session to prevent session fixation attacks.
    session()->regenerate();

    Mail::to($user->email)->queue(new LoginEmail($user, request()->ip()));
}
```

Every successful sign-in **must** regenerate the session, log the activity, and send the
login-alert email — regardless of which flow it came from (password, OTP, socialite).

### Logout — `UserService::logoutUser()`

```php
app(ActivityLogService::class)->logActivity(ActivityActionEnum::LOGOUT);

Auth::logout();

request()->session()->invalidate();
request()->session()->regenerateToken();
```

### Post-auth redirect — `WithAuthWorker::userDashboardRedirect()`

```php
if ($user->isAdmin())   { return redirect()->intended(route('admin.dashboard'))->with($with); }
if ($user->isTrainer()) { return redirect()->intended(route('trainer.dashboard'))->with($with); }

return redirect()->intended(route('user.dashboard'))->with($with);
```

`bootstrap/app.php` mirrors this for `redirectUsersTo()` and points
`redirectGuestsTo()` at `route('login')`.

### Passwords

```php
use App\Traits\WithPasswordTools;

'password' => ['required', 'confirmed', $this->passwordStrengthRule()],
```

`Password::min(8)->symbols()->mixedCase()->numbers()`, with the matching
`$passwordNote` rendered as the field description. Hashing is the `'password' =>
'hashed'` cast on `User` — never `Hash::make()` in a page.

`password` is **nullable** in the schema: passwordless and socialite accounts have none.

### OTP services

Three services, one per purpose, all `#[Singleton]`:

| Service | Flow | Mailable |
| --- | --- | --- |
| `EmailVerificationOtpService` | verify a new email | `EmailVerificationOtpEmail`, `WelcomeEmail` |
| `PasswordlessOtpService` | register/sign in with a code | `PasswordlessOtpEmail` |
| `AccountOtpService` | sensitive account changes | `AccountOtpEmail` |
| *(reset)* | `PasswordResetOtpEmail` via the forgot-password page | |

OTP pages are multi-step: they mutate `$tag` / `$title` / `$description` and dispatch
`attr` so the `layouts::auth` slots update without a re-render.

Email verification enforcement is configurable:

```php
$config = kSiteConfig('email-settings');

if ($config['verification'] && $config['verification-strict'] && ! $user->hasVerifiedEmail()) {
    return [
        'redirect' => route('email.verification', ['user' => $user->email, 'send' => true]),
        'with' => ['message' => 'Please verify your email address to access this page.'],
    ];
}
```

### Socialite

```php
Route::prefix('auth')->group(function () {
    Route::get('/{provider}/redirect', function (SocialProviderEnum $provider) {
        return Socialite::driver($provider->driver())->redirect();
    })->name('social.redirect');

    Route::get('/{provider}/callback', Callback\SocialiteCallbackController::class)->name('social.callback');
});
```

`SocialProviderEnum` owns which providers are live (`status()`), the Flux icon
(`icon()`), and the driver name (`driver()` — `X` maps to `twitter`).
`SocialProviderEnum::activeCases()` drives the buttons in `layouts::auth`.
Linked accounts are rows in `user_connected_accounts`.

### Policy consent

New users consent to every current policy at registration.
`User::outstandingPolicies()` returns versions they have not accepted yet;
`hasAcceptedCurrentPolicies()` is the boolean. `UserService::recordConsent()` writes a
`UserConsent` with IP and truncated user agent, and re-accepting is a no-op
(`firstOrCreate`).

### Suspension and last-seen

Both handled centrally in `UserService::middlewareGeneralCheck()`:

```php
if ($user->status->isSuspended()) {
    $this->logoutUser();

    return 'Your account has been suspended. Please contact support.';
}

…

app(UserService::class, ['user' => $user])->updateLastSeen();
```

`updateLastSeen()` writes at most once a minute.

### Admin page access

Admins carry a JSON `access` map on their admin role. `UserService::pageAccess()`
checks parent/child keys, passes executives through unconditionally, sets the site
title, and redirects to the profile with an `accessDenied` flash otherwise. The same
function filters the sidebar in `kNavigationStrictAction()`.

## Why

- Hand-built auth because the flows are unusual — passwordless registration, three
  OTP purposes, multi-role accounts, and policy consent at sign-up — none of which the
  starter kits model.
- Roles as rows (with their own status) allow a trainer who is also a student, and
  allow revoking a role without deleting history.
- Session regeneration on every sign-in path closes session fixation regardless of how
  the session was obtained.
- The login-alert email is the user's own audit trail, complementing the admin one.
- Nullable `password` is what makes passwordless and social accounts possible without a
  second user table.

## Example

`routes/web.php`:

```php
Route::middleware('guest')->group(function (): void {
    Route::livewire('/register', 'pages::auth.register')->name('register');
    Route::livewire('/login', 'pages::auth.login')->name('login');
    Route::livewire('/forgot-password', 'pages::auth.forgot-password')->name('password.request');

    // Registers and signs in with an emailed six-digit code, no password involved.
    Route::livewire('/passwordless', 'pages::auth.passwordless')->name('passwordless');
});

Route::livewire('/email-verification/{user:email}', 'pages::auth.email-verification')->name('email.verification');

Route::middleware('auth')->group(function (): void {
    Route::get('/logout', function () {
        app(UserService::class)->logoutUser();

        return redirect()->route('login');
    })->name('logout');
});
```

## Template

```php
<?php

use App\Traits\WithAuthWorker;
use App\Traits\WithPasswordTools;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Component;

new #[Layout('layouts::auth')] class extends Component
{
    use WithAuthWorker, WithPasswordTools;

    public string $email = '';

    public string $password = '';

    public bool $remember = false;

    public function mount(): void
    {
        kSetSiteTitle('login');
    }

    protected function rules(): array
    {
        return [
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ];
    }

    public function login()
    {
        $this->validate();

        $this->respondError(
            'Those details do not match our records.',
            if: ! Auth::attempt(['email' => $this->email, 'password' => $this->password], $this->remember),
            field: 'email',
        );

        $this->loginUser();

        return $this->userDashboardRedirect();
    }
};
?>

<div>
    <x-slot:tag>Welcome back</x-slot:tag>
    <x-slot:title>Sign in to your account</x-slot:title>
    <x-slot:description>Enter your details to continue your training.</x-slot:description>
    <x-slot:socialConnect />

    <form wire:submit="login" class="mt-8 space-y-5">
        <flux:input label="Email" type="email" wire:model="email" autofocus badge="required" />
        <x-form.password label="Password" wire:model="password" />
        <flux:switch wire:model="remember" label="Remember me" />

        <flux:button type="submit" variant="primary" class="w-full">Sign in</flux:button>
    </form>
</div>
```

## Avoid

- Installing Breeze / Jetstream / Fortify, or scaffolding auth controllers.
- `Hash::make()` in a page — the `'password' => 'hashed'` cast handles it.
- Signing a user in without `session()->regenerate()`.
- Writing `users.role_id` or `users.type` directly instead of going through
  `RoleService`.
- Checking a type with a string (`$user->type === 'admin'`) — use `isAdmin()` /
  `isType(UserTypeEnum::ADMIN)`.
- Giving a member a role, or reaching for one on a member. They have none by design.
- Treating "admin with no role" as broken. It is a state the UI is built to show.
- Assuming `password` is non-null.
- Skipping the login activity log or the login-alert email on a new sign-in path.
- Hard-coding a post-login route — use `userDashboardRedirect()`.
- Enabling a social provider without setting `SocialProviderEnum::status()` and the
  `config/services.php` credentials.
- Bypassing `pageAccess()` for a new admin page (it will be invisible in the sidebar or
  unguarded).
