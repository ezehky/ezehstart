<?php

use App\Services\LocaleService;
use App\Services\PageTranslatorService;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * "Translate this page" — Google's machine translation of whatever is on screen.
 *
 * Sits beside the language switcher, which swaps the interface for a reviewed
 * lang file. This one reaches what no lang file can: posts, pages, anything
 * from the database. A component for the same reason the switcher is one: it
 * reads the site configuration, and the header it sits in is a plain partial.
 *
 * The whole thing is marked notranslate, or Google would translate the names
 * of the languages in its own menu. So is every Livewire component on the
 * public site: Google rewrites the text nodes Livewire morphs, and a form that
 * has been translated stops updating.
 */
new class extends Component
{
    #[Computed]
    public function enabled(): bool
    {
        return app(PageTranslatorService::class)->enabled();
    }

    #[Computed]
    public function languages(): array
    {
        return app(PageTranslatorService::class)->languages()->all();
    }

    /**
     * The flag of the language the page is written in, for the "Original" row.
     */
    #[Computed]
    public function originalFlag(): string
    {
        return app(LocaleService::class)->current()->flag();
    }
};
?>

<div class="notranslate" translate="no">
    @if ($this->enabled)
        <div x-data="pageTranslator({ languages: @js(array_keys($this->languages)), source: @js(app()->getLocale()) })">
            <flux:dropdown position="bottom" align="end">
                <flux:button variant="ghost" size="sm" icon="language" :aria-label="__('Translate this page')">
                    <span x-show="current" x-text="current" class="text-xs font-semibold uppercase" x-cloak></span>
                </flux:button>

                <flux:menu>
                    <flux:menu.heading>{{ __('Translate this page') }}</flux:menu.heading>

                    <flux:menu.item x-on:click="choose('')">
                        <flux:flag class="me-2 leading-none" :country="$this->originalFlag" size="xs" />
                        <span class="flex-1">{{ __('Original') }}</span>
                        <flux:icon.check variant="micro" class="ms-2" x-show="! current" />
                    </flux:menu.item>

                    @foreach ($this->languages as $code => $language)
                        <flux:menu.item wire:key="translate-{{ $code }}" x-on:click="choose(@js($code))">
                            <flux:flag class="me-2 leading-none" :country="$language['flag']" size="xs" />
                            <span class="flex-1" lang="{{ $code }}">{{ $language['native'] }}</span>
                            <flux:icon.check variant="micro" class="ms-2" x-show="current === @js($code)" x-cloak />
                        </flux:menu.item>
                    @endforeach
                </flux:menu>
            </flux:dropdown>

            <div id="google_translate_element" class="hidden"></div>
        </div>
    @endif
</div>
