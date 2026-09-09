{{-- Stands in for wire:confirm everywhere a workspace asks before a destructive
     write. The browser's own dialog cannot be styled, cannot carry an amount or a
     consequence, and is suppressed outright in some in-app browsers — which would
     leave a member confirming nothing at all. --}}
@props([
    'name',
    'title' => 'Are you sure?',
    'confirm' => 'Confirm',
    'confirmIcon' => null,
    'cancel' => 'Cancel',
    'icon' => 'exclamation-triangle',
    'variant' => 'danger',
    'tone' => 'rose',
])

<flux:modal :name="$name" class="md:w-110">
    <div class="space-y-6">
        <div class="flex items-start gap-4">
            <x-dashboard.icon-box size="xs" :icon="$icon" :tone="$tone" />

            <div class="min-w-0">
                <flux:heading size="lg" class="font-heading font-bold">{{ $title }}</flux:heading>
                <flux:text class="mt-1">{{ $slot }}</flux:text>
            </div>
        </div>

        <div class="flex justify-end gap-3">
            <flux:modal.close>
                <flux:button type="button" variant="ghost">{{ $cancel }}</flux:button>
            </flux:modal.close>

            {{-- Dismissed here rather than in the action, so a guard that refuses the
                 write and throws still leaves the toast on screen and not the dialog. --}}
            <flux:button
                type="button"
                :variant="$variant"
                :icon="$confirmIcon"
                x-on:click="$flux.modal('{{ $name }}').close()"
                {{ $attributes->class('press') }}
            >
                {{ $confirm }}
            </flux:button>
        </div>
    </div>
</flux:modal>
