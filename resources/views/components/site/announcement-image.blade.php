{{-- An announcement's picture, wrapped in its link when it has one.

     Its own component because the popup draws it in three places — above the copy,
     beside it, and on its own — and the link has to follow it into every one. --}}
@props(['announcement'])

@php($image = $announcement?->image)

@if ($image)
    @if ($announcement->link_url)
        <a href="{{ $announcement->link_url }}" class="block focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-lime-500">
            <img src="{{ $image->url() }}" alt="{{ $image->alt_text ?: $announcement->title }}" {{ $attributes }} />
        </a>
    @else
        <img src="{{ $image->url() }}" alt="{{ $image->alt_text ?: $announcement->title }}" {{ $attributes }} />
    @endif
@endif
