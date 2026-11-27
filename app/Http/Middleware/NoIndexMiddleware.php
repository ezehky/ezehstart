<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Keeps the signed-in workspaces out of search results.
 *
 * A header rather than a Disallow line in robots.txt: robots.txt is public, and
 * listing the admin prefix there would hand its address to anybody who reads
 * it. A crawler that follows a link into a workspace lands on the sign-in
 * screen anyway; this makes sure whatever it finds is never indexed.
 */
class NoIndexMiddleware
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');

        return $response;
    }
}
