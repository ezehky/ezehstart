<?php

use App\Enums\ActivityActionEnum;
use App\Models\User;
use App\Services\ActivityLogService;
use App\Services\ImpersonationService;
use App\Services\TwoFactorService;
use App\Traits\WithFormResponseMessage;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Setting up, and taking down, the TOTP second factor.
 *
 * A component of its own rather than a section of one security screen, because
 * both workspaces need it: members on their security tab, administrators on their
 * profile. The challenge at sign-in is the same page for both.
 */
new class extends Component
{
    use WithFormResponseMessage;

    public User $user;

    public string $two_factor_code = '';

    /**
     * The QR code and secret, held only while enrolment is in progress. Both are
     * cleared the moment it finishes — there is no reason for the secret to keep
     * travelling to the browser on every subsequent request.
     */
    public ?string $twoFactorQr = null;

    public ?string $twoFactorSecret = null;

    /**
     * Recovery codes are shown exactly once, right after they are generated.
     * They are not re-readable afterwards by design: somebody who did not save
     * them regenerates a fresh set rather than being handed the old one again.
     */
    public array $recoveryCodes = [];

    public function mount(): void
    {
        // Closed while somebody is being impersonated, like the screens that host
        // it. Turning a member's second factor off from a support session is
        // exactly the takeover impersonation must not allow.
        abort_if(app(ImpersonationService::class)->isImpersonating(), 404);

        $this->user = auth()->user();
    }

    #[Computed]
    public function twoFactorAvailable(): bool
    {
        return app(TwoFactorService::class)->isAvailable();
    }

    #[Computed]
    public function twoFactorEnabled(): bool
    {
        return (bool) $this->user->twoFactor?->isEnabled();
    }

    #[Computed]
    public function recoveryCodesLeft(): int
    {
        return (int) $this->user->twoFactor?->remainingRecoveryCodes();
    }

    public function startTwoFactor(): void
    {
        abort_unless($this->twoFactorAvailable, 404);

        $service = app(TwoFactorService::class);

        $twoFactor = $service->beginEnrolment($this->user);

        $this->twoFactorQr = $service->qrCodeSvg($this->user, $twoFactor);
        $this->twoFactorSecret = $twoFactor->secret;

        $this->user->refresh();
        $this->reset('two_factor_code', 'recoveryCodes');
    }

    public function confirmTwoFactor(): void
    {
        abort_unless($this->twoFactorAvailable, 404);

        $this->validate(['two_factor_code' => ['required', 'digits:6']]);

        $this->respondError(
            'That code is not valid. Check your authenticator app and try again.',
            ! app(TwoFactorService::class)->confirm($this->user, $this->two_factor_code),
            fn () => $this->reset('two_factor_code'),
            'two_factor_code'
        );

        $this->user->refresh();

        $this->recoveryCodes = (array) $this->user->twoFactor?->recovery_codes;
        unset($this->recoveryCodesText);

        $this->reset('twoFactorQr', 'twoFactorSecret', 'two_factor_code');
        unset($this->twoFactorEnabled, $this->recoveryCodesLeft);

        $this->respondSuccess('Two-factor authentication is on. Save your recovery codes.');
    }

    public function cancelTwoFactor(): void
    {
        // Enrolment that was never confirmed leaves an unusable row behind, so
        // backing out removes it rather than leaving a half-set-up secret around.
        if (! $this->twoFactorEnabled) {
            $this->user->twoFactor()->delete();
            $this->user->refresh();
        }

        $this->reset('twoFactorQr', 'twoFactorSecret', 'two_factor_code');
        $this->resetValidation();
    }

    public function regenerateRecoveryCodes(): void
    {
        abort_unless($this->twoFactorEnabled, 404);

        $this->recoveryCodes = app(TwoFactorService::class)->regenerateRecoveryCodes($this->user);

        $this->user->refresh();
        unset($this->recoveryCodesLeft, $this->recoveryCodesText);

        $this->respondSuccess('A new set of recovery codes has been generated. The old ones no longer work.');
    }

    /**
     * The codes as one block of text, for the clipboard.
     *
     * Joined here rather than in the Alpine expression: a newline escape has to
     * survive Blade and then the HTML attribute parser to get there, and a
     * string literal broken in transit is a copy button that never works.
     */
    #[Computed]
    public function recoveryCodesText(): string
    {
        return implode(PHP_EOL, $this->recoveryCodes);
    }

    /**
     * Hand the codes over as a text file.
     *
     * Offered on the same terms as the copy button: only while the set is on
     * screen, never again after a reload. Codes are stored encrypted rather than
     * hashed, so handing them back later would be possible — it is deliberately
     * not offered, because "shown once" is the promise the callout makes.
     *
     * The file is still built from the stored set rather than from the public
     * property, which has been to the browser and back.
     */
    public function downloadRecoveryCodes(): ?StreamedResponse
    {
        abort_unless($this->twoFactorEnabled, 404);

        $this->respondError(
            'Recovery codes can only be downloaded when they are first shown.',
            if: $this->recoveryCodes === [],
        );

        $service = app(TwoFactorService::class);
        $codes = (array) $this->user->twoFactor?->recovery_codes;

        $this->respondError('There are no recovery codes to download.', if: $codes === []);

        app(ActivityLogService::class)->logActivity(
            ActivityActionEnum::DOWNLOAD,
            ' their two-factor recovery codes',
        );

        return response()->streamDownload(
            fn () => print $service->recoveryCodeDocument($codes),
            $service->recoveryCodeFilename(),
            ['Content-Type' => 'text/plain'],
        );
    }

    public function disableTwoFactor(): void
    {
        abort_unless($this->twoFactorEnabled, 404);

        app(TwoFactorService::class)->disable($this->user);

        $this->user->refresh();
        $this->reset('twoFactorQr', 'twoFactorSecret', 'two_factor_code', 'recoveryCodes');
        unset($this->twoFactorEnabled, $this->recoveryCodesLeft);

        $this->respondSuccess('Two-factor authentication has been turned off.');
    }
};
?>

