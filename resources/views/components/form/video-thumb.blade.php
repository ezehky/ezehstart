@props(['video'])

{{--
    A still of one video with a play badge over it.

    Drawn in three places — the slot control, the picker grid and the library page —
    and a video with no thumbnail has to fall back rather than leave a hole, so the
    fallback lives here rather than being written out three times.

    Deliberately not a link and not an iframe: a form does not want a player it can
    lose focus into, and a grid of iframes is a grid of third-party page loads.
--}}

<div {{ $attributes->class('relative overflow-hidden rounded-lg border border-slate-200 dark:border-slate-700') }}>
    @if ($video->thumbnailUrl())
        <img
            src="{{ $video->thumbnailUrl() }}"
            alt="{{ $video->title }}"
            class="aspect-video w-full bg-slate-900 object-cover"
            loading="lazy"
        />
    @else
        {{-- Vimeo has no thumbnail URL that can be built without an API call, and a
             grid is not worth a round trip per tile to a third party. --}}
        <div class="flex aspect-video w-full items-center justify-center bg-slate-900">
            <flux:icon.film class="size-8 text-slate-500" />
        </div>
    @endif

    <span class="absolute inset-0 flex items-center justify-center">
        <span class="flex size-10 items-center justify-center rounded-full bg-black/55 text-white">
            <flux:icon.play class="size-4" />
        </span>
    </span>

    @if ($video->readableDuration())
        <span class="absolute end-1.5 bottom-1.5 rounded bg-black/70 px-1.5 py-0.5 text-xs font-medium text-white">
            {{ $video->readableDuration() }}
        </span>
    @endif
</div>
