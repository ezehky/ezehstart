<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;

/**
 * The two password rules the site configuration can switch on: how strong a new
 * password has to be, and whether it may be one this account has used before.
 */
#[Singleton]
class PasswordSecurityService
{
    // |||||||||||||||||||||||||||||||||||||||||||||||||||||||||||
    // STRENGTH

    /**
     * Defaults to on. An install nobody has configured yet should be the strict
     * one — a security feature that stays off until somebody notices it is a
     * feature nobody has.
     */
    public function strongPasswordEnabled(): bool
    {
        return (bool) kSiteFlag('security', 'strong-password', true);
    }

    /**
     * The rule every new password is validated against.
     *
     * Eight characters is the floor either way — turning "strong passwords" off
     * relaxes the composition requirements, not the length, because a six
     * character password is not a preference anybody should be able to configure.
     */
    public function strengthRule(): Password
    {
        $rule = Password::min(8);

        return $this->strongPasswordEnabled()
            ? $rule->symbols()->mixedCase()->numbers()
            : $rule;
    }

    /**
     * The hint shown under a password field, kept truthful to the rule above so
     * the two cannot drift apart.
     */
    public function note(): string
    {
        return $this->strongPasswordEnabled()
            ? 'Password min 8 chars, a symbol, a number, with both uppercase and lowercase chars.'
            : 'Password must be at least 8 characters.';
    }

    // |||||||||||||||||||||||||||||||||||||||||||||||||||||||||||
    // HISTORY

    public function historyEnabled(): bool
    {
        return (bool) kSiteFlag('security', 'password-history', true);
    }

    /**
     * How many previous passwords are remembered and refused. Clamped rather than
     * trusted: the value comes from a JSON file an administrator edits, and a zero
     * would silently turn the feature off while the switch still read "on".
     */
    public function historyDepth(): int
    {
        $depth = (int) kSiteFlag('security', 'password-history-depth', 5);

        return max(1, min($depth ?: 5, 24));
    }

    /**
     * Whether this account has used this password before.
     *
     * Every remembered hash has to be checked individually — bcrypt salts each
     * one, so there is no query that can do this for us.
     */
    public function hasBeenUsed(User $user, string $plainPassword): bool
    {
        if (! $this->historyEnabled()) {
            return false;
        }

        // The password currently in force is not in the history table yet on an
        // account that predates the feature, so it is checked on its own.
        if ($user->password && Hash::check($plainPassword, $user->password)) {
            return true;
        }

        $hashes = $user->passwordHistories()
            ->newestFirst()
            ->limit($this->historyDepth())
            ->pluck('password');

        foreach ($hashes as $hash) {
            if (Hash::check($plainPassword, $hash)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Remember the hash this account is moving away from, then drop anything that
     * has fallen outside the configured depth.
     *
     * Called with the *already hashed* value — the model does not re-hash it, and
     * passing a plain string here would store a password in the clear.
     */
    public function record(User $user, ?string $hashedPassword): void
    {
        if (! $this->historyEnabled() || ! $hashedPassword) {
            return;
        }

        $user->passwordHistories()->create(['password' => $hashedPassword]);

        $this->prune($user);
    }

    /**
     * Keeping hashes forever is a liability with no matching benefit, so anything
     * past the depth is deleted rather than merely ignored.
     */
    public function prune(User $user): void
    {
        $keepIds = $user->passwordHistories()
            ->newestFirst()
            ->limit($this->historyDepth())
            ->pluck('id');

        $user->passwordHistories()
            ->whereNotIn('id', $keepIds)
            ->delete();
    }

    /**
     * Set a new password, remembering the old one first.
     *
     * This is the only place a password should be written outside registration —
     * doing it by hand is how an account ends up with history that skips a step.
     */
    public function updatePassword(User $user, string $plainPassword): void
    {
        $previous = $user->password;

        $user->forceFill(['password' => $plainPassword])->save();

        $this->record($user, $previous);
    }
}
