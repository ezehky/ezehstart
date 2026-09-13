<?php

namespace App\Services;

use App\Enums\ActivityActionEnum;
use App\Enums\StatusDefault;
use App\Models\User;
use App\Models\UserTwoFactor;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;

/**
 * Time-based one-time passwords, the recovery codes that go with them, and the
 * "remember this device" token that lets somebody skip the prompt for a while.
 */
#[Singleton]
class TwoFactorService
{
    /**
     * How many recovery codes are issued at a time, and how long a remembered
     * device stays remembered.
     */
    private const RECOVERY_CODE_COUNT = 8;

    private const REMEMBER_DAYS = 30;

    private function engine(): Google2FA
    {
        return new Google2FA;
    }

    // |||||||||||||||||||||||||||||||||||||||||||||||||||||||||||
    // AVAILABILITY

    /**
     * Whether the site offers two-factor at all. Off by default: it needs an
     * authenticator app on the other end, and a starter kit should not force that
     * on the first person who signs up.
     */
    public function isAvailable(): bool
    {
        return (bool) kSiteFlag('security', 'two-factor', false);
    }

    public function isEnabledFor(?User $user): bool
    {
        return $this->isAvailable() && (bool) $user?->twoFactor?->isEnabled();
    }

    // |||||||||||||||||||||||||||||||||||||||||||||||||||||||||||
    // ENROLMENT

    /**
     * Start enrolment: make a secret and hand back the row holding it.
     *
     * The row is created unconfirmed and stays that way until a generated code
     * verifies. Somebody who scans the QR and closes the tab must not end up
     * locked out of their own account by a factor they never finished setting up.
     */
    public function beginEnrolment(User $user): UserTwoFactor
    {
        $twoFactor = $user->twoFactor()->firstOrNew([]);

        // Re-enrolling replaces the secret. Keeping the old one would let a
        // previously-scanned authenticator carry on working after somebody
        // deliberately started again — usually because they lost the first one.
        $twoFactor->fill([
            'secret' => $this->engine()->generateSecretKey(),
            'recovery_codes' => null,
            'confirmed_at' => null,
            'status' => StatusDefault::INACTIVE,
        ])->save();

        return $twoFactor;
    }

    /**
     * The otpauth:// URI an authenticator app scans.
     */
    public function provisioningUri(User $user, UserTwoFactor $twoFactor): string
    {
        return $this->engine()->getQRCodeUrl(
            (string) (kSiteConfig('name') ?: config('app.name')),
            $user->email,
            (string) $twoFactor->secret
        );
    }

    /**
     * The QR code as inline SVG.
     *
     * Rendered here rather than fetched from an image service: the URI contains
     * the shared secret, and handing that to a third party to draw would defeat
     * the point of the whole feature.
     */
    public function qrCodeSvg(User $user, UserTwoFactor $twoFactor, int $size = 200): string
    {
        $writer = new Writer(new ImageRenderer(new RendererStyle($size, 0), new SvgImageBackEnd));

        return $writer->writeString($this->provisioningUri($user, $twoFactor));
    }

    /**
     * Finish enrolment once a code from the app checks out. Returns false without
     * changing anything when it does not.
     */
    public function confirm(User $user, string $code): bool
    {
        $twoFactor = $user->twoFactor;

        if (! $twoFactor?->secret || ! $this->verifyCode($twoFactor, $code)) {
            return false;
        }

        $twoFactor->fill([
            'recovery_codes' => $this->generateRecoveryCodes(),
            'recovery_generated_count' => 1,
            'confirmed_at' => now(),
            'status' => StatusDefault::ACTIVE,
        ])->save();

        app(ActivityLogService::class)->logActivity(ActivityActionEnum::TWO_FACTOR_ENABLE, model: $user);

        return true;
    }

    /**
     * Turn it off and destroy the secret. The row is deleted rather than flagged:
     * a disabled secret sitting in the database is a credential nobody is
     * watching any more.
     */
    public function disable(User $user): void
    {
        $user->twoFactor()->delete();

        app(ActivityLogService::class)->logActivity(ActivityActionEnum::TWO_FACTOR_DISABLE, model: $user);
    }

    // |||||||||||||||||||||||||||||||||||||||||||||||||||||||||||
    // VERIFICATION

    /**
     * Check a six-digit code against the secret.
     *
     * The window of one either side covers clock drift between the phone and the
     * server, which is the single most common reason a correct code is refused.
     */
    public function verifyCode(UserTwoFactor $twoFactor, string $code): bool
    {
        $code = preg_replace('/\D/', '', $code) ?? '';

        if (mb_strlen($code) !== 6 || ! $twoFactor->secret) {
            return false;
        }

        return (bool) $this->engine()->verifyKey((string) $twoFactor->secret, $code, 1);
    }

