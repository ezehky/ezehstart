<div class="space-y-4">
    {{-- The three panes fill what the full-screen chrome leaves, rather than a
         fraction of the viewport: this screen is the window now, so a canvas that
         stopped at 75vh would leave a band of nothing under it. --}}
    @include('pages.admin.marketing.partials._builder', [
        'allowSectionBlocks' => true,
        // 'canvasHeight' => 'h-[calc(100vh-20rem)] min-h-96',
        'canvasHeight' => 'h-[80vh] min-h-96',
    ])

    <div class="flex justify-between">
        <flux:button wire:click="$set('step', 'details')" variant="ghost" icon="arrow-left">Back</flux:button>
        <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
            <div class="flex items-end gap-2">
                <flux:select wire:model.live="footer_section_id" class="sm:max-w-xs">
                    <flux:select.option value="">No footer</flux:select.option>
                    @foreach ($this->footers as $footer)
                        <flux:select.option value="{{ $footer->id }}">{{ $footer->name }}</flux:select.option>
                    @endforeach
                </flux:select>
                @include('pages.admin.marketing.partials._design-settings')
            </div>

            <div class="flex gap-2">
                <flux:button wire:click="saveBuilder" variant="ghost" icon="check">Save Draft</flux:button>
                <flux:button wire:click="openPreview" variant="subtle" icon="eye">Preview</flux:button>
            </div>
        </div>
        <flux:button wire:click="saveBuilder('recipients')" variant="primary" icon:trailing="arrow-right">
            Continue to Recipients
        </flux:button>
    </div>
</div>
