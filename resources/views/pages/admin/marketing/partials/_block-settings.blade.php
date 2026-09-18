{{--
    The selected block's settings, one @case per EmailBlockTypeEnum. Every field binds
    to `$prefix.*`, which the caller (_builder.blade.php) computes via
    WithBlockEditor::pathFor() — "blocks.2.data" for a top-level block,
    "blocks.2.data.columns.1.blocks.0.data" for a block nested inside a column, so
    the same @switch below works unmodified regardless of where the block lives.

    Expects:
        $case    EmailBlockTypeEnum
        $block   array   the selected block itself (id/type/data)
        $prefix  string  the Livewire binding-path prefix, ending in ".data"
        $index   int     the selected block's TOP-LEVEL index — only meaningful
                           (and only used) by the COLUMNS case's addColumn()/
                           removeColumn()/addColumnBlock() calls, since a Columns
                           block is never itself nested inside another one
--}}

@php($data = $block['data'] ?? [])
{{-- @dump($data) --}}

@switch($case)
    @case(\App\Enums\EmailBlockTypeEnum::HEADING)
        <div class="space-y-4">
            <div>
                <div class="mb-1 flex items-center justify-between">
                    <span>
                        <flux:label class="sr-only">Heading text</flux:label>
                    </span>
                    @include('pages.admin.marketing.partials._personalize-menu', ['block' => $block, 'field' => 'text'])
                </div>
                <flux:textarea wire:model.live.debounce.1000ms="{{ $prefix }}.text" rows="2" placeholder="Heading..." />
            </div>
            <flux:radio.group wire:model.live="{{ $prefix }}.level" label="Heading level" variant="segmented" size="sm">
                @foreach (\App\Enums\EmailBlockTypeElementEnum::LEVEL->validItems() as $key => $value)
                    <flux:radio value="{{ $key }}" :label="str($key)->upper()" />
                @endforeach
            </flux:radio.group>
        </div>
        @break

    @case(\App\Enums\EmailBlockTypeEnum::PARAGRAPH)
        <div>
            <div class="mb-1 flex items-center justify-between">
                <flux:label>Text</flux:label>
                @include(
                    'pages.admin.marketing.partials._personalize-menu',
                    ['block' => $block, 'field' => 'text', 'richtext' => true]
                )
            </div>
            <x-form.rich-text wire:model="{{ $prefix }}.text" />
        </div>
        @break

    @case(\App\Enums\EmailBlockTypeEnum::BUTTON)
        <div class="space-y-4">
            <flux:input wire:model.live.debounce.1000ms="{{ $prefix }}.text" label="Button text" />
            <div>
                <div class="mb-1 flex items-center justify-between">
                    <flux:label>URL</flux:label>
                    @include('pages.admin.marketing.partials._personalize-menu', ['block' => $block, 'field' => 'url'])
                </div>
                <flux:input wire:model.live.debounce.1000ms="{{ $prefix }}.url" placeholder="https:// or &#123;&#123;unsubscribe_url&#125;&#125;" />
            </div>
            <flux:checkbox wire:model.live="{{ $prefix }}.new_tab" label="Open in new tab" />
            <flux:switch
                wire:model.live="{{ $prefix }}.full_width"
                label="Full width"
                description="Stretches to the letter's full content width."
            />
            <x-marketing.align wire:model.live="{{ $prefix }}.align" />
            <div class="grid grid-cols-2 gap-3">
                <x-form.color-field wire:model.live="{{ $prefix }}.background" label="Background" />
                <x-form.color-field wire:model.live="{{ $prefix }}.color" label="Text color" />
            </div>
        </div>
        @break

    @case(\App\Enums\EmailBlockTypeEnum::DIVIDER)
        <x-form.color-field wire:model.live="{{ $prefix }}.color" label="Line color" />
        @break

    @case(\App\Enums\EmailBlockTypeEnum::SPACER)
        <flux:input type="number" min="4" max="200" wire:model.live="{{ $prefix }}.height" label="Height (px)" />
        @break

    @case(\App\Enums\EmailBlockTypeEnum::IMAGE)
        @php($image = ! empty($data['image_id']) ? \App\Models\Image::find($data['image_id']) : null)
        <div class="space-y-4">
            <div>
                <flux:label>Image</flux:label>

                {{-- The chosen file's own address, shown because the picker closes
                     without saying which row it handed back and the canvas preview
                     is too small to tell two similar images apart. --}}
                @if ($image)
                    <div class="mt-1 flex items-start gap-2 rounded-lg border border-slate-200 p-2 dark:border-slate-700">
                        <img src="{{ $image->url() }}" alt="" class="size-12 shrink-0 rounded object-cover">
                        <div class="min-w-0 flex-1">
                            <p class="truncate text-xs font-medium text-slate-700 dark:text-slate-200">{{ $image->title }}</p>
                            <p class="break-all text-[11px] text-slate-400" title="{{ $image->url() }}">{{ $image->url() }}</p>
                        </div>
                    </div>
                @endif

                <div class="mt-2 flex flex-wrap items-center gap-2">
                    <flux:button size="sm" icon="photo" wire:click="chooseImage('img:{{ $block['id'] }}')">
                        {{ $image ? 'Change image' : 'Select from Media Library' }}
                    </flux:button>
                    @if ($image)
                        <flux:tooltip content="Remove image">
                            <flux:button size="sm" variant="ghost" icon="x-mark" square wire:click="removeBlockImage('img:{{ $block['id'] }}')" aria-label="Remove image" />
                        </flux:tooltip>
                    @endif
                </div>
            </div>
            <flux:input wire:model.live.debounce.1000ms="{{ $prefix }}.alt" label="Alt text" />
            <flux:input
                wire:model.live.debounce.1000ms="{{ $prefix }}.link_url"
                label="Link URL"
                description="Where clicking the image takes the reader. Leave empty for a plain picture."
                placeholder="https://"
            />
            <div class="grid grid-cols-2 gap-3">
                <flux:input wire:model.live.debounce.1000ms="{{ $prefix }}.width" label="Width" placeholder="100%" />
                <flux:input type="number" min="0" max="40" wire:model.live.debounce.1000ms="{{ $prefix }}.radius" label="Border radius" />
            </div>
            <x-marketing.align wire:model.live="{{ $prefix }}.align" />
        </div>
        @break

    @case(\App\Enums\EmailBlockTypeEnum::HTML)
        <div class="space-y-3">
            <flux:callout icon="exclamation-triangle" color="amber" class="text-xs">
                Advanced. This HTML is sanitized on save — scripts, handlers, and anything not on the
                allowed-tag list are stripped.
            </flux:callout>
            <flux:textarea wire:model.live.debounce.1000ms="{{ $prefix }}.html" rows="8" class="font-mono text-xs" />
        </div>
        @break

    @case(\App\Enums\EmailBlockTypeEnum::DYNAMIC_CONTENT)
        @php($provider = app(\App\Services\DynamicContentRegistryService::class)->provider($data['content_type'] ?? 'post'))
        <div class="space-y-4">
            <flux:select wire:model.live="{{ $prefix }}.content_type" label="Content type">
                @foreach (app(\App\Services\DynamicContentRegistryService::class)->options() as $key => $label)
                    <flux:select.option value="{{ $key }}">{{ $label }}</flux:select.option>
                @endforeach
            </flux:select>

            <flux:select wire:model.live="{{ $prefix }}.mode" label="Select">
                <flux:select.option value="latest">Latest</flux:select.option>
                <flux:select.option value="specific">Specific item</flux:select.option>
                <flux:select.option value="category">By category</flux:select.option>
                <flux:select.option value="tag">By tag</flux:select.option>
            </flux:select>

            @if (($data['mode'] ?? 'latest') === 'specific')
                <flux:select wire:model.live="{{ $prefix }}.content_id" label="Item">
                    <flux:select.option value="">Choose one</flux:select.option>
                    @foreach ($provider?->latest(50) ?? [] as $item)
                        <flux:select.option value="{{ $item->id }}">{{ $provider->toCard($item)['title'] }}</flux:select.option>
                    @endforeach
                </flux:select>
            @elseif (($data['mode'] ?? '') === 'category')
                <flux:select wire:model.live="{{ $prefix }}.category_id" label="Category">
                    <flux:select.option value="">Choose one</flux:select.option>
                    @foreach ($provider?->categories() ?? [] as $id => $label)
                        <flux:select.option value="{{ $id }}">{{ $label }}</flux:select.option>
                    @endforeach
                </flux:select>
            @elseif (($data['mode'] ?? '') === 'tag')
                <flux:select wire:model.live="{{ $prefix }}.tag_id" label="Tag">
                    <flux:select.option value="">Choose one</flux:select.option>
                    @foreach ($provider?->tags() ?? [] as $id => $label)
                        <flux:select.option value="{{ $id }}">{{ $label }}</flux:select.option>
                    @endforeach
                </flux:select>
            @endif

            @unless (($data['mode'] ?? '') === 'specific')
                <flux:input type="number" min="1" max="6" wire:model.live="{{ $prefix }}.limit" label="Number of items" />
            @endunless

            <flux:select wire:model.live="{{ $prefix }}.layout" label="Layout">
                <flux:select.option value="featured">Single featured</flux:select.option>
                <flux:select.option value="horizontal">Horizontal card</flux:select.option>
                <flux:select.option value="grid-2">Two-column grid</flux:select.option>
                <flux:select.option value="grid-3">Three-column grid</flux:select.option>
            </flux:select>

            <div class="grid grid-cols-2 gap-2">
                <flux:checkbox wire:model.live="{{ $prefix }}.show_image" label="Image" />
                <flux:checkbox wire:model.live="{{ $prefix }}.show_excerpt" label="Excerpt" />
                <flux:checkbox wire:model.live="{{ $prefix }}.show_date" label="Date" />
            </div>

            <flux:input wire:model.live="{{ $prefix }}.button_text" label="Button text" />
        </div>
        @break

    @case(\App\Enums\EmailBlockTypeEnum::RELATED_CONTENT)
        @php($provider = app(\App\Services\DynamicContentRegistryService::class)->provider($data['content_type'] ?? 'post'))
        <div class="space-y-4">
            <flux:select wire:model.live="{{ $prefix }}.content_type" label="Content type">
                @foreach (app(\App\Services\DynamicContentRegistryService::class)->options() as $key => $label)
                    <flux:select.option value="{{ $key }}">{{ $label }}</flux:select.option>
                @endforeach
            </flux:select>

            <flux:select wire:model.live="{{ $prefix }}.source_content_id" label="Related to">
                <flux:select.option value="">Choose the item this relates to</flux:select.option>
                @foreach ($provider?->latest(50) ?? [] as $item)
                    <flux:select.option value="{{ $item->id }}">{{ $provider->toCard($item)['title'] }}</flux:select.option>
                @endforeach
            </flux:select>

            <flux:input type="number" min="1" max="6" wire:model.live="{{ $prefix }}.limit" label="Number of items" />
            <flux:input wire:model.live="{{ $prefix }}.heading" label="Heading" />
            <flux:input wire:model.live="{{ $prefix }}.button_text" label="Button text" />
        </div>
        @break

    @case(\App\Enums\EmailBlockTypeEnum::COLUMNS)
        @php($rowBgImage = ! empty($data['background_image_id']) ? \App\Models\Image::find($data['background_image_id']) : null)
        <div class="space-y-5">
            {{-- The row itself is a container too, not just its columns — a promo
                 banner is one wide background image behind two columns of content
                 as often as it is two separately-coloured columns. --}}
            <div class="space-y-2 rounded-lg border border-slate-200 p-3 dark:border-slate-700">
                <flux:label>Row background</flux:label>
                <div class="grid grid-cols-2 gap-2">
                    <x-form.color-field wire:model.live="{{ $prefix }}.background" clearable />
                    <div class="flex items-center gap-1">
                        <flux:tooltip content="{{ $rowBgImage ? 'Change background image' : 'Set a background image' }}">
                            <flux:button size="sm" icon="photo" wire:click="chooseImage('bg:{{ $block['id'] }}')">
                                {{ $rowBgImage ? 'Change' : 'Background image' }}
                            </flux:button>
                        </flux:tooltip>
                        @if ($rowBgImage)
                            <flux:tooltip content="Remove background image">
                                <flux:button size="sm" variant="ghost" icon="x-mark" square wire:click="removeBlockImage('bg:{{ $block['id'] }}')" aria-label="Remove background image" />
                            </flux:tooltip>
                        @endif
                    </div>
                </div>
            </div>

            {{-- Each column's actual content — its blocks — is added and edited
                 straight on the canvas (see _columns-canvas-item.blade.php), the
                 same way top-level blocks are. This panel only ever covers what a
                 column can't show on its own: the column's own background. --}}
            @foreach ($data['columns'] ?? [] as $columnIndex => $column)
                @php($colBgImage = ! empty($column['background_image_id']) ? \App\Models\Image::find($column['background_image_id']) : null)
                <div class="space-y-2 rounded-lg border border-slate-200 p-3 dark:border-slate-700" wire:key="column-{{ $columnIndex }}">
                    <div class="flex items-center justify-between">
                        <flux:label>Column {{ $columnIndex + 1 }}</flux:label>
                        @if (count($data['columns']) > 1)
                            <flux:button size="xs" variant="ghost" icon="trash" wire:click="removeColumn({{ $index }}, {{ $columnIndex }})" aria-label="Remove column" />
                        @endif
                    </div>

                    <p class="text-xs text-slate-500">{{ count($column['blocks'] ?? []) }} block(s) — add and edit them on the canvas.</p>

                    <div class="grid grid-cols-2 gap-2">
                        <x-form.color-field wire:model.live="{{ $prefix }}.columns.{{ $columnIndex }}.background" label="Background" clearable size="sm" />
                        <div class="flex items-end gap-1">
                            <flux:tooltip content="{{ $colBgImage ? 'Change column background image' : 'Set a column background image' }}">
                                <flux:button size="sm" icon="photo" wire:click="chooseImage('colbg:{{ $block['id'] }}:{{ $columnIndex }}')">
                                    {{ $colBgImage ? 'Change' : 'Bg image' }}
                                </flux:button>
                            </flux:tooltip>
                            @if ($colBgImage)
                                <flux:tooltip content="Remove column background image">
                                    <flux:button size="sm" variant="ghost" icon="x-mark" square wire:click="removeBlockImage('colbg:{{ $block['id'] }}:{{ $columnIndex }}')" aria-label="Remove column background image" />
                                </flux:tooltip>
                            @endif
                        </div>
                    </div>
                </div>
            @endforeach

            @if (count($data['columns'] ?? []) < 4)
                <flux:button size="sm" variant="ghost" icon="plus" wire:click="addColumn({{ $index }})">Add column</flux:button>
            @endif
        </div>
        @break

    @case(\App\Enums\EmailBlockTypeEnum::LOGO)
    @case(\App\Enums\EmailBlockTypeEnum::LOGO_DARK)
        <div class="space-y-4">
            <p class="text-xs text-slate-500">
                Pulled straight from {{ $case->isLogoDark() ? 'the dark logo' : 'the logo' }} in Site Config — Configuration → Site Settings, not from this block.
            </p>
            <flux:input wire:model.live.debounce.1000ms="{{ $prefix }}.width" label="Width" placeholder="160px" />
            <x-marketing.align wire:model.live="{{ $prefix }}.align" />
            <flux:input
                wire:model.live.debounce.1000ms="{{ $prefix }}.link_url"
                label="Link URL"
                placeholder="&#123;&#123;site.url&#125;&#125;"
            />
        </div>
        @break

    @case(\App\Enums\EmailBlockTypeEnum::FAVICON)
        <div class="space-y-4">
            <p class="text-xs text-slate-500">Pulled straight from the favicon in Site Config — Configuration → Site Settings.</p>
            <flux:input wire:model.live.debounce.1000ms="{{ $prefix }}.width" label="Width" placeholder="32px" />
            <x-marketing.align wire:model.live="{{ $prefix }}.align" />
        </div>
        @break

    @case(\App\Enums\EmailBlockTypeEnum::SOCIALS)
        <div class="space-y-4">
            <flux:select wire:model.live="{{ $prefix }}.source" label="Links">
                <flux:select.option value="config">Use Site Config's socials</flux:select.option>
                <flux:select.option value="custom">Add my own</flux:select.option>
            </flux:select>

            @if (($data['source'] ?? 'config') === 'custom')
                <div class="space-y-3">
                    @forelse ($data['custom_links'] ?? [] as $linkIndex => $link)
                        <div class="space-y-2 rounded-lg border border-slate-200 p-3 dark:border-slate-700" wire:key="social-link-{{ $linkIndex }}">
                            <div class="flex items-center justify-between">
                                <flux:label class="text-xs text-slate-500">Link {{ $linkIndex + 1 }}</flux:label>
                                <flux:tooltip content="Remove this link">
                                    <flux:button size="sm" variant="ghost" icon="trash" square wire:click="removeSocialLink('{{ $block['id'] }}', {{ $linkIndex }})" aria-label="Remove link {{ $linkIndex + 1 }}" />
                                </flux:tooltip>
                            </div>
                            <div class="grid grid-cols-2 gap-2">
                                <flux:select wire:model.live="{{ $prefix }}.custom_links.{{ $linkIndex }}.platform" label="Icon" size="sm">
                                    <flux:select.option value="">None</flux:select.option>
                                    @foreach (\App\Enums\SocialHandleEnum::cases() as $handle)
                                        <flux:select.option value="{{ $handle->value }}">{{ $handle->label() }}</flux:select.option>
                                    @endforeach
                                </flux:select>
                                <flux:input wire:model.live.debounce.1000ms="{{ $prefix }}.custom_links.{{ $linkIndex }}.label" label="Label" size="sm" placeholder="e.g. Our blog" />
                            </div>
                            <flux:input wire:model.live.debounce.1000ms="{{ $prefix }}.custom_links.{{ $linkIndex }}.url" label="URL" size="sm" placeholder="https://" />
                        </div>
                    @empty
                        <p class="rounded-lg border border-dashed border-slate-200 p-3 text-center text-xs text-slate-500 dark:border-slate-700">
                            No links yet.
                        </p>
                    @endforelse
                    <flux:button size="sm" variant="ghost" icon="plus" wire:click="addSocialLink('{{ $block['id'] }}')" class="w-full">Add link</flux:button>
                </div>
            @else
                <p class="text-xs text-slate-500">
                    Reads whatever is saved under Configuration → Social Handles. Add or edit them there.
                </p>
            @endif

            <flux:select wire:model.live="{{ $prefix }}.style" label="Button style">
                <flux:select.option value="image">Icon image</flux:select.option>
                <flux:select.option value="text">Name only</flux:select.option>
            </flux:select>

            @if (($data['style'] ?? 'image') === 'image')
                <flux:select wire:model.live="{{ $prefix }}.variant" label="Icon color">
                    <flux:select.option value="default">Brand colours</flux:select.option>
                    <flux:select.option value="white">White</flux:select.option>
                    <flux:select.option value="black">Black</flux:select.option>
                </flux:select>
            @endif

            <x-marketing.align wire:model.live="{{ $prefix }}.align" />
        </div>
        @break

    @case(\App\Enums\EmailBlockTypeEnum::SECTION)
        <flux:select wire:model.live="{{ $prefix }}.email_section_id" label="Saved section">
            <flux:select.option value="">Choose one</flux:select.option>
            @foreach (\App\Models\EmailSection::query()->orderBy('email_section_type')->orderBy('name')->get() as $section)
                <flux:select.option value="{{ $section->id }}">
                    {{ $section->email_section_type->label() }} — {{ $section->name }}
                </flux:select.option>
            @endforeach
        </flux:select>
        @break
