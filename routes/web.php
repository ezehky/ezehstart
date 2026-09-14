<?php

use App\Enums\PolicyTypeEnum;
use App\Http\Controllers\PolicyPageController;
use App\Http\Controllers\SocialAuthController;
use App\Models\User;
use App\Services\AccountDeletionService;
use App\Services\ImpersonationService;
use App\Services\UserService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::view('/', 'site.welcome')->name('home');

// Legal pages. One route per policy type, named after the case, so /terms is
// route('terms') and adding a case to the enum publishes a page without anything
// here needing an edit. The type is bound as a route default rather than a URL
// segment, which keeps the URLs flat and the route names stable.
foreach (PolicyTypeEnum::cases() as $policyType) {
    Route::get("/{$policyType->value}", PolicyPageController::class)
        ->defaults('type', $policyType->value)
        ->name($policyType->routeName());
}

// Take an address off the newsletter. Signed and outside every middleware group:
// the link has to work straight from an email, for somebody who never had an
// account and for somebody who is signed in as a different one. Unsubscribing is
// not a change that needs proving twice, so the signature is the whole check.
Route::livewire('/unsubscribe/{user:email}', 'pages::site.unsubscribe')
    ->middleware('signed')
    ->name('newsletter.unsubscribe');

// Blog. Public and unauthenticated — the show page refuses anything that is not
// live, so drafts and scheduled posts are not reachable by guessing a slug.
Route::livewire('/blog', 'pages::site.blog.index')->name('blog.index');
Route::livewire('/blog/{post:slug}', 'pages::site.blog.show')->name('blog.show');

// Authentication Routes
Route::middleware('guest')->group(function (): void {

    Route::livewire('/register', 'pages::auth.register')->name('register');
    Route::livewire('/login', 'pages::auth.login')->name('login');
    Route::livewire('/forgot-password', 'pages::auth.forgot-password')->name('password.request');

    // Registers and signs in with an emailed six-digit code, no password involved.
    Route::livewire('/passwordless', 'pages::auth.passwordless')->name('passwordless');

    // The second factor. Sits in the guest group because the first factor stands
    // the session back down before sending anybody here — a half-signed-in
    // account must not be able to reach anything while it waits.
    Route::livewire('/two-factor', 'pages::auth.two-factor-challenge')->name('two-factor.challenge');

    // The browser reports the visitor's zone once; every kDatetimeConverter() call
    // afterwards reads it from the session, so timestamps render in local time
    // before the account has a saved timezone.
    Route::post('/set-timezone', function (Request $request) {

        $request->validate([
            'timezone' => 'required|string',
        ]);

        // Store the user's timezone in the session
        $request->session()->put('user-timezone', $request->input('timezone'));

        return response()->json(['message' => 'Timezone set successfully.']);
    })->name('set-timezone');
});

// Social sign-in. Deliberately outside the guest group: the same callback both
// signs somebody in and connects a provider to an account that is already signed
// in, and a guest middleware would block the second case.
Route::get('/auth/{provider}/redirect', [SocialAuthController::class, 'redirect'])->name('social.redirect');
Route::get('/auth/{provider}/callback', [SocialAuthController::class, 'callback'])->name('social.callback');

// Email Verification
Route::livewire('/email-verification/{user:email}', 'pages::auth.email-verification')->name('email.verification');

// Undo a scheduled account deletion. Signed and time-limited rather than sat
// behind the login: the link has to work straight from the email, including for
// somebody who has already forgotten they asked. It is deliberately outside the
// guest group so that clicking it while signed in works too.
Route::get('/account/restore/{user}', function (User $user) {
    $service = app(AccountDeletionService::class);

    // Nothing to undo. A link used twice, or one clicked after an administrator
    // already put the account back, is a 404 rather than a silent no-op.
    abort_unless($service->isPending($user), 404);

    $service->cancel($user, notify: false);

    $message = 'Welcome back. Your account is no longer scheduled for deletion.';

    return auth()->id() === $user->id
        ? redirect()->to($user->user_type->dashboardRoute())->with('message', $message)
        : redirect()->route('login')->with('message', $message);
})->middleware('signed')->name('account.restore');

// General Auth Routes
Route::middleware('auth')->group(function (): void {
    // Hand the session back to the administrator who started impersonating.
    //
    // In the auth group rather than an admin one: while it is running the signed-in
    // account *is* the member, so an admin-only middleware would refuse the one route
    // that ends it and strand the administrator inside somebody else's account.
    Route::get('/stop-impersonating', function () {
        $service = app(ImpersonationService::class);

        abort_unless($service->isImpersonating(), 404);

        $redirect = $service->stop();

        return $redirect
            ? redirect()->to($redirect)->with('message', 'You are back on your own account.')
            : redirect()->route('login')->with('message', 'That session has ended. Please sign in again.');
    })->name('impersonation.stop');

    // Logout Route
    Route::get('/logout', function () {
        // Log out the user and invalidate the session
        app(UserService::class)->logoutUser();

        // Redirect to the login page
        return redirect()->route('login');
    })->name('logout');
});
