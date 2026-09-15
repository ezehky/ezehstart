{{--
    The page/letter background dropdown — shared between the template builder and
    the campaign builder's build step, always @include()'d against the host's own
    `$design` property (never a Blade component, so wire:model resolves against the
    host rather than a separate component boundary — same convention as _builder.blade.php).

    EmailRenderService::document() already reads these three keys with sensible
    defaults; this is only the form that was missing, not new render logic.
--}}

<flux:dropdown position="bottom" align="end">
    <flux:button size="sm" variant="ghost" icon="swatch">Design</flux:button>
    <flux:menu class="w-72 space-y-4 p-4">
        <flux:input type="color" wire:model.live="design.background" label="Page background" description="Behind the letter — the blank canvas colour." />
        <flux:input type="color" wire:model.live="design.container_background" label="Letter background" description="The card the blocks sit on." />
        <flux:input type="number" min="320" max="800" wire:model.live="design.container_width" label="Width (px)" placeholder="640" />
    </flux:menu>
</flux:dropdown>
