<?php

namespace App\Traits;

use App\Enums\ActivityActionEnum;
use App\Enums\UserRoleEnum;
use App\Mail\LoginEmail;
use App\Models\Policy;
use App\Models\Role;
use App\Models\User;
use App\Services\ActivityLogService;
use App\Services\EmailVerificationOtpService;
use App\Services\PolicyContentService;
use App\Services\UserService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

trait WithAuthWorker
{
    use WithFormResponseMessage;

    protected function userDashboardRedirect(array $with = [])
    {
        $user = auth()->user();

        // Admin
        if ($user->isAdmin()) {
            return redirect()->intended(route('admin.dashboard'))->with($with);
        }

        // Everyone else
        return redirect()->intended(route('user.dashboard'))->with($with);
    }

    protected function logActivity(ActivityActionEnum $action, string $description = ''): void
    {
        app(ActivityLogService::class)->logActivity($action, $description);
    }

    /**
     * Register an account and everything that has to exist alongside it.
     *
     * The user, their role and their profile are written together, so a failure
     * halfway through cannot leave an account that can sign in but reach nothing.
     * Mail is sent after the commit, never inside it.
     */
    private function createUser(array $data, array $profileData = [], bool $sendOtp = true): ?User
    {
        $userService = app(UserService::class);

        // Resolved before the transaction opens. Which policies are in force is a
        // read against the same rows the consent checkbox was rendered from, and
        // doing it inside the write would hold the account open on a query that
        // has nothing to do with creating it.
        $consentPolicies = app(PolicyContentService::class)->getCurrentRequiringConsent();

        try {
            $user = DB::transaction(function () use ($data, $profileData, $userService, $consentPolicies) {
                // Create the user
                $user = User::query()->create([
                    ...$data,
                    'ip_address' => request()->ip(),
                ]);

                // Assign the default role to the user
                $this->assignDefaultRole($user);

                // Create the user profile if provided
                $user->userProfile()->create([...$profileData, 'settings' => $userService->profileDefaultSettings()]);

                // The account and its consent records are written together. An
                // account that exists without the record of what it agreed to is
                // exactly the state this feature is here to prevent.
                $consentPolicies->each(fn (Policy $policy) => $userService->recordConsent($policy, $user));

                return $user;
            });

            // Send welcome email after the transaction is committed
            app(EmailVerificationOtpService::class)->sendWelcomeEmail($user, $sendOtp);

            // Log Activity: Log the registration activity
            $this->logActivity(ActivityActionEnum::REGISTER);

            // Log the user in after registration
            Auth::login($user, true);
            session()->regenerate();

            return $user;
        } catch (\Throwable $e) {
            // Log the error for debugging purposes
            Log::channel('code')->error('Error creating user: '.$e->getMessage(), [
                'exception' => $e,
                'data' => $data,
                'profileData' => $profileData,
            ]);
        }

        return null;
    }

    /**
     * @param  string|null  $description  How the session was obtained. Defaults to the
     *                                    action's own wording when omitted.
     */
    protected function loginUser(?string $description = null)
    {
        $user = auth()->user();

        // Log Activity: Log the login activity
        app(ActivityLogService::class)->logActivity(ActivityActionEnum::LOGIN, $description);

        // Regenerate the session to prevent session fixation attacks. Resolved from the
        // container rather than the request, matching createUser() above.
        session()->regenerate();

        Mail::to($user->email)->queue(new LoginEmail($user, request()->ip()));
    }

    /**
     * The role every self-registered account starts with.
     */
    private function assignDefaultRole(User $user): void
    {
        $role = Role::query()->firstOrCreate(['name' => UserRoleEnum::USER]);

        $user->roles()->syncWithoutDetaching([$role->id]);
    }
}
