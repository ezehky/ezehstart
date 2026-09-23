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

@php($itemService = app(\App\Services\EmailBlockItemService::class))

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
            <x-form.color-field wire:model.live="design.brand" label="Brand" size="sm" description="Buttons, links, the accent bar." />
            <x-form.color-field wire:model.live="design.background" label="Page bg" size="sm" description="Behind the letter." />
            <x-form.color-field
                wire:model.live="design.container_background"
                label="Letter bg"
                description:trailing="The card blocks sit on."
                size="sm"
            />
            <x-marketing.fonts class="w-full" wire:model.live="design.font_family" label="Typeface" :service="$itemService" />
        </div>

        <div class="grid grid-cols-2 gap-3">
            <x-form.number-field
                wire:model.live.debounce.1000ms="design.container_width"
                label="Width (px)"
                description="The letter's maximum width."
                min="320"
                max="800"
                size="sm"
            />
        </div>
        <x-marketing.radius wire:model.live="design.container_radius" :service="$itemService" label="Corner radius" />

        <flux:separator variant="subtle" />

        <flux:switch wire:model.live="design.accent_bar" label="Accent bar" description="A brand-coloured rule across the top of the letter." />
    </div>
</flux:modal>
