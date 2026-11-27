<?php

use App\Http\Middleware\AdminMiddleware;
use App\Http\Middleware\LocaleMiddleware;
use App\Http\Middleware\NoIndexMiddleware;
use App\Http\Middleware\UserMiddleware;
use App\Services\CookieConsentService;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function (Application $app) {
            // Admin. The prefix is deliberately non-obvious — the admin surface stays
            // off the guessable path, which is also why robots.txt never names it:
            // both workspaces answer with a noindex header instead.
            Route::middleware(['web', NoIndexMiddleware::class, 'auth', 'auth.session', AdminMiddleware::class])
                ->prefix('app-splash')
                ->name('admin.')
                ->group(__DIR__.'/../routes/admin.php');

            // Member workspace
            Route::middleware(['web', NoIndexMiddleware::class, 'auth', 'auth.session', UserMiddleware::class])
                ->prefix('app')
                ->name('user.')
                ->group(__DIR__.'/../routes/user.php');

            // A third workspace is three files: a {Type}Middleware, a routes/{type}.php,
            // and a group here. Add the case to UserTypeEnum at the same time — every
            // branch a type decides lives on the case, so the match arms there will
            // fail to compile until it does.
        }
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Redirect guests to the login page
        $middleware->redirectGuestsTo(fn () => route('login'));

        // Send a signed-in account to the workspace its type belongs to. The column
        // is user_type, not type — type is an SQL keyword this project does not use
        // as a column name, and ->type read back null here, so every guest route a
        // signed-in account touched was a 500 rather than a redirect.
        $middleware->redirectUsersTo(fn (Request $request) => $request->user()->user_type->dashboardRoute());

        // The interface language, on every web request. Livewire's own update
        // route runs the web group too, so a component re-render is translated the
        // same way as the page it sits on.
        $middleware->web(append: [LocaleMiddleware::class]);

        // The cookie choice is written by the banner in the browser, which cannot
        // encrypt it, so it is read as it was written — see CookieConsentService.
        $middleware->encryptCookies(except: [CookieConsentService::COOKIE]);

        // Prevent CSRF for webhooks
        $middleware->preventRequestForgery(except: [
            'webhooks/*',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
