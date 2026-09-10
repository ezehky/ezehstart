@props([
    'name',
    'label' => 'Image',
    'description' => null,
    'images' => null,
    'multiple' => false,
    'max' => null,
    'error' => null,
])

{{--
    One image slot on a form, drawn from what App\Traits\WithImagePicker holds.

    The trait names the slots; this draws one of them and wires its three buttons
    back to the trait's chooseImage(), removeImage() and clearImages(). Nothing in
    here knows what the images are for, which is what lets a cover, a gallery and a
    logo all be the same control:

        <x-form.image-slot name="cover" label="Cover image" :images="$this->slotImages('cover')" />
        <x-form.image-slot name="gallery" label="Gallery" :images="$this->slotImages('gallery')" multiple :max="6" />

    `name` rather than `slot`, because $slot is Blade's own.
--}}

@php
    $images = $images ?? collect();
    $errorName = $error ?? "image_slots.{$name}.0";
@endphp

<flux:card class="space-y-3">
    <div>
        <flux:heading level="2" size="sm">{{ $label }}</flux:heading>

        @if ($description)
            <flux:text size="sm" class="mt-1">{{ $description }}</flux:text>
        @endif
    </div>

    @if ($images->isEmpty())
        <flux:button size="sm" icon="photo" type="button" class="w-full" wire:click="chooseImage('{{ $name }}')">
            Choose from library
        </flux:button>
    @elseif ($multiple)
        <div class="grid grid-cols-3 gap-2">
            @foreach ($images as $image)
                <div wire:key="slot-{{ $name }}-{{ $image->id }}" class="group relative">
                    <img
                        src="{{ $image->url() }}"
                        alt="{{ $image->alt_text ?: $image->title }}"
                        class="aspect-square w-full rounded-lg border border-slate-200 object-cover dark:border-slate-700"
                        loading="lazy"
                    />

                    <flux:button
                        icon="x-mark"
                        size="xs"
                        variant="danger"
                        type="button"
                        title="Remove {{ $image->title }}"
                        class="absolute end-1 top-1 opacity-0 transition group-hover:opacity-100 focus-visible:opacity-100"
                        wire:click="removeImage('{{ $name }}', {{ $image->id }})"
                    />
                </div>
            @endforeach
        </div>

        <div class="flex flex-wrap items-center gap-2">
            @unless ($max && $images->count() >= $max)
                <flux:button size="sm" icon="plus" type="button" wire:click="chooseImage('{{ $name }}')">
                    Add more
                </flux:button>
            @endunless

            <flux:button size="sm" variant="ghost" type="button" wire:click="clearImages('{{ $name }}')">
                Remove all
            </flux:button>

            <flux:text size="sm" class="ms-auto">
                {{ $images->count() }}{{ $max ? ' of '.$max : '' }} chosen
            </flux:text>
        </div>
    @else
        @php($image = $images->first())

        <img
            src="{{ $image->url() }}"
            alt="{{ $image->alt_text ?: $image->title }}"
            class="aspect-video w-full rounded-lg object-cover"
        />

        <div class="flex gap-2">
            <flux:button size="sm" type="button" wire:click="chooseImage('{{ $name }}')">Replace</flux:button>
            <flux:button size="sm" variant="ghost" type="button" wire:click="clearImages('{{ $name }}')">Remove</flux:button>
        </div>
    @endif

    <flux:error name="{{ $errorName }}" />
</flux:card>
