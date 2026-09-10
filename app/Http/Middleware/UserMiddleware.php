<?php

namespace App\Http\Middleware;

use App\Enums\UserTypeEnum;
use App\Services\UserService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class UserMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Check the account is signed in and belongs in this workspace
        $result = app(UserService::class)->middlewareGeneralCheck(UserTypeEnum::USER);

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
