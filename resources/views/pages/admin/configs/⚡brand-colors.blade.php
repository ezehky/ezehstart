<?php

use App\Enums\BrandPaletteEnum;
use App\Services\BrandColorService;
use App\Traits\WithGateProps;
use App\Traits\WithSiteConfigProcessor;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * The install's brand colour.
 *
 * One build serves every project made from the kit: BrandColorService turns what
 * is saved here into CSS variables the base layout prints over the compiled
 * stylesheet, so every brand-* utility, Flux's accent and the email builder's
 * "brand" swatch follow it. Nothing is rebuilt.
 */
new class extends Component
{
    use WithGateProps, WithSiteConfigProcessor;

    protected function configSubject(): string
    {
        return 'brand colour';
    }

    public function mount(): void
    {
        kSetSiteTitle('config', 'brand-colors');
        $this->setPageGate('config.brand-colors');
        $this->setConfigInitial();
    }

    protected function rules(): array
    {
        $rules = [
            'config.brand.palette' => ['required', Rule::enum(BrandPaletteEnum::class)],
        ];

        // A preset brings its own hex, so the field is only asked for while it
        // is on screen.
        if ($this->palette()->isCustom()) {
            $rules['config.brand.primary'] = ['required', 'regex:/^#?([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/'];
        }

        return $rules;
    }

    protected function messages(): array
    {
        return [
            'config.brand.primary.regex' => 'Enter a hex colour such as #2dd4b5.',
        ];
    }

    /**
     * Picking a preset also copies its hex into the custom field, so switching
     * to Custom afterwards starts from the colour already on screen rather than
     * from whatever was typed last time.
     */
    public function pickPalette(string $value): void
    {
        $palette = BrandPaletteEnum::tryFrom($value);

        if (! $palette) {
            return;
        }

        $this->config['brand']['palette'] = $palette->value;

        if (! $palette->isCustom()) {
            $this->config['brand']['primary'] = $palette->primary();
        }

        $this->resetValidation();
    }

    public function save(): bool
    {
        $this->checkGate();

        $this->validate();

        $this->config['brand']['primary'] = app(BrandColorService::class)->normalize(data_get($this->config, 'brand.primary'))
            ?? BrandPaletteEnum::TEAL->primary();

        $this->saveConfig(true);

        // A full reload rather than wire:navigate: the colour is printed in the
        // <head>, which a navigate swap keeps.
        $this->redirectRoute('admin.config.brand-colors');

        return $this->respondSuccess('Brand colour saved.');
    }

    private function palette(): BrandPaletteEnum
    {
        return BrandPaletteEnum::tryFrom((string) data_get($this->config, 'brand.palette')) ?? BrandPaletteEnum::TEAL;
    }

    /**
     * The ramp as it would be saved, for the preview. A half-typed hex previews
     * as the colour it replaced rather than as nothing.
     */
    #[Computed]
    public function preview(): array
    {
        $service = app(BrandColorService::class);
        $palette = $this->palette();

        $primary = $palette->isCustom()
            ? ($service->normalize(data_get($this->config, 'brand.primary')) ?? BrandPaletteEnum::TEAL->primary())
            : $palette->primary();

        $foreground = $service->foreground($primary);

        return [
            'scale' => $service->scale($primary),
            'foreground' => $foreground,
            'buttonContrast' => round($service->contrast($primary, $foreground), 1),
        ];
    }
};
?>

<form wire:submit="save" class="space-y-6">
    <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
        <flux:card class="space-y-4 lg:col-span-2">
            <div>
                <flux:heading level="2" size="lg">Palette</flux:heading>
                <flux:text class="mt-1">Buttons, links, highlights and the active sidebar item all take this colour.</flux:text>
            </div>

            <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                @foreach (BrandPaletteEnum::cases() as $option)
                    @php($selected = data_get($config, 'brand.palette') === $option->value)

                    <button
                        type="button"
                        wire:key="palette-{{ $option->value }}"
                        wire:click="pickPalette('{{ $option->value }}')"
                        @class([
                            'flex items-center gap-3 rounded-xl border p-3 text-left transition',
                            'border-brand-500 ring-2 ring-brand-500/30' => $selected,
                            'border-slate-200 hover:border-slate-300 dark:border-white/10 dark:hover:border-white/20' => ! $selected,
                        ])
                    >
                        @if ($option->isCustom())
                            <span class="flex size-10 shrink-0 items-center justify-center rounded-lg bg-slate-100 dark:bg-white/10">
                                <flux:icon name="eye-dropper" class="size-5 text-slate-500" />
                            </span>
                        @else
                            <span class="size-10 shrink-0 rounded-lg ring-1 ring-inset ring-black/10" style="background-color: {{ $option->primary() }}"></span>
                        @endif

                        <span class="text-sm font-medium text-slate-800 dark:text-slate-100">{{ $option->label() }}</span>

                        @if ($selected)
                            <flux:icon name="check-circle" variant="solid" class="ms-auto size-5 text-brand-500" />
                        @endif
                    </button>
                @endforeach
            </div>

            @if (data_get($config, 'brand.palette') === BrandPaletteEnum::CUSTOM->value)
                <flux:separator variant="subtle" />

                <div class="max-w-xs">
                    <x-form.color-field
                        wire:model.live.debounce.500ms="config.brand.primary"
                        label="Brand colour"
                    />
                    <flux:text size="sm" class="mt-2">Lands on the 400 shade; the lighter and darker shades are worked out from it.</flux:text>
                </div>
            @endif
        </flux:card>

        <flux:card class="space-y-5">
            <flux:heading level="2" size="lg">Preview</flux:heading>

            <div class="flex overflow-hidden rounded-lg ring-1 ring-inset ring-black/10">
                @foreach ($this->preview['scale'] as $shade => $hex)
                    <span
                        wire:key="shade-{{ $shade }}"
                        class="h-8 flex-1"
                        style="background-color: {{ $hex }}"
                        title="{{ $shade }} · {{ $hex }}"
                    ></span>
                @endforeach
            </div>

            <div class="space-y-3 rounded-xl border border-slate-200 p-4 dark:border-white/10">
                <p class="font-heading text-lg font-semibold" style="color: {{ $this->preview['scale'][700] }}">A heading in the brand</p>
                <span
                    class="inline-flex rounded-lg px-4 py-1.5 text-sm font-semibold"
                    style="background-color: {{ $this->preview['scale'][400] }}; color: {{ $this->preview['foreground'] }}"
                >
                    Primary button
                </span>
            </div>

            @if ($this->preview['buttonContrast'] < 4.5)
                <flux:text size="sm">
                    Button text reads at {{ $this->preview['buttonContrast'] }}:1. Fine for a bold label;
                    a deeper colour reads better for small text.
                </flux:text>
            @endif
        </flux:card>
    </div>

    <x-util.floating-actions>
        <x-dashboard.gate.button
            :gate="$pageGate"
            :level="$gateModify"
            type="submit"
            variant="primary"
            icon="check"
        >
            Save colour
        </x-dashboard.gate.button>
    </x-util.floating-actions>
</form>
