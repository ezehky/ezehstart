<?php

namespace App\Traits;

use App\Models\User;
use App\Services\PasswordSecurityService;
use Illuminate\Validation\Rules\Password;

trait WithPasswordTools
{
    /**
     * The hint rendered under a password field. Filled on boot rather than
     * declared, because what it should say depends on whether strong passwords
     * are switched on in the site configuration.
     */
    public string $passwordNote = '';

    /**
     * Livewire calls boot{TraitName}() on every request, hydration included, so
     * the note is correct even after the configuration changes mid-session.
     */
    public function bootWithPasswordTools(): void
    {
        $this->passwordNote = app(PasswordSecurityService::class)->note();
    }

    protected function passwordStrengthRule(): Password
    {
        return app(PasswordSecurityService::class)->strengthRule();
    }

    /**
     * Refuse a password this account has used before.
     *
     * Returns the error message to show, or null when the password is fine — so
     * a page can hand it straight to respondError() without branching itself.
     */
    protected function passwordReuseError(User $user, string $plainPassword): ?string
    {
        if (! app(PasswordSecurityService::class)->hasBeenUsed($user, $plainPassword)) {
            return null;
        }

        return 'You have used this password before. Please choose one you have not used on this account.';
    }
}
