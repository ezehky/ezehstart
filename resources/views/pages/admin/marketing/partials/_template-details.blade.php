{{--
    Step 1 of the template builder — the same shape as _campaign-details.blade.php:
    the name and description a template needs before there is anything to build.
    Footer and Design stay on the builder step, next to the canvas, because both
    are about the letter itself rather than the template row.
--}}

<flux:card class="mx-auto max-w-2xl space-y-5">
    <div>
        <flux:heading level="2" size="lg">Template details</flux:heading>
        <flux:text class="mt-1">Everything here can change later — the canvas is next.</flux:text>
    </div>

    <flux:input wire:model="name" label="Template name" placeholder="e.g. Newsletter Template" />
    <flux:input wire:model="description" label="Description" description="Internal only — shown to admins picking a starting point for a campaign." />

    <div class="flex justify-end gap-2">
        <flux:button href="{{ route('admin.marketing.templates') }}" wire:navigate variant="ghost">Cancel</flux:button>
        <x-dashboard.gate.button
            gate="marketing.templates"
            :level="$template ? $gateModify : $gateCreate"
            wire:click="saveDetails"
            icon:trailing="arrow-right"
        >
            Continue to Builder
        </x-dashboard.gate.button>
    </div>
</flux:card>
