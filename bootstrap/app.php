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

            // A third workspace is three files: a {Role}Middleware, a routes/{role}.php,
            // and a group here. Add the case to UserRoleEnum and the branch tables in
            // UserService::middlewareGeneralCheck() and WithAuthWorker at the same time.
        }
    )
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
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
