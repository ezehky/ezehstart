<?php

namespace App\Services;

use App\Enums\ActivityActionEnum;
use App\Enums\SocialProviderEnum;
use App\Enums\StatusDefault;
use App\Models\User;
use App\Models\UserConnectedAccount;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Support\Collection;
use Laravel\Socialite\Contracts\User as SocialiteUser;

/**
 * Signing in with a third-party account, and the links between those accounts
 * and local ones.
 */
#[Singleton]
class SocialAccountService
{
    /**
     * Whether social sign-in is offered at all. Both the site switch and at least
     * one provider with credentials have to be true — an enabled feature with no
     * configured provider is a row of buttons that all lead to an error page.
     */
    public function isAvailable(): bool
    {
        return (bool) kSiteFlag('security', 'socialite', false)
            && $this->enabledProviders()->isNotEmpty();
    }

    /**
     * The providers this install can actually use: credentials in the environment
     * and the provider's own switch left on.
     *
     * @return Collection<int, SocialProviderEnum>
     */
    public function enabledProviders()
    {
        return collect(SocialProviderEnum::cases())
            ->filter(fn (SocialProviderEnum $provider) => $provider->isConfigured() && $provider->isEnabled())
            ->values();
    }

    /**
     * The same question for one provider, and what the redirect and callback
     * routes abort on. Both switches and the credentials have to agree, or a
     * provider taken off the sign-in page would still be reachable by typing its
     * URL — which is a hidden button, not a disabled feature.
     */
    public function isProviderEnabled(SocialProviderEnum $provider): bool
    {
        return (bool) kSiteFlag('security', 'socialite', false)
            && $provider->isConfigured()
            && $provider->isEnabled();
    }

    // |||||||||||||||||||||||||||||||||||||||||||||||||||||||||||
    // SIGN IN

    /**
     * Resolve a provider identity to a local account, creating one if this is the
     * first time we have seen it.
     *
     * The order matters. The provider id is checked first, because that is the
     * only stable identifier — somebody can change the email on their Google
     * account, and matching on email first would strand them with a second local
     * account. Email is the fallback, and only for linking an existing account.
     *
     * @return array{user: User|null, error: string|null, created: bool}
     */
    public function resolve(SocialProviderEnum $provider, SocialiteUser $socialiteUser): array
    {
        $providerId = (string) $socialiteUser->getId();
        $email = $socialiteUser->getEmail();

        // 1. Seen this exact identity before.
        $existing = UserConnectedAccount::query()
            ->forProvider($provider)
            ->where('provider_id', $providerId)
            ->first();

        if ($existing) {
            $this->refreshTokens($existing, $socialiteUser);

            return ['user' => $existing->user, 'error' => null, 'created' => false];
        }

        // A provider that will not tell us an email leaves nothing to match on and
        // nothing to create an account from.
        if (! $email) {
            return [
                'user' => null,
                'error' => 'Your '.$provider->label().' account did not share an email address, so we cannot sign you in with it.',
                'created' => false,
            ];
        }

        // 2. A local account already uses this email — link, do not duplicate.
        $user = User::query()->where('email', $email)->first();

        if ($user) {
            $this->link($user, $provider, $socialiteUser);

            return ['user' => $user, 'error' => null, 'created' => false];
        }

        // 3. Nobody here yet.
        return ['user' => null, 'error' => null, 'created' => true];
    }

    /**
     * Attach a provider identity to a local account.
     */
    public function link(User $user, SocialProviderEnum $provider, SocialiteUser $socialiteUser): UserConnectedAccount
    {
        $account = $user->connectedAccounts()->updateOrCreate(
            ['provider' => $provider],
            [
                'provider_id' => (string) $socialiteUser->getId(),
                'nickname' => $socialiteUser->getNickname() ?: $socialiteUser->getName(),
                'avatar' => $socialiteUser->getAvatar(),
                'provider_token' => $socialiteUser->token ?? null,
                'provider_refresh_token' => $socialiteUser->refreshToken ?? null,
                'token_expires_at' => isset($socialiteUser->expiresIn)
                    ? now()->addSeconds((int) $socialiteUser->expiresIn)
                    : null,
                'status' => StatusDefault::ACTIVE,
            ]
        );

        app(ActivityLogService::class)->logActivity(
            ActivityActionEnum::SOCIAL_ACCOUNT_LINK,
            $provider->label(),
            model: $user
        );

        return $account;
    }

    /**
     * Detach a provider, refusing when it would leave the account unreachable.
     *
     * Returns the reason it was refused, or null when it worked. An account with
     * no password and one connected provider has exactly one way in, and removing
     * it locks somebody out of their own account permanently.
     */
    public function unlink(User $user, SocialProviderEnum $provider): ?string
    {
        $account = $user->connectedAccounts()->forProvider($provider)->first();

        if (! $account) {
            return 'That account is not connected.';
        }

        if (! $user->password && $user->connectedAccounts()->count() <= 1) {
            return 'This is the only way you can sign in. Set a password first, then disconnect it.';
        }

        $account->delete();

        app(ActivityLogService::class)->logActivity(
            ActivityActionEnum::SOCIAL_ACCOUNT_UNLINK,
            $provider->label(),
            model: $user
        );

        return null;
    }

    // |||||||||||||||||||||||||||||||||||||||||||||||||||||||||||
    // PRIVATE

    /**
     * Providers hand back a new access token on every sign-in, and the old one
     * usually stops working, so the stored pair is replaced rather than kept.
     */
    private function refreshTokens(UserConnectedAccount $account, SocialiteUser $socialiteUser): void
    {
        $account->fill([
            'provider_token' => $socialiteUser->token ?? null,
            'provider_refresh_token' => $socialiteUser->refreshToken ?? $account->provider_refresh_token,
            'token_expires_at' => isset($socialiteUser->expiresIn)
                ? now()->addSeconds((int) $socialiteUser->expiresIn)
                : null,
        ])->save();
    }
}
