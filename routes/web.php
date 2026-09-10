<?php

use App\Enums\PolicyTypeEnum;
use App\Http\Controllers\PolicyPageController;
use App\Http\Controllers\SocialAuthController;
use App\Services\UserService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome')->name('home');

// Legal pages. One route per policy type, named after the case, so /terms is
// route('terms') and adding a case to the enum publishes a page without anything
// here needing an edit. The type is bound as a route default rather than a URL
// segment, which keeps the URLs flat and the route names stable.
foreach (PolicyTypeEnum::cases() as $policyType) {
    Route::get('/'.$policyType->value, PolicyPageController::class)
        ->defaults('type', $policyType->value)
        ->name($policyType->routeName());
}

// Blog. Public and unauthenticated — the show page refuses anything that is not
// live, so drafts and scheduled posts are not reachable by guessing a slug.
Route::livewire('/blog', 'pages::blog.index')->name('blog.index');
Route::livewire('/blog/{post:slug}', 'pages::blog.show')->name('blog.show');

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

// General Auth Routes
Route::middleware('auth')->group(function (): void {
    // Logout Route
    Route::get('/logout', function () {
        // Log out the user and invalidate the session
        app(UserService::class)->logoutUser();

        // Redirect to the login page
        return redirect()->route('login');
    })->name('logout');
});
