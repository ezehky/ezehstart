{{--
    The page/letter design modal — shared between the template builder and the
    campaign builder's build step, always @include()'d against the host's own
    `$design` property (never a Blade component, so wire:model resolves against the
    host rather than a separate component boundary — same convention as _builder.blade.php).

    A modal, not a dropdown: every field is wire:model.live, and _builder.blade.php
    reads this same $design to paint the canvas, so a dropdown closing on outside
    click made it too easy to lose track of what had just changed before seeing it
    land. The modal name is page-global rather than per-block ("design-settings",
    not keyed to anything) because only one of these screens is ever open at a time.

    EmailRenderService::document() reads every key below with sensible defaults, so
    this is only the form; the render side already understands whatever is left
    unset.
--}}

<flux:modal.trigger name="design-settings">
    <flux:button variant="ghost" icon="swatch">Design</flux:button>
</flux:modal.trigger>

<flux:modal name="design-settings" class="w-full max-w-md">
    <div class="space-y-5">
        <div>
            <flux:heading size="lg">Design</flux:heading>
            <flux:subheading>Applies to the whole letter.</flux:subheading>
        </div>

        <div class="grid grid-cols-2 gap-3">
            <flux:input type="color" wire:model.live="design.brand" label="Brand" description="Buttons, links, the accent bar." />
            <flux:input type="color" wire:model.live="design.background" label="Page bg" description="Behind the letter." />
            <flux:input type="color" wire:model.live="design.container_background" label="Letter bg" description="The card blocks sit on." />
        </div>

        <flux:select wire:model.live="design.font_family" label="Typeface">
            <flux:select.option value="sans">Sans serif</flux:select.option>
            <flux:select.option value="serif">Serif</flux:select.option>
            <flux:select.option value="mono">Monospace</flux:select.option>
        </flux:select>

        <div class="grid grid-cols-2 gap-3">
            <flux:input type="number" min="320" max="800" wire:model.live.debounce.600ms="design.container_width" label="Width (px)" placeholder="640" />
            <flux:input type="number" min="0" max="32" wire:model.live.debounce.600ms="design.container_radius" label="Corner radius" placeholder="6" />
        </div>

        <flux:separator variant="subtle" />

        <flux:switch wire:model.live="design.accent_bar" label="Accent bar" description="A brand-coloured rule across the top of the letter." />
    </div>
</flux:modal>
