<?php

use App\Enums\PolicyTypeEnum;
use App\Http\Controllers\PolicyPageController;
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

// Authentication Routes
Route::middleware('guest')->group(function (): void {

    Route::livewire('/register', 'pages::auth.register')->name('register');
    Route::livewire('/login', 'pages::auth.login')->name('login');
    Route::livewire('/forgot-password', 'pages::auth.forgot-password')->name('password.request');

    // Registers and signs in with an emailed six-digit code, no password involved.
    Route::livewire('/passwordless', 'pages::auth.passwordless')->name('passwordless');

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
