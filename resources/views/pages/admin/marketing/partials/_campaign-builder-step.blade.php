<div class="space-y-4">
    <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
        <flux:select wire:model.live="footer_section_id" label="Footer" class="sm:max-w-xs">
            <flux:select.option value="">No footer</flux:select.option>
            @foreach ($this->footers as $footer)
                <flux:select.option value="{{ $footer->id }}">{{ $footer->name }}</flux:select.option>
            @endforeach
        </flux:select>

        <div class="flex gap-2">
            <flux:button wire:click="saveBuilder" variant="ghost" icon="check">Save Draft</flux:button>
            <flux:button wire:click="openPreview" variant="subtle" icon="eye">Preview</flux:button>
        </div>
    </div>

    {{-- The three panes fill what the full-screen chrome leaves, rather than a
         fraction of the viewport: this screen is the window now, so a canvas that
         stopped at 75vh would leave a band of nothing under it. --}}
    @include('pages.admin.marketing.partials._builder', [
        'allowSectionBlocks' => true,
        'canvasHeight' => 'h-[calc(100vh-20rem)] min-h-96',
    ])

    <div class="flex justify-between">
        <flux:button wire:click="$set('step', 'details')" variant="ghost" icon="arrow-left">Back</flux:button>
        <flux:button wire:click="saveBuilder('recipients')" variant="primary" icon:trailing="arrow-right">Continue to Recipients</flux:button>
    </div>
</div>
