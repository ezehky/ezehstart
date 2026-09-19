@props(['case', 'css'])

@php
    $srcName = $case->isLogoDark() ? 'logo-dark' : ($case->isFavicon() ? 'favicon' : 'logo');
    $src = kSiteConfig($srcName);
@endphp
@if ($src)
    <img src="{{ $src }}" alt="{{ $srcName }}" style="{{ $css['style'] }}">
@else
    <div class="inline-flex items-center gap-1 rounded border border-dashed border-slate-200 px-2 py-1 text-xs text-slate-400 dark:border-slate-700">
        <flux:icon name="photo" class="size-3.5" /> No {{ $srcName }} set.
    </div>
@endif
