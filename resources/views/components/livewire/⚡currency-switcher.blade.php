<?php

use App\Models\Currency;
use App\Services\CurrencyService;
use App\Services\ImpersonationService;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * The menu in the site header that changes which currency amounts are shown in.
 *
 * The sibling of the language switcher, and built the same way: choosing reloads
 * the page, since every amount on it was converted when it was rendered.
 */
new class extends Component
{
    /**
     * Hidden while an administrator is viewing the site as a member. Choosing
     * writes to the member's account, and the account is not the admin's to change.
     */
    #[Computed]
    public function enabled(): bool
    {
        return app(CurrencyService::class)->isSwitcherEnabled()
            && ! app(ImpersonationService::class)->isImpersonating();
    }

    /**
     * @return \Illuminate\Support\Collection<int, array>
     */
    #[Computed]
    public function currencies()
    {
        return app(CurrencyService::class)->active();
    }

    #[Computed]
    public function current(): array
    {
        return kActiveCurrency();
    }

    public function choose(int $currencyId)
    {
        $currency = Currency::query()->active()->find($currencyId);

        abort_unless($currency && $this->enabled, 404);

        app(CurrencyService::class)->choose($currency, auth()->user());

        return $this->redirect(url()->previous() ?: route('home'));
    }
};
?>

<div>
    @if ($this->enabled)
        <flux:dropdown position="bottom" align="end">
            <flux:button variant="ghost" size="sm" :aria-label="__('Change currency')" class="gap-1.5">
                <span class="text-xs font-semibold">{!! $this->current['symbol'] !!}</span>
                <span class="text-xs font-semibold uppercase">{{ $this->current['code'] }}</span>
            </flux:button>

            <flux:menu>
                @foreach ($this->currencies as $currency)
                    <flux:menu.item
                        wire:key="currency-{{ $currency['id'] }}"
                        wire:click="choose({{ $currency['id'] }})"
                        :icon:trailing="$currency['id'] === $this->current['id'] ? 'check' : null"
                    >
                        <span class="me-2 inline-block w-8 text-slate-400 dark:text-slate-500">{!! $currency['symbol'] !!}</span>
                        <span>{{ $currency['name'] }}</span>
                        <span class="ms-2 text-xs text-slate-400 dark:text-slate-500">{{ $currency['code'] }}</span>
                    </flux:menu.item>
                @endforeach
            </flux:menu>
        </flux:dropdown>
    @endif
</div>