@endswitch

{{-- Duplicates check values against data keys--}}
@if (array_intersect_key($data, array_flip(['font_size', 'font', 'char_case', 'align', 'color'])))
    <div class="space-y-4 mt-4">
        @if (array_intersect_key($data, array_flip(['font_size', 'font'])))
            <flux:field>
                <flux:label>Font</flux:label>
                <flux:input.group>
                    @if (\App\Enums\EmailBlockTypeElementEnum::checkField('font', $data))
                        <x-marketing.fonts class="w-full" wire:model.live="{{ $prefix }}.font" />
                    @endif
                    @if (\App\Enums\EmailBlockTypeElementEnum::checkField('font_size', $data))
                        <flux:select class="w-24" wire:model.live="{{ $prefix }}.font_size" size="sm" placeholder="Font size">
                            @foreach (\App\Enums\EmailBlockTypeElementEnum::FONT_SIZE->validItems() as $key => $item)
                                <flux:select.option value="{{ $key }}">{{ $key }}</flux:select.option>
                            @endforeach
                        </flux:select>
                    @endif
                </flux:input.group>
            </flux:field>
        @endif

        @if (\App\Enums\EmailBlockTypeElementEnum::checkField('char_case', $data))
            <flux:radio.group wire:model.live="{{ $prefix }}.char_case" label="Case" variant="segmented" size="sm">
                @foreach (\App\Enums\EmailBlockTypeElementEnum::charCases() as $key => $value)
                    <flux:radio value="{{ $key }}" :icon="$value['icon']" />
                @endforeach
            </flux:radio.group>
        @endif
        @if (\App\Enums\EmailBlockTypeElementEnum::checkField('align', $data))
            <x-marketing.align wire:model.live="{{ $prefix }}.align" />
        @endif
        @if (\App\Enums\EmailBlockTypeElementEnum::checkField('color', $data))
            <x-form.color-field wire:model.live="{{ $prefix }}.color" label="Text color" />
        @endif
    </div>
