@props([
    'name',
    'label' => 'Video',
    'description' => null,
    'videos' => null,
    'multiple' => false,
    'max' => null,
    'error' => null,
])

{{--
    One video slot on a form, drawn from what App\Traits\WithVideoPicker holds.

    The trait names the slots; this draws one of them and wires its three buttons
    back to the trait's chooseVideo(), removeVideo() and clearVideos(). Nothing in
    here knows what the videos are for, which is what lets a trailer and a playlist
    both be the same control:

        <x-form.video-slot name="trailer" label="Trailer" :videos="$this->slotVideos('trailer')" />
        <x-form.video-slot name="playlist" label="Playlist" :videos="$this->slotVideos('playlist')" multiple :max="8" />

    `name` rather than `slot`, because $slot is Blade's own. The sibling of
    x-form.image-slot, and deliberately the same control twice.
--}}

@php
    $videos = $videos ?? collect();
    $errorName = $error ?? "video_slots.{$name}.0";
@endphp

<flux:card class="space-y-3">
    <div>
        <flux:heading level="2" size="sm">{{ $label }}</flux:heading>

        @if ($description)
            <flux:text size="sm" class="mt-1">{{ $description }}</flux:text>
        @endif
    </div>

    @if ($videos->isEmpty())
        <flux:button size="sm" icon="film" type="button" class="w-full" wire:click="chooseVideo('{{ $name }}')">
            Choose from library
        </flux:button>
    @elseif ($multiple)
        <div class="grid grid-cols-2 gap-2 sm:grid-cols-3">
            @foreach ($videos as $video)
                <div wire:key="video-slot-{{ $name }}-{{ $video->id }}" class="group relative">
                    <x-form.video-thumb :video="$video" />

                    <flux:button
                        icon="x-mark"
                        size="xs"
                        variant="danger"
                        type="button"
                        title="Remove {{ $video->title }}"
                        class="absolute end-1 top-1 opacity-0 transition group-hover:opacity-100 focus-visible:opacity-100"
                        wire:click="removeVideo('{{ $name }}', {{ $video->id }})"
                    />
                </div>
            @endforeach
        </div>

        <div class="flex flex-wrap items-center gap-2">
            @unless ($max && $videos->count() >= $max)
                <flux:button size="sm" icon="plus" type="button" wire:click="chooseVideo('{{ $name }}')">
                    Add more
                </flux:button>
            @endunless

            <flux:button size="sm" variant="ghost" type="button" wire:click="clearVideos('{{ $name }}')">
                Remove all
            </flux:button>

            <flux:text size="sm" class="ms-auto">
                {{ $videos->count() }}{{ $max ? ' of '.$max : '' }} chosen
            </flux:text>
        </div>
    @else
        @php($video = $videos->first())

        <x-form.video-thumb :video="$video" />

        <div>
            <p class="truncate text-sm font-medium text-slate-950 dark:text-white">{{ $video->title }}</p>
            <p class="text-xs text-slate-500 dark:text-slate-400">{{ $video->provider->label() }}</p>
        </div>

        <div class="flex gap-2">
            <flux:button size="sm" type="button" wire:click="chooseVideo('{{ $name }}')">Replace</flux:button>
            <flux:button size="sm" variant="ghost" type="button" wire:click="clearVideos('{{ $name }}')">Remove</flux:button>
        </div>
    @endif

    <flux:error name="{{ $errorName }}" />
</flux:card>
