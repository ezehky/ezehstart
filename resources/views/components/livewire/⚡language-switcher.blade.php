<?php

use App\Enums\LocaleEnum;
use App\Services\ImpersonationService;
use App\Services\LocaleService;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * The flag menu that changes the interface language.
 *
 * A component rather than a form post because it sits in three layouts — the
 * public site, the sign-in screens and the dashboard bar — and each of those is
 * already a Livewire page. Choosing reloads the page it was chosen on, since
 * every string on it, including the layout's, has to be rendered again.
 */
new class extends Component
{
    /**
     * Show the language name beside the flag, for the wider bars.
     */
    public bool $withLabel = false;

    #[Computed]
    public function enabled(): bool
    {
        return app(LocaleService::class)->isSwitcherEnabled();
    }

    /**
     * @return array<int, LocaleEnum>
     */
    #[Computed]
    public function locales(): array
    {
        return LocaleEnum::available();
    }

    #[Computed]
    public function current(): LocaleEnum
    {
        return app(LocaleService::class)->current();
    }

    public function choose(string $locale)
    {
        $choice = LocaleEnum::tryFrom($locale);

        abort_unless($choice && $this->enabled, 404);

        // Held in the session only while an administrator is viewing the site as
        // somebody else — the member's own saved preference is theirs to change.
        $user = app(ImpersonationService::class)->isImpersonating() ? null : auth()->user();

        app(LocaleService::class)->choose($choice, $user);

        return $this->redirect(url()->previous() ?: route('home'));
    }
};
?>

<div>
    @if ($this->enabled)
        <flux:dropdown position="bottom" align="end">
            <flux:button variant="ghost" size="sm" :aria-label="__('Change language')" class="gap-1.5">
                <span class="text-base leading-none">{{ $this->current->flag() }}</span>
                @if ($withLabel)
                    <span>{{ $this->current->label() }}</span>
                @else
                    <span class="text-xs font-semibold uppercase">{{ $this->current->value }}</span>
                @endif
            </flux:button>

            <flux:menu>
                @foreach ($this->locales as $locale)
                    <flux:menu.item
                        wire:key="locale-{{ $locale->value }}"
                        wire:click="choose('{{ $locale->value }}')"
                        :icon:trailing="$locale === $this->current ? 'check' : null"
                    >
                        <span class="me-2 text-base leading-none">{{ $locale->flag() }}</span>
                        <span lang="{{ $locale->value }}" dir="{{ $locale->direction() }}">{{ $locale->label() }}</span>
                    </flux:menu.item>
                @endforeach
            </flux:menu>
        </flux:dropdown>
    @endif
</div>