    /**
     * Spend a recovery code.
     *
     * A used code is removed from the set rather than marked, so it cannot be
     * replayed, and the count on the settings page is simply what is left.
     */
    public function useRecoveryCode(User $user, string $code): bool
    {
        $twoFactor = $user->twoFactor;

        if (! $twoFactor) {
            return false;
        }

        $codes = (array) ($twoFactor->recovery_codes ?? []);
        $candidate = $this->normaliseRecoveryCode($code);

        $match = null;

        // hash_equals over every code rather than in_array, so how long the check
        // takes does not depend on how close the guess was.
        foreach ($codes as $stored) {
            if (hash_equals($this->normaliseRecoveryCode($stored), $candidate)) {
                $match = $stored;
            }
        }

        if ($match === null) {
            return false;
        }

        $twoFactor->recovery_codes = array_values(array_filter(
            $codes,
            fn (string $stored) => $stored !== $match
        ));

        $twoFactor->last_used_at = now();
        $twoFactor->save();

        app(ActivityLogService::class)->logActivity(ActivityActionEnum::TWO_FACTOR_RECOVERY_USED, model: $user);

        return true;
    }

    /**
     * Issue a fresh set, invalidating every code from the previous one.
     *
     * @return array<int, string>
     */
    public function regenerateRecoveryCodes(User $user): array
    {
        $twoFactor = $user->twoFactor;

        if (! $twoFactor) {
            return [];
        }

        $codes = $this->generateRecoveryCodes();

        $twoFactor->recovery_codes = $codes;
        $twoFactor->recovery_generated_count = (int) $twoFactor->recovery_generated_count + 1;
        $twoFactor->save();

        app(ActivityLogService::class)->logActivity(ActivityActionEnum::TWO_FACTOR_RECOVERY_REGENERATE, model: $user);

        return $codes;
    }

    /**
     * The recovery codes written out as the text file somebody downloads.
     *
     * Built here rather than in the page so the wording is the same whichever
     * screen offers the download, and so a test can assert on it without
     * rendering anything.
     *
     * @param  array<int, string>  $codes
     */
    public function recoveryCodeDocument(array $codes): string
    {
        // Defaulted to the app name rather than read bare: kSiteConfig() hands back
        // its array default for a key an install has never saved, and an unseeded
        // site would otherwise interpolate an array into the heading.
        $name = kSiteConfig('name', default: config('app.name'));

        $lines = [
            $name.' - two-factor recovery codes',
            'Generated '.now()->format('M d, Y'),
            '',
            'Each code works once. Keep this file somewhere you can reach without',
            'the device your authenticator app is on.',
            '',
            ...array_map(fn (string $code) => '  '.$code, $codes),
            '',
        ];

        return implode(PHP_EOL, $lines);
    }

    /**
     * What that file is called when it lands in somebody's downloads folder.
     */
    public function recoveryCodeFilename(): string
    {
        return kSlug(kSiteConfig('name', default: config('app.name'))).'-recovery-codes.txt';
    }

    // |||||||||||||||||||||||||||||||||||||||||||||||||||||||||||
    // REMEMBERED DEVICES

    /**
     * Mark this browser as one that may skip the prompt, and hand back the token
     * to put in a cookie. Only the hash is stored, so a leaked database does not
     * hand anybody a working second factor.
     */
    public function rememberDevice(User $user): ?string
    {
        $twoFactor = $user->twoFactor;

        if (! $twoFactor) {
            return null;
        }

        $token = Str::random(60);

        $twoFactor->remember_token = hash('sha256', $token);
        $twoFactor->remember_expires_at = now()->addDays(self::REMEMBER_DAYS);
        $twoFactor->save();

        return $token;
    }

    public function forgetDevices(User $user): void
    {
        $user->twoFactor()?->update([
            'remember_token' => null,
            'remember_expires_at' => null,
        ]);
    }

    public function rememberCookieName(): string
    {
        return 'two_factor_remember';
    }

    public function rememberDays(): int
    {
        return self::REMEMBER_DAYS;
    }

    // |||||||||||||||||||||||||||||||||||||||||||||||||||||||||||
    // PRIVATE

    /**
     * @return array<int, string>
     */
    private function generateRecoveryCodes(): array
    {
        return collect(range(1, self::RECOVERY_CODE_COUNT))
            ->map(fn () => mb_strtoupper(Str::random(5).'-'.Str::random(5)))
            ->all();
    }

    /**
     * Codes are shown with a dash and in capitals, but people retype them in all
     * sorts of ways. Comparison happens on the stripped form.
     */
    private function normaliseRecoveryCode(string $code): string
    {
        return mb_strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $code) ?? '');
    }
}
