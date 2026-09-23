<?php

namespace App\Http\Middleware;

use App\Services\LocaleService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Set the language for every web request, guests included.
 *
 * On the web group rather than on the workspaces, because the sign-in screens,
 * the blog and the legal pages are exactly the ones a visitor reads before there
 * is any account to have a preference on.
 */
class LocaleMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $service = app(LocaleService::class);

        $service->apply($service->resolve($request->user()));

        return $next($request);
    }
}
