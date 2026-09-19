{{--
    A lightweight admin-facing approximation of one block, for the canvas. The real,
    email-safe markup a recipient gets is built by EmailRenderService — this view
    exists only so the admin can see roughly what they are building.
--}}

@switch($case)
    @case(\App\Enums\EmailBlockTypeEnum::HEADING)
        <p class="{{ $css['classes'] }} font-heading" style="{{ $css['style'] }}">
            {{ data_get($data, 'text') }}
        </p>
        @break

    @case(\App\Enums\EmailBlockTypeEnum::PARAGRAPH)
        {{-- Trusted here: this is the admin's own live, unsaved input in their own
             session, the same trust level the rich-text editor itself renders at.
             The escaped/sanitized version is what actually ships — see
             EmailRenderService::renderBlock(). --}}
        <div class="{{ $css['classes'] }} leading-relaxed" style="{{ $css['style'] }}">
            {!! data_get($data, 'text') !!}
        </div>
        @break

    @case(\App\Enums\EmailBlockTypeEnum::BUTTON)
        <span style="{{ $css['style'] }}">
            {{ data_get($data, 'text', 'Click here') }}
        </span>
        @break

    @case(\App\Enums\EmailBlockTypeEnum::DIVIDER)
        <hr style="{{ $css['style'] }}">
        @break

    @case(\App\Enums\EmailBlockTypeEnum::SPACER)
        <div style="{{ $css['style'] }}"></div>
        @break

    @case(\App\Enums\EmailBlockTypeEnum::IMAGE)
        @php($image = ! empty($data['image_id']) ? \App\Models\Image::find($data['image_id']) : null)
        @if ($image)
            <img
                src="{{ $image->url() }}"
                alt="{{ data_get($data, 'alt', 'Email Image') }}"
                class="max-w-full"
                style="{{ $css['style'] }}"
            >
        @else
            <div class="mx-auto flex h-32 w-full items-center justify-center rounded-lg bg-slate-100 text-slate-400 dark:bg-slate-800">
                <flux:icon name="photo" class="size-6" />
            </div>
        @endif
        @break

    @case(\App\Enums\EmailBlockTypeEnum::HTML)
        <div class="rounded border border-dashed border-slate-300 p-3 text-xs text-slate-500 dark:border-slate-600">
            <flux:icon name="code-bracket" class="mb-1 size-4" /> Custom HTML block
        </div>
        @break

    @case(\App\Enums\EmailBlockTypeEnum::DYNAMIC_CONTENT)
        @php($provider = app(\App\Services\DynamicContentRegistryService::class)->provider(data_get($data, 'content_type', 'post')))
        <div class="rounded-lg border border-slate-200 p-3 dark:border-slate-700">
            <div class="flex items-center gap-2 text-xs font-semibold text-lime-700 dark:text-lime-400">
                <flux:icon name="newspaper" class="size-4" />
                {{ $provider?->label() ?? 'Content' }} ·
                {{ ucfirst(data_get($data, 'mode', 'latest')) }} ·
                {{ data_get($data, 'limit', 1) }} item(s) ·
                {{ ucfirst(str_replace('_', ' ', data_get($data, 'layout', 'featured'))) }}
            </div>
        </div>
        @break

    @case(\App\Enums\EmailBlockTypeEnum::RELATED_CONTENT)
        <div class="rounded-lg border border-slate-200 p-3 dark:border-slate-700">
            <p class="mb-1 text-center text-xs font-semibold">{{ data_get($data, 'heading', 'You May Also Like') }}</p>
            <div class="flex items-center justify-center gap-2 text-[11px] text-slate-400">
                <flux:icon name="squares-2x2" class="size-3.5" /> {{ data_get($data, 'limit', 3) }} related item(s)
            </div>
        </div>
        @break

    {{-- COLUMNS has no case here: it's a container, rendered directly by
         _columns-canvas-item.blade.php (each column's children are individually
         selectable on canvas) rather than through this non-interactive preview. --}}

    @case(\App\Enums\EmailBlockTypeEnum::LOGO)
    @case(\App\Enums\EmailBlockTypeEnum::LOGO_DARK)
    @case(\App\Enums\EmailBlockTypeEnum::FAVICON)
        <x-marketing.logo-placeholder :$case :$css />
        @break

    @case(\App\Enums\EmailBlockTypeEnum::SOCIALS)
        @php($links = data_get($data, 'source', 'config') === 'custom' ? data_get($data, 'custom_links', []) : (array) kSiteConfig('social-handles', default: []))
        <div style="{{ $css['style'] }}">
            @forelse ($links as $link)
                @php($handle = \App\Enums\SocialHandleEnum::tryFrom(data_get($link, 'platform')))
                @if (data_get($data, 'style', 'image') === 'image' && $handle)
                    <div class="flex size-6 items-center justify-center rounded-full bg-slate-100 dark:bg-slate-800">
                        <flux:icon :name="$handle->icon()" class="size-3.5 text-slate-500 dark:text-slate-300" />
                    </div>
                @else
                    <span class="rounded bg-slate-100 px-2 py-1 text-[11px] font-medium text-slate-600 dark:bg-slate-800 dark:text-slate-300">
                        {{ $link['label'] ?? $handle?->label() ?? $link['platform'] ?? 'Link' }}
                    </span>
                @endif
            @empty
                <p class="text-xs text-slate-400">No social links configured.</p>
            @endforelse
        </div>
        @break

    @case(\App\Enums\EmailBlockTypeEnum::SECTION)
        @php($section = ! empty($data['email_section_id']) ? \App\Models\EmailSection::find($data['email_section_id']) : null)
        <div class="rounded-lg border border-sky-200 bg-sky-50 p-3 text-xs font-medium text-sky-700 dark:border-sky-900 dark:bg-sky-400/10 dark:text-sky-300">
            <flux:icon name="rectangle-stack" class="mb-1 size-4" />
            {{ $section?->name ?? 'Choose a saved section →' }}
        </div>
        @break
@endswitch
