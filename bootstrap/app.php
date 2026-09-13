<?php

use App\Http\Middleware\AdminMiddleware;
use App\Http\Middleware\UserMiddleware;
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
            // off the guessable path.
            Route::middleware(['web', 'auth', 'auth.session', AdminMiddleware::class])
                ->prefix('app-splash')
                ->name('admin.')
                ->group(__DIR__.'/../routes/admin.php');

            // Member workspace
            Route::middleware(['web', 'auth', 'auth.session', UserMiddleware::class])
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
