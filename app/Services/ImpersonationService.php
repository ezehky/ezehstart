<?php

namespace App\Services;

use App\Enums\ActivityActionEnum;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Support\Facades\Auth;

/**
 * Signing in as somebody else, so support can see what they see.
 *
 * The feature exists because "the button does not work" is unanswerable from the outside.
 * It is also the most dangerous thing an administrator can do, so every part of it is
 * built around the assumption that it will one day be abused or forgotten about:
 *
 * - **Never administrator to administrator.** Becoming a peer is privilege escalation
 *   with a support story attached, and the audit trail would name the wrong person for
 *   everything that followed.
 * - **The real identity lives in the session**, never in a signed URL or a query
 *   parameter. A token in a link is a token that gets copied, logged and replayed.
 * - **Both ends are logged**, as the administrator, before the swap and after the swap
 *   back — so the trail reads "X started acting as Y" rather than going quiet.
 * - **It expires.** An administrator who wanders off is otherwise a live session with
 *   somebody else's identity attached to it.
 * - **Account-altering screens are closed**, enforced in mount() on each of them rather
 *   than trusted to a hidden button. Impersonation is for looking.
 */
#[Singleton]
class ImpersonationService
{
    /**
     * The real administrator's id, while somebody else is being impersonated.
     */
    public const SESSION_KEY = 'impersonator_id';

    /**
     * When the swap happened, for the expiry.
     */
    public const STARTED_KEY = 'impersonation_started_at';

    /**
     * How long one sitting may last. Long enough to reproduce a problem, short enough
     * that a forgotten tab closes itself.
     */
    public const MAX_MINUTES = 60;

    // Getters

    public function isImpersonating(): bool
    {
        return session()->has(self::SESSION_KEY);
    }

    /**
     * The administrator behind the current session, if there is one.
     */
    public function impersonator(): ?User
    {
        if (! $id = session(self::SESSION_KEY)) {
            return null;
        }

        return User::query()->whereKey($id)->first();
    }

    /**
     * Minutes left in this sitting, 0 when it is over or not running.
     */
    public function minutesRemaining(): int
    {
        if (! $startedAt = session(self::STARTED_KEY)) {
            return 0;
        }

        $elapsed = now()->diffInMinutes(Carbon::parse($startedAt), absolute: true);

        return (int) max(0, self::MAX_MINUTES - $elapsed);
    }

    public function hasExpired(): bool
    {
        return $this->isImpersonating() && $this->minutesRemaining() <= 0;
    }

    /**
     * Why this account cannot be impersonated, or null when it can.
     *
     * The ?string blocked-reason contract: null means allowed, a string is the sentence
     * shown to the administrator who tried.
     */
    public function blockedReason(User $target): ?string
    {
        if ($this->isImpersonating()) {
            return 'You are already viewing the site as somebody else. Return to your own account first.';
        }

        if (! auth()->check() || ! auth()->user()->isAdmin()) {
            return 'Only an administrator can view the site as another account.';
        }

        if (auth()->id() === $target->getKey()) {
            return 'You are already signed in as yourself.';
        }

        // The one refusal that is not about convenience. An administrator who can become
        // another administrator has every gate that account holds, and the trail would
        // credit the actions to them rather than to whoever made the swap.
        if ($target->isAdmin()) {
            return 'An administrator cannot be impersonated.';
        }

        // A status that carries a message is one the workspace middleware turns away, so
        // the swap would end in an immediate logout and look like a broken feature.
        if ($target->status->message()) {
            return 'That account cannot sign in at the moment, so there is nothing to see as them.';
        }

        return null;
    }

    // Actions

    /**
     * Become this account.
     *
     * Returns the route to send the administrator to, so the caller does not have to
     * know which workspace the target belongs in.
     */
    public function start(User $target): string
    {
        $administrator = auth()->user();

        // Written before the swap, so it belongs to the administrator who made it
        // rather than to the account they became.
        app(ActivityLogService::class)->logActivity(
            ActivityActionEnum::IMPERSONATION_START,
            " {$target->name} ({$target->email})",
            model: $target,
        );

        Auth::login($target);

        // Set after the login rather than before it: Auth::login() migrates the session,
        // and a key written on the far side of that is one less thing to reason about.
        session([
            self::SESSION_KEY => $administrator->getKey(),
            self::STARTED_KEY => now()->toIso8601String(),
        ]);

        return $target->user_type->dashboardRoute();
    }

    /**
     * Hand the session back to the administrator who started it.
     *
     * Returns where to send them, or null when there was nothing to return from —
     * which is what a stale link or a second click looks like.
     */
    public function stop(): ?string
    {
        $administrator = $this->impersonator();
        $target = auth()->user();

        $this->forget();

        // The administrator's own account is gone, or was never there. Nothing can be
        // handed back, so the safe answer is to end the session rather than leave
        // somebody signed in as an account they did not sign in to.
        if (! $administrator || ! $administrator->isAdmin()) {
            app(UserService::class)->logoutUser();

            return null;
        }

        Auth::login($administrator);

        // Logged after the swap back, so again it is the administrator's entry.
        app(ActivityLogService::class)->logActivity(
            ActivityActionEnum::IMPERSONATION_STOP,
            $target ? " {$target->name} ({$target->email})" : null,
            model: $target,
        );

        return $administrator->user_type->dashboardRoute();
    }

    /**
     * Drop the keys without touching the session's identity. Used by stop(), and on its
     * own wherever a session is being torn down anyway.
     */
    public function forget(): void
    {
        session()->forget([self::SESSION_KEY, self::STARTED_KEY]);
    }
}
