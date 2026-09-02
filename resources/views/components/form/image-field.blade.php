@props([
	'label' => 'File',
	'formats' => "JPG, JPEG, PNG, WEBP",
	'maxSize' => '300 KB',
	'default' => null,
	'temporary' => null,
])

@php
	$errorName = $attributes->get('wire:model') ?? $attributes->get('name');
	$wireModel = $attributes->get('wire:model');
@endphp

<flux:field class="space-y-2">
	<div class="flex items-center justify-between gap-4">
		<flux:label>{{ $label }}</flux:label>
	</div>

    <div
        x-data="{ uploading: false, progress: 0, fileName: null }"
        x-on:livewire-upload-start="uploading = true"
        x-on:livewire-upload-finish="uploading = false"
        x-on:livewire-upload-cancel="uploading = false"
        x-on:livewire-upload-error="uploading = false"
        x-on:livewire-upload-progress="progress = $event.detail.progress"
    >
        <input
            x-ref="input"
            type="file"
            class="sr-only"
            x-on:change="fileName = $event.target.files[0]?.name ?? null"
            {{ $attributes->merge(['accept' => 'image/jpeg,image/png,image/webp']) }}
        />

        <button
            type="button"
            x-on:click="$refs.input.click()"
            class="flex w-full items-center gap-3 rounded-lg border border-dashed border-zinc-300 bg-zinc-50 px-4 py-3 text-left transition hover:border-zinc-400 hover:bg-zinc-100 focus:outline-none focus-visible:ring-2 focus-visible:ring-zinc-900 focus-visible:ring-offset-2 dark:border-white/15 dark:bg-white/5 dark:hover:border-white/30 dark:hover:bg-white/10 dark:focus-visible:ring-white dark:focus-visible:ring-offset-zinc-950"
        >
            <div class="flex size-9 shrink-0 items-center justify-center overflow-hidden rounded-md bg-zinc-200 text-zinc-600 dark:bg-white/10 dark:text-zinc-300">
                @if ($temporary || $default)
                    <img src="{{ $temporary ?? $default }}" alt="Selected image preview" class="size-full object-cover" />
                @else
                    <flux:icon.cloud-arrow-up class="size-5" />
                @endif
            </div>

            <div class="min-w-0 flex-1 space-y-2">
                <p x-show="! uploading" class="truncate text-sm font-semibold text-zinc-950 dark:text-white" x-text="fileName ?? 'Click to browse'"></p>
                <div x-cloak x-show="uploading" class="flex items-center gap-3" aria-live="polite">
                    <progress
                        max="100"
                        x-bind:value="progress"
                        class="h-1.5 min-w-0 flex-1 appearance-none overflow-hidden rounded-full bg-zinc-200 [&::-moz-progress-bar]:rounded-full [&::-moz-progress-bar]:bg-zinc-900 [&::-webkit-progress-bar]:rounded-full [&::-webkit-progress-bar]:bg-zinc-200 [&::-webkit-progress-value]:rounded-full [&::-webkit-progress-value]:bg-zinc-900 dark:bg-white/10 dark:[&::-moz-progress-bar]:bg-white dark:[&::-webkit-progress-bar]:bg-white/10 dark:[&::-webkit-progress-value]:bg-white"
                        aria-label="Image upload progress"
                    ></progress>
                    <span class="shrink-0 text-xs font-semibold text-zinc-600 dark:text-zinc-300" x-text="`${progress}%`"></span>
                </div>

                <div class="flex flex-wrap justify-between gap-x-3 gap-y-1 text-xs text-zinc-500 dark:text-zinc-400">
                    @if ($formats)
                        <span class="flex items-center gap-1">
                            <flux:icon.check class="size-4" hidden /> {{ $formats }}
                        </span>
                    @endif

                    @if ($maxSize)
                        <span>{{ $maxSize }}</span>
                    @endif
                </div>
            </div>
        </button>
    </div>

	@if ($errorName)
		<flux:error name="{{ $errorName }}" />
	@endif
</flux:field>
