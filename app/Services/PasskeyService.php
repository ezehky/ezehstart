<?php

namespace App\Services;

use App\Enums\ActivityActionEnum;
use App\Models\User;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Spatie\LaravelPasskeys\Actions\FindPasskeyToAuthenticateAction;
use Spatie\LaravelPasskeys\Actions\GeneratePasskeyRegisterOptionsAction;
use Spatie\LaravelPasskeys\Actions\StorePasskeyAction;
use Spatie\LaravelPasskeys\Models\Passkey;
use Spatie\LaravelPasskeys\Support\Config;
use Spatie\LaravelPasskeys\Support\Serializer;
use Webauthn\AuthenticatorSelectionCriteria;
use Webauthn\PublicKeyCredentialCreationOptions;
use Webauthn\PublicKeyCredentialRequestOptions;
use Webauthn\PublicKeyCredentialRpEntity;
use Webauthn\PublicKeyCredentialUserEntity;

/**
 * Passkeys — WebAuthn key pairs held on the account holder's device.
 *
 * spatie/laravel-passkeys does the cryptography: building the challenge, checking
 * the signature that comes back, storing the public key. What it does not do is
 * sign anybody in the way this project signs people in — its controller calls
 * auth()->login() and nothing else, which would skip the status check, the audit
 * trail and the login-alert email. So its actions are used and its routes and
 * components are not; the screens call this service instead.
 */
#[Singleton]
class PasskeyService
{
    /**
     * Where the challenge waits between the browser asking for it and the signed
     * answer coming back. One key per ceremony, so enrolling a key on the
     * security screen cannot overwrite a sign-in that is half-way through.
     */
    private const REGISTRATION_KEY = 'passkeys.registration-options';

    private const AUTHENTICATION_KEY = 'passkeys.authentication-options';

    private const SIGNUP_KEY = 'passkeys.signup';

    // |||||||||||||||||||||||||||||||||||||||||||||||||||||||||||
    // AVAILABILITY

    /**
     * Whether passkeys are on offer at all. On by default — nothing about a
     * passkey needs a key in .env — and switched off from the security screen,
     * which closes sign-in and enrolment together.
     */
    public function isAvailable(): bool
    {
        return (bool) kSiteFlag('security', 'passkeys', true);
    }

    // |||||||||||||||||||||||||||||||||||||||||||||||||||||||||||
    // ENROLMENT

    /**
     * The options the browser needs to create a key for this account, as JSON.
     * The same JSON is held in the session so the answer can be checked against
     * the challenge that was actually issued.
     */
    public function registrationOptions(User $user): string
    {
        $options = app(GeneratePasskeyRegisterOptionsAction::class)->execute($user);

        session()->put(self::REGISTRATION_KEY, $options);

        return $options;
    }

    /**
     * Check the browser's answer and keep the public key. Null when it does not
     * check out — a cancelled prompt, a challenge that expired, or a key made for
     * another site all land here, and none of them is worth more to the account
     * holder than "that did not work, try again".
     */
    public function register(User $user, string $credentialJson, string $name): ?Passkey
    {
        $options = session()->pull(self::REGISTRATION_KEY);

        return \is_string($options) ? $this->store($user, $credentialJson, $options, $name) : null;
    }

    /**
     * Remove one of this account's keys. Scoped to the account so an id from
     * somebody else's list deletes nothing.
     */
    public function delete(User $user, int $passkeyId): bool
    {
        $passkey = $user->passkeys()->whereKey($passkeyId)->first();

        if (! $passkey) {
            return false;
        }

        $passkey->delete();

        app(ActivityLogService::class)->logActivity(ActivityActionEnum::PASSKEY_DELETE, $passkey->name, model: $user);

        return true;
    }

    // |||||||||||||||||||||||||||||||||||||||||||||||||||||||||||
    // SIGN UP

