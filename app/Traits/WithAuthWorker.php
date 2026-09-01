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

        // Trainer
        if ($user->isTrainer()) {
            return redirect()->intended(route('trainer.dashboard'))->with($with);
        }

        // Student
        return redirect()->intended(route('user.dashboard'))->with($with);
    }

    protected function logActivity(ActivityActionEnum $action, string $description = ''): void
    {
        app(ActivityLogService::class)->logActivity($action, $description);
    }

    private function createUser(array $data, array $profileData = [], ?User $referred_by = null, bool $sendOtp = true): ?User
    {
        $userService = app(UserService::class);
        $getCurrentRequiringConsent = app(PolicyContentService::class)->getCurrentRequiringConsent();

        try {
            $user = DB::transaction(function () use ($data, $profileData, $referred_by, $userService, $getCurrentRequiringConsent) {
                // Create the user
                $user = User::query()->create([
                    ...$data,
                    'ip_address' => request()->ip(),
                ]);

                // Assign the student role to the user
                $this->assignStudentRole($user);

                // Create the user profile if provided
                $user->userProfile()->create([...$profileData, 'settings' => $userService->profileDefaultSettings()]);

                // Affiliate profile
                $user->affiliateProfile()->create([
                    'affiliate_code' => $userService->generateUsername($data['name']),
                    'referred_by' => $referred_by?->id,
                ]);

                // Record consent for all current policies requiring consent
                $getCurrentRequiringConsent
                    ->each(fn (Policy $policy) => $userService->recordConsent($policy, $user));

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
            logger()->error('Error creating user: '.$e->getMessage(), [
                'exception' => $e,
                'data' => $data,
                'profileData' => $profileData,
                'referred_by' => $referred_by?->id,
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

    private function assignStudentRole(User $user): void
    {
        $role = Role::query()->firstOrCreate(['name' => UserRoleEnum::STUDENT]);

        $user->roles()->syncWithoutDetaching([$role->id]);
    }

    private function getReferringUser(?string $affiliate_code = null): ?User
    {
        if (! $affiliate_code) {
            return null;
        }

        return User::query()
            ->whereHas('affiliateProfile', fn ($query) => $query->where('affiliate_code', $affiliate_code))
            ->first();
    }
}
