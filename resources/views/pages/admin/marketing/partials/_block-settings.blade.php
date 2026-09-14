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
                    @include('pages.admin.marketing.partials._personalize-menu', ['index' => $index, 'field' => 'text'])
                </div>
                <flux:textarea wire:model.live="{{ $prefix }}.text" rows="4" />
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
        <div class="space-y-4">
            <div>
                <flux:label>Image</flux:label>
                <div class="mt-1 flex items-center gap-2">
                    <flux:button size="sm" icon="photo" wire:click="chooseImage('block-{{ $index }}')">
                        {{ ($blocks[$index]['data']['image_id'] ?? null) ? 'Change image' : 'Select from Media Library' }}
                    </flux:button>
                </div>
            </div>
            <flux:input wire:model.live="{{ $prefix }}.alt" label="Alt text" />
            <flux:input wire:model.live="{{ $prefix }}.link_url" label="Link URL" placeholder="https://" />
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
        <div class="space-y-3">
            @foreach ($blocks[$index]['data']['columns'] ?? [] as $columnIndex => $column)
                <flux:textarea wire:model.live="{{ $prefix }}.columns.{{ $columnIndex }}.text" :label="'Column '.($columnIndex + 1)" rows="3" />
            @endforeach
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
