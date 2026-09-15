{{--
    A lightweight admin-facing approximation of one block, for the canvas. The real,
    email-safe markup a recipient gets is built by EmailRenderService — this view
    exists only so the admin can see roughly what they are building.
--}}

@php($align = ['left' => 'text-left', 'center' => 'text-center', 'right' => 'text-right'][$data['align'] ?? 'left'] ?? 'text-left')

@switch($case)
    @case(\App\Enums\EmailBlockTypeEnum::HEADING)
        <p class="{{ $align }} font-heading font-bold" style="color: {{ $data['color'] ?? '#0F172A' }}; font-size: {{ ['h1' => '26px', 'h2' => '20px', 'h3' => '16px'][$data['level'] ?? 'h1'] }}">
            {{ $data['text'] ?? '' }}
        </p>
        @break

    @case(\App\Enums\EmailBlockTypeEnum::PARAGRAPH)
        {{-- Trusted here: this is the admin's own live, unsaved input in their own
             session, the same trust level the rich-text editor itself renders at.
             The escaped/sanitized version is what actually ships — see
             EmailRenderService::renderBlock(). --}}
        <div class="{{ $align }} text-[14px] leading-relaxed" style="color: {{ $data['color'] ?? '#475569' }}">{!! $data['text'] ?? '' !!}</div>
        @break

    @case(\App\Enums\EmailBlockTypeEnum::BUTTON)
        <div class="{{ $align }}">
            <span class="inline-block rounded-lg px-5 py-2.5 text-[13px] font-bold" style="background: {{ $data['background'] ?? '#A3E635' }}; color: {{ $data['color'] ?? '#0F172A' }}">
                {{ $data['text'] ?? 'Click here' }}
            </span>
        </div>
        @break

    @case(\App\Enums\EmailBlockTypeEnum::DIVIDER)
        <hr class="border-t" style="border-color: {{ $data['color'] ?? '#E2E8F0' }}">
        @break

    @case(\App\Enums\EmailBlockTypeEnum::SPACER)
        <div style="height: {{ (int) ($data['height'] ?? 24) }}px"></div>
        @break

    @case(\App\Enums\EmailBlockTypeEnum::IMAGE)
        @php($image = ! empty($data['image_id']) ? \App\Models\Image::find($data['image_id']) : null)
        <div class="{{ $align }}">
            @if ($image)
                <img src="{{ $image->url() }}" alt="{{ $data['alt'] ?? '' }}" class="inline-block max-w-full" style="width: {{ $data['width'] ?? '100%' }}; border-radius: {{ (int) ($data['radius'] ?? 0) }}px">
            @else
                <div class="mx-auto flex h-32 w-full items-center justify-center rounded-lg bg-slate-100 text-slate-400 dark:bg-slate-800">
                    <flux:icon name="photo" class="size-6" />
                </div>
            @endif
        </div>
        @break

    @case(\App\Enums\EmailBlockTypeEnum::HTML)
        <div class="rounded border border-dashed border-slate-300 p-3 text-xs text-slate-500 dark:border-slate-600">
            <flux:icon name="code-bracket" class="mb-1 size-4" /> Custom HTML block
        </div>
        @break

    @case(\App\Enums\EmailBlockTypeEnum::DYNAMIC_CONTENT)
        @php($provider = app(\App\Services\DynamicContentRegistryService::class)->provider($data['content_type'] ?? 'post'))
        <div class="rounded-lg border border-slate-200 p-3 dark:border-slate-700">
            <div class="flex items-center gap-2 text-xs font-semibold text-lime-700 dark:text-lime-400">
                <flux:icon name="newspaper" class="size-4" />
                {{ $provider?->label() ?? 'Content' }} · {{ ucfirst($data['mode'] ?? 'latest') }} · {{ $data['limit'] ?? 1 }} item(s) · {{ ucfirst(str_replace('_', ' ', $data['layout'] ?? 'featured')) }}
            </div>
        </div>
        @break

    @case(\App\Enums\EmailBlockTypeEnum::RELATED_CONTENT)
        <div class="rounded-lg border border-slate-200 p-3 dark:border-slate-700">
            <p class="mb-1 text-center text-xs font-semibold">{{ $data['heading'] ?? 'You May Also Like' }}</p>
            <div class="flex items-center justify-center gap-2 text-[11px] text-slate-400">
                <flux:icon name="squares-2x2" class="size-3.5" /> {{ $data['limit'] ?? 3 }} related item(s)
            </div>
        </div>
        @break

    @case(\App\Enums\EmailBlockTypeEnum::COLUMNS)
        @php($rowBgImage = ! empty($data['background_image_id']) ? \App\Models\Image::find($data['background_image_id']) : null)
        <div
            class="grid gap-3 rounded p-2"
            style="grid-template-columns: repeat({{ count($data['columns'] ?? []) ?: 2 }}, 1fr); background-color: {{ $data['background'] ?? 'transparent' }}; {{ $rowBgImage ? 'background-image:url('.$rowBgImage->url().');background-size:cover;background-position:center;' : '' }}"
        >
            @foreach ($data['columns'] ?? [] as $column)
                @php($colBgImage = ! empty($column['background_image_id']) ? \App\Models\Image::find($column['background_image_id']) : null)
                <div
                    class="rounded border border-dashed border-slate-200 p-2 text-xs text-slate-500 dark:border-slate-700"
                    style="background-color: {{ $column['background'] ?? 'transparent' }}; {{ $colBgImage ? 'background-image:url('.$colBgImage->url().');background-size:cover;background-position:center;' : '' }}"
                >
                    @if (($column['type'] ?? 'text') === 'image')
                        @php($colImage = ! empty($column['image_id']) ? \App\Models\Image::find($column['image_id']) : null)
                        @if ($colImage)
                            <img src="{{ $colImage->url() }}" alt="" class="w-full rounded">
                        @else
                            <flux:icon name="photo" class="mx-auto size-4 text-slate-300" />
                        @endif
                    @else
                        {{ $column['text'] ?? '' }}
                    @endif
                </div>
            @endforeach
        </div>
        @break

    @case(\App\Enums\EmailBlockTypeEnum::LOGO)
    @case(\App\Enums\EmailBlockTypeEnum::LOGO_DARK)
        @php($src = kSiteConfig($case->isLogoDark() ? 'logo-dark' : 'logo'))
        <div class="{{ $align }}">
            @if ($src)
                <img src="{{ $src }}" alt="" style="width: {{ $data['width'] ?? '160px' }}" class="inline-block">
            @else
                <div class="inline-flex items-center gap-1 rounded border border-dashed border-slate-200 px-2 py-1 text-xs text-slate-400 dark:border-slate-700">
                    <flux:icon name="photo" class="size-3.5" /> No {{ $case->isLogoDark() ? 'dark logo' : 'logo' }} set in Site Config
                </div>
            @endif
        </div>
        @break

    @case(\App\Enums\EmailBlockTypeEnum::FAVICON)
        @php($src = kSiteConfig('favicon'))
        <div class="{{ $align }}">
            @if ($src)
                <img src="{{ $src }}" alt="" style="width: {{ $data['width'] ?? '32px' }}" class="inline-block">
            @else
                <div class="inline-flex items-center gap-1 rounded border border-dashed border-slate-200 px-2 py-1 text-xs text-slate-400 dark:border-slate-700">
                    <flux:icon name="star" class="size-3.5" /> No favicon set in Site Config
                </div>
            @endif
        </div>
        @break

    @case(\App\Enums\EmailBlockTypeEnum::SOCIALS)
        @php($links = ($data['source'] ?? 'config') === 'custom' ? ($data['custom_links'] ?? []) : (array) kSiteConfig('social-handles', default: []))
        <div class="flex {{ ($data['align'] ?? 'center') === 'left' ? 'justify-start' : (($data['align'] ?? 'center') === 'right' ? 'justify-end' : 'justify-center') }} gap-2">
            @forelse ($links as $link)
                @php($handle = \App\Enums\SocialHandleEnum::tryFrom($link['platform'] ?? ''))
                @if (($data['style'] ?? 'image') === 'image' && $handle)
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
