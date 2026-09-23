<?php

use App\Models\User;
use App\Services\ImpersonationService;
use App\Services\PasskeyService;
use App\Traits\WithFormResponseMessage;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * The passkeys an account holds, and the button that adds another.
 *
 * A card of its own rather than a section of one security screen, because both
 * workspaces need it: members on their security tab, administrators on their
 * profile. The ceremony itself runs in resources/js/passkey.js.
 */
new class extends Component
{
    use WithFormResponseMessage;

    public User $user;

    /**
     * What the account holder calls this device. Asked for before the prompt so
     * the list says "Work laptop" rather than "Passkey 3".
     */
    public string $name = '';

    public ?int $removingId = null;

    public function mount(): void
    {
        // Closed while an administrator is acting as somebody else, like every
        // screen that changes how an account is reached. A support session that
        // could plant its own key on a member would outlive the impersonation.
        abort_if(app(ImpersonationService::class)->isImpersonating(), 404);

        $this->user = auth()->user();
    }

    #[Computed]
    public function available(): bool
    {
        return app(PasskeyService::class)->isAvailable();
    }

    #[Computed]
    public function passkeys(): Collection
    {
        return $this->user->passkeys()->latest('id')->get(['id', 'name', 'last_used_at', 'created_at']);
    }

    protected function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
        ];
    }

    /**
     * Validate the name, then hand the browser a challenge. Null when the name
     * failed — the error is already on the field and there is nothing to prompt.
     */
    public function passkeyOptions(): ?string
    {
        abort_unless($this->available, 404);

        $this->validate();

        return app(PasskeyService::class)->registrationOptions($this->user);
    }

    public function storePasskey(string $credential): bool
    {
        abort_unless($this->available, 404);

        $this->validate();

        $passkey = app(PasskeyService::class)->register($this->user, $credential, $this->name);

        $this->respondError(
            'That passkey could not be saved. Please try again.',
            ! $passkey,
            field: 'name',
        );

        $this->reset('name');
        unset($this->passkeys);

        return $this->respondSuccess('Passkey added. You can use it to sign in from now on.');
    }

    public function confirmRemove(int $passkeyId): void
    {
        $this->removingId = $passkeyId;

        $this->js("\$flux.modal('removePasskeyModal').show()");
    }

    public function removePasskey(): bool
    {
        $removed = $this->removingId && app(PasskeyService::class)->delete($this->user, $this->removingId);

        $this->reset('removingId');

        $this->respondError('That passkey is no longer on your account.', ! $removed);

        unset($this->passkeys);

        return $this->respondSuccess('Passkey removed.');
    }
};
?>

<div>
    @if ($this->available)
        <flux:card class="space-y-6" x-data="passkey">
            <div>
                <flux:heading level="2" size="lg">Passkeys</flux:heading>
                <flux:text class="mt-1">
                    Sign in with your fingerprint, face or device PIN instead of a password. The key
                    stays on your device — we only ever see its public half.
                </flux:text>
            </div>

            @if ($this->passkeys->isNotEmpty())
                <ul class="divide-y divide-slate-100 dark:divide-slate-800">
                    @foreach ($this->passkeys as $passkey)
                        <li wire:key="passkey-{{ $passkey->id }}" class="flex items-center justify-between gap-3 py-3 first:pt-0 last:pb-0">
                            <div class="flex items-center gap-3">
                                <x-dashboard.icon-box size="sm" icon="finger-print" tone="emerald" />
                                <div>
                                    <p class="text-sm font-semibold text-slate-950 dark:text-white">{{ $passkey->name }}</p>
                                    <p class="text-xs text-slate-500 dark:text-slate-400">
                                        {{-- The package's model, not ours, so it has no
                                             ->fooHuman() getters to lean on. --}}
                                        Added {{ kDatetimeConverter($passkey->created_at, $user, dateFormat: true) }}
                                        · {{ $passkey->last_used_at ? 'Last used '.kDatetimeConverter($passkey->last_used_at, $user, diffForHumans: true) : 'Never used' }}
                                    </p>
                                </div>
                            </div>
                            <flux:button size="sm" variant="ghost" wire:click="confirmRemove({{ $passkey->id }})">
                                Remove
                            </flux:button>
                        </li>
                    @endforeach
                </ul>
            @endif

            <template x-if="! supported">
                <flux:callout color="amber" icon="exclamation-triangle" class="text-sm">
                    This browser cannot create passkeys. Try a recent version of Chrome, Safari, Edge or Firefox.
                </flux:callout>
            </template>

            <form x-show="supported" x-on:submit.prevent="register()" class="flex max-w-md flex-col gap-3 sm:flex-row sm:items-start">
                <div class="grow">
                    <flux:input wire:model="name" placeholder="Name this device, e.g. Work laptop" aria-label="Passkey name" />
                    <flux:error name="name" />
                </div>
                <flux:button type="submit" variant="primary" icon="finger-print" x-bind:disabled="busy">
                    Add a passkey
                </flux:button>
            </form>

            <p x-show="error" x-text="error" x-cloak class="text-sm text-rose-600 dark:text-rose-400"></p>
        </flux:card>

        <x-dashboard.confirm-modal
            name="removePasskeyModal"
            title="Remove this passkey?"
            icon="finger-print"
            confirm="Remove it"
            cancel="Keep it"
            wire:click="removePasskey"
        >
            The device that holds it will no longer sign you in. Your password and any other
            passkeys keep working.
        </x-dashboard.confirm-modal>
    @endif
</div>