    /**
     * The options for a key made before its account exists, as JSON — the
     * sign-up page's "create account with a passkey".
     *
     * Built here rather than by the package's action, which reads the handle off
     * a saved account's id. There is no account yet, so the handle is random. It
     * only has to agree with itself: sign-in finds the account through the stored
     * key, and checks the handle the device answers with against the one stored
     * beside it, never against users.id.
     *
     * User verification is required, for the same reason as at sign-in: this key
     * is the account's only way in, and it is only as strong as the fingerprint,
     * face or PIN in front of it.
     *
     * The name and address are held with the challenge, so the account that gets
     * created is the one the device was asked to make a key for.
     */
    public function signupOptions(string $name, string $email): string
    {
        $rp = new PublicKeyCredentialRpEntity(
            name: '',
            id: Config::getRelyingPartyId(),
            icon: Config::getRelyingPartyIcon(),
        );

        // Assigned rather than passed, as the package does — webauthn-lib 5.3
        // deprecates the name in the constructor.
        $rp->name = Config::getRelyingPartyName();

        $options = Serializer::make()->toJson(new PublicKeyCredentialCreationOptions(
            rp: $rp,
            user: new PublicKeyCredentialUserEntity(name: $email, id: Str::random(32), displayName: $name),
            challenge: Str::random(32),
            authenticatorSelection: new AuthenticatorSelectionCriteria(
                null,
                AuthenticatorSelectionCriteria::USER_VERIFICATION_REQUIREMENT_REQUIRED,
                AuthenticatorSelectionCriteria::RESIDENT_KEY_REQUIREMENT_REQUIRED,
            ),
            attestation: PublicKeyCredentialCreationOptions::ATTESTATION_CONVEYANCE_PREFERENCE_NONE,
        ));

        session()->put(self::SIGNUP_KEY, ['options' => $options, 'email' => $email]);

        return $options;
    }

    /**
     * Whether a sign-up challenge is waiting for this address. Asked before the
     * account is written, so a key answer arriving for an address the form no
     * longer holds creates nothing.
     */
    public function hasPendingSignup(string $email): bool
    {
        return data_get(session()->get(self::SIGNUP_KEY), 'email') === $email;
    }

    /**
     * Keep the key the sign-up page's device made, on the account just written for
     * it. Null when it does not check out — called inside the transaction that
     * writes the account, so a null there is what rolls the account back.
     */
    public function completeSignup(User $user, string $credentialJson): ?Passkey
    {
        $pending = session()->pull(self::SIGNUP_KEY);

        if (data_get($pending, 'email') !== $user->email || ! \is_string(data_get($pending, 'options'))) {
            return null;
        }

        return $this->store($user, $credentialJson, $pending['options'], __('Added at sign-up'));
    }

    /**
     * Verify a device's answer against the challenge it was given and keep the
     * public key, logged against the account.
     */
    private function store(User $user, string $credentialJson, string $options, string $name): ?Passkey
    {
        try {
            $passkey = app(StorePasskeyAction::class)->execute(
                $user,
                $credentialJson,
                $options,
                request()->getHost(),
                ['name' => Str::limit(trim($name), 250, '')],
            );
        } catch (\Throwable $e) {
            Log::channel('ezeh')->warning('Passkey enrolment failed: '.$e->getMessage(), ['user' => $user->id]);

            return null;
        }

        app(ActivityLogService::class)->logActivity(ActivityActionEnum::PASSKEY_CREATE, $passkey->name, model: $user);

        return $passkey;
    }

    // |||||||||||||||||||||||||||||||||||||||||||||||||||||||||||
    // SIGN IN

    /**
     * The challenge for a sign-in, as JSON.
     *
     * Built here rather than by the package's action for one reason: user
     * verification is *required*, not preferred. A passkey signs somebody in
     * without the TOTP prompt, and that is only sound when the device itself asked
     * for a fingerprint, a face or a PIN — so a key that merely proves it is
     * plugged in is refused.
     *
     * No allow-list of credentials: the browser offers whichever keys it holds
     * for this site, and the account is found from the one that answers.
     */
    public function authenticationOptions(): string
    {
        $options = Serializer::make()->toJson(new PublicKeyCredentialRequestOptions(
            challenge: Str::random(32),
            rpId: Config::getRelyingPartyId(),
            allowCredentials: [],
            userVerification: PublicKeyCredentialRequestOptions::USER_VERIFICATION_REQUIREMENT_REQUIRED,
        ));

        session()->put(self::AUTHENTICATION_KEY, $options);

        return $options;
    }

    /**
     * The account whose key signed the challenge, or null.
     *
     * The challenge is pulled rather than read, so an answer can be used once and
     * a replayed one finds nothing to check against.
     */
    public function authenticate(string $credentialJson): ?User
    {
        $options = session()->pull(self::AUTHENTICATION_KEY);

        if (! \is_string($options) || ! json_validate($credentialJson)) {
            return null;
        }

        try {
            $passkey = app(FindPasskeyToAuthenticateAction::class)->execute($credentialJson, $options);
        } catch (\Throwable $e) {
            Log::channel('ezeh')->warning('Passkey sign-in failed: '.$e->getMessage());

            return null;
        }

        $user = $passkey?->authenticatable;

        return $user instanceof User ? $user : null;
    }
}