@endif


@unless (in_array($case, [\App\Enums\EmailBlockTypeEnum::SPACER, \App\Enums\EmailBlockTypeEnum::SECTION], true))
    {{-- Every block but Spacer (which is nothing but its own height) and Section
         (a reference — the blocks it points at carry their own) gets the same one
         editable clearance: the space below it, before the next block starts. --}}
    <div class="space-y-4 mt-4">
        <flux:separator variant="subtle" text="layout" />
        @if (\App\Enums\EmailBlockTypeElementEnum::checkField('background', $data))
            <x-form.color-field wire:model.live="{{ $prefix }}.background" label="Background color" />
        @endif

        @if (\App\Enums\EmailBlockTypeElementEnum::checkField('border', $data))
            <x-marketing.border :$prefix />
        @endif

        @if (\App\Enums\EmailBlockTypeElementEnum::checkField('radius', $data))
            <x-marketing.radius wire:model.live="{{ $prefix }}.radius" />
        @endif

        @foreach (['spacing' => 'Spacing', 'border_spacing' => 'Border spacing'] as $key => $label)
            @if (\App\Enums\EmailBlockTypeElementEnum::checkField($key, $data))
                <flux:field>
                    <flux:label>
                        {{ $label }}
                        <x-marketing.small-text>px: top, right, bottom, left</x-marketing.small-text>
                    </flux:label>
                    <flux:input.group>
                        @foreach (['top' => 't', 'right' => 'r', 'bottom' => 'b', 'left' => 'l'] as $direction => $placeholder)
                            <x-form.number-field
                                wire:model.live.debounce.1000ms="{{ $prefix }}.{{ $key }}.{{ $direction }}"
                                placeholder="{{ $placeholder }}"
                                size="sm"
                                min="0"
                                max="120"
                            />
                        @endforeach
                    </flux:input.group>
                </flux:field>
            @endif
        @endforeach
    </div>
@endunless
