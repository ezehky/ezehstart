<?php

namespace App\Http\Middleware;

use App\Enums\UserTypeEnum;
use App\Services\ImpersonationService;
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
        // An impersonation sitting that has run out ends here, before the request is
        // served. This is the only middleware it passes through — while it is running
        // the signed-in account is the member, so AdminMiddleware never sees it.
        $impersonation = app(ImpersonationService::class);

        if ($impersonation->hasExpired()) {
            $impersonation->stop();

            return redirect()->route('admin.dashboard')
                ->with('message', 'That impersonation session timed out and you are back on your own account.');
        }

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
