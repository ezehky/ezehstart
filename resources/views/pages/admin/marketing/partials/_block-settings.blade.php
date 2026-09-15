{{--
    The selected block's settings, one @case per EmailBlockTypeEnum. Every field binds
    straight to `blocks.{{ $index }}.data.*` — see WithBlockEditor.
--}}

@php($prefix = "blocks.{$index}.data")

@switch($case)
    @case(\App\Enums\EmailBlockTypeEnum::HEADING)
        <div class="space-y-4">
            <div>
                <div class="mb-1 flex items-center justify-between">
                    <flux:label>Heading text</flux:label>
                    @include('pages.admin.marketing.partials._personalize-menu', ['index' => $index, 'field' => 'text'])
                </div>
                <flux:textarea wire:model.live="{{ $prefix }}.text" rows="2" />
            </div>
            <flux:select wire:model.live="{{ $prefix }}.level" label="Heading level">
                <flux:select.option value="h1">H1</flux:select.option>
                <flux:select.option value="h2">H2</flux:select.option>
                <flux:select.option value="h3">H3</flux:select.option>
            </flux:select>
            <flux:select wire:model.live="{{ $prefix }}.align" label="Alignment">
                <flux:select.option value="left">Left</flux:select.option>
                <flux:select.option value="center">Center</flux:select.option>
                <flux:select.option value="right">Right</flux:select.option>
            </flux:select>
            <flux:input type="color" wire:model.live="{{ $prefix }}.color" label="Text color" />
        </div>
        @break

    @case(\App\Enums\EmailBlockTypeEnum::PARAGRAPH)
        <div class="space-y-4">
            <div>
                <div class="mb-1 flex items-center justify-between">
                    <flux:label>Text</flux:label>
                    @include('pages.admin.marketing.partials._personalize-menu', ['index' => $index, 'field' => 'text', 'richtext' => true])
                </div>
                <x-form.rich-text wire:model="{{ $prefix }}.text" />
            </div>
            <flux:select wire:model.live="{{ $prefix }}.align" label="Alignment">
                <flux:select.option value="left">Left</flux:select.option>
                <flux:select.option value="center">Center</flux:select.option>
                <flux:select.option value="right">Right</flux:select.option>
            </flux:select>
            <flux:input type="color" wire:model.live="{{ $prefix }}.color" label="Text color" />
        </div>
        @break

    @case(\App\Enums\EmailBlockTypeEnum::BUTTON)
        <div class="space-y-4">
            <flux:input wire:model.live="{{ $prefix }}.text" label="Button text" />
            <div>
                <div class="mb-1 flex items-center justify-between">
                    <flux:label>URL</flux:label>
                    @include('pages.admin.marketing.partials._personalize-menu', ['index' => $index, 'field' => 'url'])
                </div>
                <flux:input wire:model.live="{{ $prefix }}.url" placeholder="https:// or &#123;&#123;unsubscribe_url&#125;&#125;" />
            </div>
            <flux:checkbox wire:model.live="{{ $prefix }}.new_tab" label="Open in new tab" />
            <flux:select wire:model.live="{{ $prefix }}.align" label="Alignment">
                <flux:select.option value="left">Left</flux:select.option>
                <flux:select.option value="center">Center</flux:select.option>
                <flux:select.option value="right">Right</flux:select.option>
            </flux:select>
            <div class="grid grid-cols-2 gap-3">
                <flux:input type="color" wire:model.live="{{ $prefix }}.background" label="Background" />
                <flux:input type="color" wire:model.live="{{ $prefix }}.color" label="Text color" />
            </div>
        </div>
        @break

    @case(\App\Enums\EmailBlockTypeEnum::DIVIDER)
        <flux:input type="color" wire:model.live="{{ $prefix }}.color" label="Line color" />
        @break

    @case(\App\Enums\EmailBlockTypeEnum::SPACER)
        <flux:input type="number" min="4" max="200" wire:model.live="{{ $prefix }}.height" label="Height (px)" />
        @break

    @case(\App\Enums\EmailBlockTypeEnum::IMAGE)
        @php($image = ! empty($blocks[$index]['data']['image_id']) ? \App\Models\Image::find($blocks[$index]['data']['image_id']) : null)
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
                    <flux:button size="sm" icon="photo" wire:click="chooseImage('block-{{ $index }}')">
                        {{ $image ? 'Change image' : 'Select from Media Library' }}
                    </flux:button>
                    @if ($image)
                        <flux:button size="sm" variant="ghost" icon="x-mark" wire:click="removeBlockImage('block-{{ $index }}')">
                            Remove image
                        </flux:button>
                    @endif
                </div>
            </div>
            <flux:input wire:model.live="{{ $prefix }}.alt" label="Alt text" />
            <flux:input
                wire:model.live="{{ $prefix }}.link_url"
                label="Link URL"
                description="Where clicking the image takes the reader. Leave empty for a plain picture."
                placeholder="https://"
            />
            <div class="grid grid-cols-2 gap-3">
                <flux:input wire:model.live="{{ $prefix }}.width" label="Width" placeholder="100%" />
                <flux:input type="number" min="0" max="40" wire:model.live="{{ $prefix }}.radius" label="Border radius" />
            </div>
            <flux:select wire:model.live="{{ $prefix }}.align" label="Alignment">
                <flux:select.option value="left">Left</flux:select.option>
                <flux:select.option value="center">Center</flux:select.option>
                <flux:select.option value="right">Right</flux:select.option>
            </flux:select>
        </div>
        @break

    @case(\App\Enums\EmailBlockTypeEnum::HTML)
        <div class="space-y-3">
            <flux:callout icon="exclamation-triangle" color="amber" class="text-xs">
                Advanced. This HTML is sanitized on save — scripts, handlers, and anything not on the
                allowed-tag list are stripped.
            </flux:callout>
            <flux:textarea wire:model.live="{{ $prefix }}.html" rows="8" class="font-mono text-xs" />
        </div>
        @break

    @case(\App\Enums\EmailBlockTypeEnum::DYNAMIC_CONTENT)
        @php($provider = app(\App\Services\DynamicContentRegistryService::class)->provider($blocks[$index]['data']['content_type'] ?? 'post'))
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

            @if (($blocks[$index]['data']['mode'] ?? 'latest') === 'specific')
                <flux:select wire:model.live="{{ $prefix }}.content_id" label="Item">
                    <flux:select.option value="">Choose one</flux:select.option>
                    @foreach ($provider?->latest(50) ?? [] as $item)
                        <flux:select.option value="{{ $item->id }}">{{ $provider->toCard($item)['title'] }}</flux:select.option>
                    @endforeach
                </flux:select>
            @elseif (($blocks[$index]['data']['mode'] ?? '') === 'category')
                <flux:select wire:model.live="{{ $prefix }}.category_id" label="Category">
                    <flux:select.option value="">Choose one</flux:select.option>
                    @foreach ($provider?->categories() ?? [] as $id => $label)
                        <flux:select.option value="{{ $id }}">{{ $label }}</flux:select.option>
                    @endforeach
                </flux:select>
            @elseif (($blocks[$index]['data']['mode'] ?? '') === 'tag')
                <flux:select wire:model.live="{{ $prefix }}.tag_id" label="Tag">
                    <flux:select.option value="">Choose one</flux:select.option>
                    @foreach ($provider?->tags() ?? [] as $id => $label)
                        <flux:select.option value="{{ $id }}">{{ $label }}</flux:select.option>
                    @endforeach
                </flux:select>
            @endif

            @unless (($blocks[$index]['data']['mode'] ?? '') === 'specific')
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
        @php($provider = app(\App\Services\DynamicContentRegistryService::class)->provider($blocks[$index]['data']['content_type'] ?? 'post'))
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
        @php($rowBgImage = ! empty($blocks[$index]['data']['background_image_id']) ? \App\Models\Image::find($blocks[$index]['data']['background_image_id']) : null)
        <div class="space-y-5">
            {{-- The row itself is a container too, not just its columns — a promo
                 banner is one wide background image behind two columns of text as
                 often as it is two separately-coloured columns. --}}
            <div class="space-y-2 rounded-lg border border-slate-200 p-3 dark:border-slate-700">
                <flux:label>Row background</flux:label>
                <div class="grid grid-cols-2 gap-2">
                    <flux:input type="color" wire:model.live="{{ $prefix }}.background" placeholder="None" />
                    <flux:button size="sm" icon="photo" wire:click="chooseImage('block-{{ $index }}-bg')">
                        {{ $rowBgImage ? 'Change image' : 'Background image' }}
                    </flux:button>
                </div>
                @if ($rowBgImage)
                    <flux:button size="sm" variant="ghost" icon="x-mark" wire:click="removeBlockImage('block-{{ $index }}-bg')">
                        Remove background image
                    </flux:button>
                @endif
            </div>

            @foreach ($blocks[$index]['data']['columns'] ?? [] as $columnIndex => $column)
                @php($colBgImage = ! empty($column['background_image_id']) ? \App\Models\Image::find($column['background_image_id']) : null)
                @php($colImage = ! empty($column['image_id']) ? \App\Models\Image::find($column['image_id']) : null)
                <div class="space-y-3 rounded-lg border border-slate-200 p-3 dark:border-slate-700" wire:key="column-{{ $columnIndex }}">
                    <div class="flex items-center justify-between">
                        <flux:label>Column {{ $columnIndex + 1 }}</flux:label>
                        @if (count($blocks[$index]['data']['columns']) > 1)
                            <flux:button size="xs" variant="ghost" icon="trash" wire:click="removeColumn({{ $index }}, {{ $columnIndex }})" aria-label="Remove column" />
                        @endif
                    </div>

                    <flux:select wire:model.live="{{ $prefix }}.columns.{{ $columnIndex }}.type" label="Contains" size="sm">
                        <flux:select.option value="text">Text</flux:select.option>
                        <flux:select.option value="image">Image</flux:select.option>
                    </flux:select>

                    @if (($column['type'] ?? 'text') === 'image')
                        @if ($colImage)
                            <div class="flex items-start gap-2 rounded-lg border border-slate-200 p-2 dark:border-slate-700">
                                <img src="{{ $colImage->url() }}" alt="" class="size-10 shrink-0 rounded object-cover">
                                <p class="min-w-0 flex-1 truncate text-xs text-slate-600 dark:text-slate-300">{{ $colImage->title }}</p>
                            </div>
                        @endif
                        <div class="flex flex-wrap items-center gap-2">
                            <flux:button size="sm" icon="photo" wire:click="chooseImage('block-{{ $index }}-col-{{ $columnIndex }}')">
                                {{ $colImage ? 'Change image' : 'Select from Media Library' }}
                            </flux:button>
                            @if ($colImage)
                                <flux:button size="sm" variant="ghost" icon="x-mark" wire:click="removeBlockImage('block-{{ $index }}-col-{{ $columnIndex }}')">
                                    Remove
                                </flux:button>
                            @endif
                        </div>
                        <flux:input wire:model.live="{{ $prefix }}.columns.{{ $columnIndex }}.alt" label="Alt text" size="sm" />
                    @else
                        <flux:textarea wire:model.live="{{ $prefix }}.columns.{{ $columnIndex }}.text" label="Text" rows="3" />
                    @endif

                    <div class="grid grid-cols-2 gap-2">
                        <flux:input type="color" wire:model.live="{{ $prefix }}.columns.{{ $columnIndex }}.background" label="Background" size="sm" />
                        <flux:button size="sm" icon="photo" wire:click="chooseImage('block-{{ $index }}-col-{{ $columnIndex }}-bg')">
                            {{ $colBgImage ? 'Change bg' : 'Bg image' }}
                        </flux:button>
                    </div>
                    @if ($colBgImage)
                        <flux:button size="sm" variant="ghost" icon="x-mark" wire:click="removeBlockImage('block-{{ $index }}-col-{{ $columnIndex }}-bg')">
                            Remove background image
                        </flux:button>
                    @endif
                </div>
            @endforeach

            @if (count($blocks[$index]['data']['columns'] ?? []) < 4)
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
            <flux:input wire:model.live="{{ $prefix }}.width" label="Width" placeholder="160px" />
            <flux:select wire:model.live="{{ $prefix }}.align" label="Alignment">
                <flux:select.option value="left">Left</flux:select.option>
                <flux:select.option value="center">Center</flux:select.option>
                <flux:select.option value="right">Right</flux:select.option>
            </flux:select>
            <flux:input wire:model.live="{{ $prefix }}.link_url" label="Link URL" placeholder="&#123;&#123;site.url&#125;&#125;" />
        </div>
        @break

    @case(\App\Enums\EmailBlockTypeEnum::FAVICON)
        <div class="space-y-4">
            <p class="text-xs text-slate-500">Pulled straight from the favicon in Site Config — Configuration → Site Settings.</p>
            <flux:input wire:model.live="{{ $prefix }}.width" label="Width" placeholder="32px" />
            <flux:select wire:model.live="{{ $prefix }}.align" label="Alignment">
                <flux:select.option value="left">Left</flux:select.option>
                <flux:select.option value="center">Center</flux:select.option>
                <flux:select.option value="right">Right</flux:select.option>
            </flux:select>
        </div>
        @break

    @case(\App\Enums\EmailBlockTypeEnum::SOCIALS)
        <div class="space-y-4">
            <flux:select wire:model.live="{{ $prefix }}.source" label="Links">
                <flux:select.option value="config">Use Site Config's socials</flux:select.option>
                <flux:select.option value="custom">Add my own</flux:select.option>
            </flux:select>

            @if (($blocks[$index]['data']['source'] ?? 'config') === 'custom')
                <div class="space-y-2">
                    @foreach ($blocks[$index]['data']['custom_links'] ?? [] as $linkIndex => $link)
                        <div class="flex items-end gap-2" wire:key="social-link-{{ $linkIndex }}">
                            <flux:select wire:model.live="{{ $prefix }}.custom_links.{{ $linkIndex }}.platform" label="Icon" size="sm" class="w-32">
                                <flux:select.option value="">None</flux:select.option>
                                @foreach (\App\Enums\SocialHandleEnum::cases() as $handle)
                                    <flux:select.option value="{{ $handle->value }}">{{ $handle->label() }}</flux:select.option>
                                @endforeach
                            </flux:select>
                            <flux:input wire:model.live="{{ $prefix }}.custom_links.{{ $linkIndex }}.label" label="Label" size="sm" placeholder="e.g. Our blog" />
                            <flux:input wire:model.live="{{ $prefix }}.custom_links.{{ $linkIndex }}.url" label="URL" size="sm" />
                            <flux:button size="sm" variant="ghost" icon="trash" wire:click="removeSocialLink({{ $index }}, {{ $linkIndex }})" aria-label="Remove link" />
                        </div>
                    @endforeach
                    <flux:button size="sm" variant="ghost" icon="plus" wire:click="addSocialLink({{ $index }})">Add link</flux:button>
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

            @if (($blocks[$index]['data']['style'] ?? 'image') === 'image')
                <flux:select wire:model.live="{{ $prefix }}.variant" label="Icon color">
                    <flux:select.option value="default">Brand colours</flux:select.option>
                    <flux:select.option value="white">White</flux:select.option>
                    <flux:select.option value="black">Black</flux:select.option>
                </flux:select>
            @endif

            <flux:select wire:model.live="{{ $prefix }}.align" label="Alignment">
                <flux:select.option value="left">Left</flux:select.option>
                <flux:select.option value="center">Center</flux:select.option>
                <flux:select.option value="right">Right</flux:select.option>
            </flux:select>
        </div>
        @break

    @case(\App\Enums\EmailBlockTypeEnum::SECTION)
        <flux:select wire:model.live="{{ $prefix }}.email_section_id" label="Saved section">
            <flux:select.option value="">Choose one</flux:select.option>
            @foreach (\App\Models\EmailSection::query()->orderBy('email_section_type')->orderBy('name')->get() as $section)
                <flux:select.option value="{{ $section->id }}">{{ $section->email_section_type->label() }} — {{ $section->name }}</flux:select.option>
            @endforeach
        </flux:select>
        @break
@endswitch
