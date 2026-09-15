{{--
    Step 2 of the template builder — the canvas, with Footer and Design next to
    it exactly as _campaign-builder-step.blade.php places them for a campaign.
    calc(100vh-19rem)
--}}

<div class="space-y-4">
    @include('pages.admin.marketing.partials._builder', [
        'allowSectionBlocks' => true,
        'canvasHeight' => 'h-[80vh] min-h-96',
    ])

    <div class="flex flex-wrap items-center justify-between gap-3">
        <flux:button wire:click="$set('step', 'details')" variant="ghost" icon="arrow-left">Back</flux:button>

        <div class="flex items-end gap-2">
            <flux:select wire:model.live="footer_section_id" class="max-w-xs">
                <flux:select.option value="">No footer</flux:select.option>
                @foreach ($this->footers as $footer)
                    <flux:select.option value="{{ $footer->id }}">{{ $footer->name }}</flux:select.option>
                @endforeach
            </flux:select>
            @include('pages.admin.marketing.partials._design-settings')
        </div>

        <x-dashboard.gate.button :gate="$pageGate" :level="$template ? $gateModify : $gateCreate" wire:click="save" icon="check">
            Save Template
        </x-dashboard.gate.button>
    </div>
</div>
