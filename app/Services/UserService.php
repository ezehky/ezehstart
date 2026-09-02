<?php

namespace App\Services;

use App\Enums\ActivityActionEnum;
use App\Enums\NotificationTypeEnum;
use App\Enums\UserRoleEnum;
use App\Models\User;
use App\Models\UserProfile;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Str;

#[Singleton]
class UserService
{
    public function __construct(private ?User $user = null) {}

    public function generateUsername(?string $prefix = null): string
    {
        // FIRST PART: 2 uppercase letters
        $partOne = fake()->regexify('[A-Za-z]{2}');

        // SECOND PART: 2 alphanumeric chars
        $partTwo = fake()->regexify('[A-Za-z0-9]{2}');

        // UNIQUE PART: 4-char hash from UUID
        $unique = str(md5(Str::uuid()))->substr(0, length: 4);
        // PREFIX
        $separator = rand(0, 1) ? '_' : '.';
        if ($prefix) {
            // GET PREFIX | LOWERCASE AND ADD SEPARATOR: stop in empty position if it is a fullname
            $prefix = str($prefix)->before(' ')->lower().$separator;
        }
        // CREATE USERNAME
        $username = "{$prefix}{$partOne}{$partTwo}{$unique}";

        // RETURN USERNAME
        return str($username)->substr(0, 24);
    }

    public function updateLastSeen(): void
    {
        if (auth()->check()) {
            $user = auth()->user();

            // update only if last seen > 1 min ago (to avoid DB spam)
            if ($user->last_seen_at === null || $user->last_seen_at->lt(now()->subMinute())) {
                $user->update([
                    'last_seen_at' => now(),
                ]);
            }
        }
    }

    public function resetPassword(string $password = '12345'): void
    {
        $this->user->password = $password;

        // SAVE
        $this->user->save();

        // Log Activity
        app(ActivityLogService::class)->logActivity(
            ActivityActionEnum::PASSWORD_RESET,
            "Reset {$this->user->name}'s password to {$password}.",
            model: $this->user
        );
    }

    public function logoutUser(): void
    {
        // Log Activity
        app(ActivityLogService::class)->logActivity(ActivityActionEnum::LOGOUT);

        // Log out the user
        Auth::logout();

        // invalidate the session to prevent session fixation attacks
        request()->session()->invalidate();
        request()->session()->regenerateToken();
    }

    // Profile Settings

    /**
     * The per-account switches every profile starts with. Add a key here and it is
     * backfilled onto existing profiles the next time they sign in.
     */
    public function profileDefaultSettings(): array
    {
        return [
            'can-delete-account' => true,
        ];
    }

    public function runProfileSettingsUpdate(): void
    {
        // Ensure the user has a profile
        $profile = UserProfile::query()->firstOrCreate(['user_id' => $this->user->id]);

        // The cast does not hand back a reference, so build the merged set and assign once.
        $settings = (array) ($profile->settings ?? []);

        // Update the profile settings with default values if they don't exist
        foreach ($this->profileDefaultSettings() as $key => $value) {
            $settings[$key] ??= $value;
        }

        // Save the updated profile settings
        $profile->settings = $settings;
        $profile->save();
    }

    // Notification Subscriptions
    public function runNotificationSubscriptionsUpdate(): void
    {
        foreach (NotificationTypeEnum::cases() as $type) {
            $this->user->notificationSubscriptions()->firstOrCreate(['notification_type' => $type]);
        }
    }

    // General Middleware

    /**
     * The shared guard behind every workspace middleware. Returns null when the
     * request may proceed, a string to bounce the user out with, or a redirect
     * instruction array.
     */
    public function middlewareGeneralCheck(UserRoleEnum $role): string|null|array
    {
        // Check if the user is authenticated
        if (! auth()->check()) {
            return 'You must be logged in to access this page.';
        }

        // Get the authenticated user
        $user = auth()->user();

        // Check if the user is suspended
        if ($user->status->isSuspended()) {
            // Log out the user
            $this->logoutUser();

            // Redirect to login page with an error message
            return 'Your account has been suspended. Please contact support.';
        }

        // Check if the user has the required role
        abort_unless($user->hasRole($role), 404);

        // Email verification check. The keys are read with defaults rather than
        // indexed: an install whose site config has not been seeded yet must still
        // serve the workspace instead of erroring on a missing key.
        if ($role->isUser()) {
            $config = kSiteConfig('email-settings');

            $verification = (bool) data_get($config, 'verification', false);
            $strict = (bool) data_get($config, 'verification-strict', false);

            if ($verification && $strict && ! $user->hasVerifiedEmail()) {
                // Redirect to the email verification page with a message
                return [
                    'redirect' => route('email.verification', ['user' => $user->email, 'send' => true]),
                    'with' => ['message' => 'Please verify your email address to access this page.'],
                ];
            }
        }

        // Update the last seen timestamp for the user
        app(UserService::class, ['user' => $user])->updateLastSeen();

        $dashboardLinks = [];

        foreach (UserRoleEnum::cases() as $availableRole) {
            if (! $user->hasRole($availableRole)) {
                continue;
            }

            $dashboardLinks[$availableRole->value] = match ($availableRole) {
                UserRoleEnum::ADMIN => [
                    'label' => 'Administration',
                    'link' => route('admin.dashboard'),
                ],
                UserRoleEnum::USER => [
                    'label' => 'Dashboard',
                    'link' => route('user.dashboard'),
                ],
            };
        }

        // Share the active role navigation and available dashboards with all views.
        View::share([
            'dashboardRoute' => match ($role) {
                UserRoleEnum::ADMIN => route('admin.dashboard'),
                default => route('user.dashboard'),
            },
            'currentRole' => $role,
            'navigationLinks' => kPageNavigationLinks($role->value),
            'dashboardLinks' => $dashboardLinks,
        ]);

        // Return null if no issues
        return null;
    }
}