<div>
    @if ($this->twoFactorAvailable)
        <flux:card class="space-y-6">
            <div class="flex items-start justify-between gap-4">
                <div>
                    <flux:heading level="2" size="lg">Two-factor authentication</flux:heading>
                    <flux:text class="mt-1">
                        Ask for a code from your authenticator app as well as your password.
                    </flux:text>
                </div>
                @if ($this->twoFactorEnabled)
                    <flux:badge size="sm" color="lime">On</flux:badge>
                @endif
            </div>

            @if ($recoveryCodes)
                {{-- Shown once, immediately after generating. Never re-readable. --}}
                <flux:callout color="amber" icon="key">
                    <flux:callout.heading>Save your recovery codes</flux:callout.heading>
                    <flux:callout.text>
                        Each code works once, and this is the only time they are shown. Keep them
                        somewhere you can reach without this device.
                    </flux:callout.text>
                    {{-- The text is built on the server and handed over by @js rather
                         than joined in the expression: a newline escape does not
                         reliably survive a Blade attribute, and a broken string
                         literal here fails as a copy that silently never works. --}}
                    <div
                        x-data="{
                            text: @js($this->recoveryCodesText),
                            state: 'idle',
                            copy() {
                                this.write().then(() => this.settle('copied')).catch(() => this.settle('failed'))
                            },
                            write() {
                                if (navigator.clipboard && window.isSecureContext) {
                                    return navigator.clipboard.writeText(this.text)
                                }

                                return this.legacyWrite()
                            },
                            legacyWrite() {
                                const field = document.createElement('textarea')

                                field.value = this.text
                                field.setAttribute('readonly', '')
                                field.style.position = 'fixed'
                                field.style.opacity = '0'
                                document.body.appendChild(field)
                                field.select()

                                const copied = document.execCommand('copy')

                                document.body.removeChild(field)

                                return copied ? Promise.resolve() : Promise.reject()
                            },
                            settle(state) {
                                this.state = state
                                setTimeout(() => this.state = 'idle', 2500)
                            },
                        }"
                    >
                        <div class="mt-3 grid grid-cols-2 gap-2 font-mono text-sm">
                            @foreach ($recoveryCodes as $recoveryCode)
                                <span class="rounded bg-white/60 px-2 py-1 dark:bg-slate-900/60">{{ $recoveryCode }}</span>
                            @endforeach
                        </div>

                        <div class="mt-4 flex flex-wrap gap-3">
                            <flux:button
                                size="sm"
                                x-on:click="copy()"
                                x-bind:icon="state === 'copied' ? 'check' : 'clipboard-document'"
                            >
                                <span x-show="state === 'idle'">Copy codes</span>
                                <span x-show="state === 'copied'" x-cloak>Copied</span>
                                <span x-show="state === 'failed'" x-cloak>Copy failed</span>
                            </flux:button>

                            <flux:button size="sm" icon="arrow-down-tray" wire:click="downloadRecoveryCodes">
                                Download as .txt
                            </flux:button>
                        </div>
                    </div>
                </flux:callout>
            @endif

            @if ($twoFactorQr)
                <div class="space-y-4">
                    <flux:text size="sm">
                        Scan this with your authenticator app, then enter the six-digit code it shows.
                    </flux:text>

                    <div class="inline-block rounded-lg bg-white p-3">{!! $twoFactorQr !!}</div>

                    <flux:text size="sm">
                        Cannot scan it? Enter this key by hand:
                        <span class="font-mono select-all">{{ $twoFactorSecret }}</span>
                    </flux:text>

                    <form wire:submit="confirmTwoFactor" class="max-w-sm space-y-4">
                        <flux:field>
                            <flux:label>Authentication code</flux:label>
                            <flux:otp wire:model="two_factor_code" length="6" />
                            <flux:error name="two_factor_code" />
                        </flux:field>
                        <div class="flex gap-3">
                            <flux:button type="submit" variant="primary">Turn it on</flux:button>
                            <flux:button type="button" variant="ghost" wire:click="cancelTwoFactor">Cancel</flux:button>
                        </div>
                    </form>
                </div>
            @elseif ($this->twoFactorEnabled)
                <div class="flex flex-wrap items-center gap-3">
                    <flux:text size="sm" class="grow">
                        {{ $this->recoveryCodesLeft }} recovery code(s) left.
                    </flux:text>
                    <flux:button size="sm" wire:click="regenerateRecoveryCodes">
                        Generate new recovery codes
                    </flux:button>
                    <flux:button size="sm" variant="danger" x-on:click="$flux.modal('disableTwoFactorModal').show()">
                        Turn off
                    </flux:button>
                </div>
            @else
                <flux:button variant="primary" icon="shield-check" wire:click="startTwoFactor">
                    Set up two-factor authentication
                </flux:button>
            @endif
        </flux:card>
    @endif

    <x-dashboard.confirm-modal
        name="disableTwoFactorModal"
        title="Turn off two-factor authentication?"
        icon="shield-exclamation"
        confirm="Turn it off"
        cancel="Keep it on"
        wire:click="disableTwoFactor"
    >
        Your account goes back to being protected by your password alone, and the recovery
        codes you saved stop working. You can set it up again at any time.
    </x-dashboard.confirm-modal>
</div>
