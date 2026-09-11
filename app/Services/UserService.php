<?php

namespace App\Services;

use App\Enums\ActivityActionEnum;
use App\Enums\SocialHandleEnum;
use App\Enums\UserTypeEnum;
use App\Models\NotificationType;
use App\Models\Policy;
use App\Models\User;
use App\Models\UserConsent;
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

    /**
     * Record that a user accepted a policy.
     *
     * firstOrCreate rather than create: the unique index already says one consent
     * per user per version, and a double submit is a thing that happens rather
     * than an error worth showing somebody. The IP and user agent are what make
     * the record evidence rather than a claim.
     */
    /**
     * Write an account's public-facing bio and social handles.
     *
     * The row is created on demand: a profile is a detail of an account rather than
     * something every account is made with, so plenty of them do not have one yet.
     *
     * Handles are stored exactly as typed and blank ones are dropped rather than
     * stored empty — an absent key and an empty string would be two spellings of the
     * same nothing, and socialLinks() would have to know about both.
     *
     * @param  array<string, string|null>  $socials  Keyed by SocialHandleEnum value.
     */
    public function updateAuthorProfile(User $user, ?string $bio, array $socials): bool
    {
        $profile = UserProfile::query()->firstOrNew(['user_id' => $user->id]);

        $profile->bio = filled($bio) ? trim($bio) : null;
        $profile->socials = collect($socials)
            ->only(collect(SocialHandleEnum::profiles())->map(fn (SocialHandleEnum $case) => $case->value)->all())
            ->map(fn ($handle) => trim((string) $handle))
            ->filter(fn (string $handle) => $handle !== '')
            ->all();

        if ($profile->exists && $profile->isClean()) {
            return false;
        }

        $logService = app(ActivityLogService::class);
        $affectedColumns = $logService->affectedColumns($profile);

        $profile->save();

        $logService->logActivity(
            ActivityActionEnum::SETTINGS_UPDATE,
            "Updated the author profile for {$user->name}.",
            $affectedColumns,
            $profile,
            prefixDescription: false,
        );

        return true;
    }

    public function recordConsent(Policy $policy, ?User $user = null): UserConsent
    {
        $user ??= $this->user;

        return UserConsent::query()->firstOrCreate(
            ['user_id' => $user->id, 'policy_id' => $policy->id],
            [
                'accepted_at' => now(),
                'ip_address' => request()->ip(),
                'user_agent' => substr((string) request()->userAgent(), 0, 500),
            ],
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

    // Notification Preferences

    /**
     * Give this account a switch for every notification type that is currently
     * offered. Types live in a table now, so the set can grow after an account was
     * created — this is what backfills the gap on the next page load.
     *
     * Retired types are deliberately not removed here: the preference row goes
     * when the type row does, by foreign key.
     */
    public function runNotificationPreferencesUpdate(): void
    {
        $types = NotificationType::query()->active()->pluck('id');

        foreach ($types as $typeId) {
            $this->user->notificationPreferences()->firstOrCreate(['notification_type_id' => $typeId]);
        }
    }

    // General Middleware

    /**
     * The shared guard behind every workspace middleware. Returns null when the
     * request may proceed, a string to bounce the user out with, or a redirect
     * instruction array.
     */
    public function middlewareGeneralCheck(UserTypeEnum $type): string|null|array
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

        // Check that this account belongs in this workspace at all
        abort_unless($user->isType($type), 404);

        // Email verification check. The keys are read with defaults rather than
        // indexed: an install whose site config has not been seeded yet must still
        // serve the workspace instead of erroring on a missing key.
        if ($type->isUser()) {
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

        // Share the workspace navigation with every view. An account has exactly one
        // type, so there is no workspace to switch to and nothing here offers one —
        // the sidebar renders what this type reaches and nothing else.
        View::share([
            'dashboardRoute' => $type->dashboardRoute(),
            'currentType' => $type,
            'navigationLinks' => kPageNavigationLinks($type->value),
        ]);

        // Return null if no issues
        return null;
    }
}
