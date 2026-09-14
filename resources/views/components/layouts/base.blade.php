<!doctype html>
<html lang="en">
	<head>
		<meta charset="utf-8">
		<meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
		<meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}"/>
        @php
            $siteName = $_configs['name'] ?? config('app.name');
            $meta = config('_setups.metadata');
            $pageTitle = trim(config('_setups.title') ? config('_setups.title') . ' | ' . $siteName : $siteName);
        @endphp

		<title>{{ $pageTitle }}</title>
		<meta name="author" content="{{ $siteName }}">
		<link rel="icon" type="image/x-icon" href="{{ $_configs['favicon'] ?? '' }}">

        {{-- SEO / social metadata — populated via kSetMetaData() --}}
        @if ($meta['description'] ?? null)
            <meta name="description" content="{{ $meta['description'] }}">
        @endif
        @if ($meta['key'] ?? null)
            <meta name="keywords" content="{{ $meta['key'] }}">
        @endif
        <link rel="canonical" href="{{ $meta['url'] ?? url()->current() }}">

        <meta property="og:site_name" content="{{ $siteName }}">
        <meta property="og:type" content="{{ $meta['type'] ?? 'website' }}">
        <meta property="og:title" content="{{ $pageTitle }}">
        <meta property="og:url" content="{{ $meta['url'] ?? url()->current() }}">
        @if ($meta['description'] ?? null)
            <meta property="og:description" content="{{ $meta['description'] }}">
        @endif
        @if ($meta['image'] ?? null)
            <meta property="og:image" content="{{ $meta['image'] }}">
        @endif

        <meta name="twitter:card" content="{{ ($meta['image'] ?? null) ? 'summary_large_image' : 'summary' }}">
        <meta name="twitter:title" content="{{ $pageTitle }}">
        @if ($meta['description'] ?? null)
            <meta name="twitter:description" content="{{ $meta['description'] }}">
        @endif
        @if ($meta['image'] ?? null)
            <meta name="twitter:image" content="{{ $meta['image'] }}">
        @endif

		@livewireStyles
        @fluxAppearance
        @vite(['resources/css/app.css', 'resources/js/app.js'])
        {{-- FONT --}}
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=inter:400,500,600&display=swap" rel="stylesheet" />
        {{-- FONT --}}
		@stack('styles')
	</head>

	<body
        x-data="animationOnScroll"
        {{
            $attributes->class([
                'antialiased font-sans',
                'custom-scrollbar',
                'bg-background-light text-[#0d121c] transition-colors duration-200 ease-out-strong dark:bg-background-dark dark:text-slate-100'
            ])->merge()
        }}
    >
        {{ $slot }}

        {{-- The cookie notice sits in the base shell rather than the public one:
             the session cookie it is about is set by signing in, so a notice that
             only appeared on the marketing pages would be describing the one part
             of the site that does not set it. --}}
        <x-site.cookie-banner />

        {{-- TOAST --}}
        @persist('toast')
            <flux:toast.group expanded>
                <flux:toast />
            </flux:toast.group>
        @endpersist
        {{-- TOAST --}}
		@stack('scripts')
        @fluxScripts
		@livewireScriptConfig
	</body>
</html>
