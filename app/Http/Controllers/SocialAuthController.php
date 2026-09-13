<?php

namespace App\Http\Controllers;

use App\Enums\SocialProviderEnum;
use App\Enums\StatusUser;
use App\Models\User;
use App\Services\SocialAccountService;
use App\Traits\WithAuthWorker;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Laravel\Socialite\Facades\Socialite;

/**
 * The OAuth round trip.
 *
 * A controller rather than a Livewire page because the flow is two plain HTTP
 * redirects with no state of its own — there is no screen here to be reactive.
 */
class SocialAuthController extends Controller
{
    use WithAuthWorker;

    /**
     * Send the visitor to the provider.
     */
    public function redirect(string $provider): RedirectResponse
    {
        $case = $this->resolveProvider($provider);

        return Socialite::driver($case->value)->redirect();
    }

    /**
     * Handle the visitor coming back.
     */
    public function callback(string $provider)
    {
        $case = $this->resolveProvider($provider);

        try {
            $socialiteUser = Socialite::driver($case->value)->user();
        } catch (\Throwable $e) {
            // A cancelled consent screen lands here too, so this is not
            // necessarily an error worth alarming anybody about.
            Log::channel('ezeh')->warning('Social sign-in failed: '.$e->getMessage(), [
                'provider' => $case->value,
            ]);

            return redirect()->route('login')
                ->with('error', 'We could not complete that sign-in. Please try again.');
        }

        $service = app(SocialAccountService::class);

        // Already signed in: this is somebody connecting a provider from their
        // security settings, not signing in with one.
        if (auth()->check()) {
            $service->link(auth()->user(), $case, $socialiteUser);

            return redirect()->route('user.security-settings')
                ->with('success', $case->label().' has been connected to your account.');
        }

        $result = $service->resolve($case, $socialiteUser);

        if ($result['error']) {
            return redirect()->route('login')->with('error', $result['error']);
        }

        $user = $result['user'] ?? $this->registerFromProvider($case, $socialiteUser);

        if (! $user) {
            return redirect()->route('login')
                ->with('error', 'We could not create your account. Please try again.');
        }

        // A suspended or deleted account must not be able to walk back in through
        // a provider. An account inside its deletion grace period still may — it
        // is still theirs until the date passes, and signing in is how somebody
        // gets to the screen that cancels it.
        if ($user->status->isSuspended() || $user->status->isDeleted()) {
            return redirect()->route('login')
                ->with('error', $user->status->isSuspended()
                    ? 'Your account has been suspended. Please contact support.'
                    : 'This account has been deleted.');
        }

        Auth::login($user, true);

        // A connected provider is a first factor like any other, so an account
        // with a second factor still owes it.
        if ($challenge = $this->twoFactorChallengeRedirect($user, true)) {
            return $challenge;
        }

        $this->loginUser('Signed in with '.$case->label().'.');

        return $this->userDashboardRedirect();
    }

    /**
     * Create a local account for a provider identity we have never seen.
     *
     * The email arrives already verified — the provider checked it, which is the
     * whole reason this is a shortcut worth offering. No password is set: the
     * account signs in through the provider until somebody adds one.
     */
    private function registerFromProvider(SocialProviderEnum $provider, $socialiteUser): ?User
    {
        $user = $this->createUser([
            'name' => $socialiteUser->getName() ?: $socialiteUser->getNickname() ?: 'New user',
            'email' => $socialiteUser->getEmail(),
            'email_verified_at' => now(),
            'password' => null,
            'avatar' => null,
            'status' => StatusUser::ACTIVE,
        ], sendOtp: false);

        // link() writes the SOCIAL_ACCOUNT_LINK entry itself, so there is nothing
        // to log here — doing it again would put the same connection in the audit
        // trail twice.
        if ($user) {
            app(SocialAccountService::class)->link($user, $provider, $socialiteUser);
        }

        return $user;
    }

    /**
     * 404 rather than a friendly message: an unknown or switched-off provider is
     * a URL somebody typed, not a state a real user can reach from the interface.
     */
    private function resolveProvider(string $provider): SocialProviderEnum
    {
        $case = SocialProviderEnum::tryFrom($provider);

        abort_unless($case && app(SocialAccountService::class)->isProviderEnabled($case), 404);

        return $case;
    }
}
