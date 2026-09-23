{{--
    The block editor body — palette, canvas, and the selected block's settings.
    Shared between the campaign builder, the template builder, and the saved section
    editor, always @include()'d (never a Blade component) so wire:model paths resolve
    against the host Livewire component rather than a separate component boundary.

    Expects, from the host:
        $blocks              array   (from WithBlockEditor)
        $selectedBlockId      ?string
        $allowSectionBlocks   bool    whether a saved section can be inserted (off
                                       inside the section editor itself — a section
                                       cannot reference another section)
        $canvasHeight         ?string an override for the three panes' height class,
                                       so the full-screen builders can fill the
                                       window rather than 75vh of it
        $design               ?array  the page design (from _design-settings.blade.php),
                                       painted onto the canvas so it matches what
                                       EmailRenderService::document() renders — absent
                                       on the section editor, which has no page design
                                       of its own, so every read below falls back
--}}

@php
    $palette = collect(\App\Enums\EmailBlockTypeEnum::cases())
        ->when(! ($allowSectionBlocks ?? true), fn ($cases) => $cases->reject(
            fn ($case) => $case === \App\Enums\EmailBlockTypeEnum::SECTION,
        ))
        ->groupBy(fn ($case) => $case->group());

    // $selectedIndex is only meaningful for a TOP-LEVEL selection — used solely to
    // insert a new palette block after whichever block is currently selected. A
    // nested selection (a block inside a column) leaves it null, so a palette
    // click then just appends at the end rather than trying to slot a top-level
    // block into the middle of a column.
    $selectedIndex = $this->selectedBlockIndex();
    $selected = $this->selectedBlockId ? $this->findBlock($this->selectedBlockId) : null;

    $blockCase = $selected ? \App\Enums\EmailBlockTypeEnum::tryFrom($selected['type']) : null;

    // $design only exists on hosts that carry a page design (the campaign and
    // template builders) — the saved section editor has no page around its
    // blocks, so this reads as an empty array there and every value below falls
    // back to the same defaults EmailRenderService::document() renders with.
    $design ??= [];
    $letterFont = match ($design['font_family'] ?? 'sans') {
        'serif' => 'Georgia, "Times New Roman", serif',
        'mono' => '"Courier New", Courier, monospace',
        default => 'Arial, Helvetica, sans-serif',
    };
    $canvasHeight ??= 'max-h-[75vh]';

    // Exposed as --email-brand so every colour field's "Brand colour" swatch paints
    // the current brand without its own round trip (see x-form.color-field).
    $brand = app(\App\Services\EmailBlockItemService::class)->brand($design);
@endphp

<div
    class="grid grid-cols-1 overflow-hidden rounded-2xl border border-slate-200 lg:grid-cols-[240px_1fr_300px] dark:border-slate-800"
    style="--email-brand: {{ $brand }};"
>
    {{-- PALETTE --}}
    <div class="{{ $canvasHeight }} overflow-y-auto border-b border-slate-200 bg-white p-4 lg:border-b-0 lg:border-e dark:border-slate-800 dark:bg-slate-950 custom-scrollbar">
        @foreach ($palette as $group => $cases)
            <p class="mb-2 mt-4 text-[11px] font-semibold uppercase tracking-[0.07em] text-slate-400 first:mt-0">{{ $group }}</p>
            <div class="grid grid-cols-2 gap-2">
                @foreach ($cases as $case)
                    <button
                        type="button"
                        wire:click="addBlock('{{ $case->value }}', {{ $selectedIndex ?? 'null' }})"
                        class="press flex flex-col items-center gap-1.5 rounded-lg border border-slate-200 p-2.5 text-center hover:border-lime-400 hover:bg-lime-50 dark:border-slate-700 dark:hover:border-lime-400 dark:hover:bg-lime-400/10"
                    >
                        <flux:icon :name="$case->icon()" class="size-4 text-slate-500 dark:text-slate-300" />
                        <span class="text-[11px] font-medium text-slate-600 dark:text-slate-300">{{ $case->label() }}</span>
                    </button>
                @endforeach
            </div>
        @endforeach
    </div>

    {{-- CANVAS --}}
    {{-- The page background behind the letter is the one design value shown even
         where the canvas can't fit a full page — a flat colour swatch, not the
         actual body tag, so it stays readable in both themes when left unset. --}}
    <div
        class="{{ $canvasHeight }} overflow-y-auto p-6 custom-scrollbar"
        style="{{ $designCss['container'] }} padding:24px 12px;"
    >
        {{-- The canvas is a single column of blocks, with the selected block's
             settings in the right-hand pane. The canvas itself is not a form —
             the settings are, and they are keyed to the selected block so that
             Livewire's morph keeps the right fields bound to the right block. --}}
        {{-- wire:sort reorders by the dragged block's id, never by index: the canvas
             re-renders on every selection, and an index captured before the drag
             would already be stale by the time it landed. The grip is pinned as the
             handle in the config rather than left to be inferred: on an empty
             canvas there is no handle in the DOM to infer it from, and the whole
             block would become draggable — which would swallow the click that
             selects it.

             The action is named bare ("reorderBlocks"), never called with
             ($item, $position): Livewire rewrites every identifier in an action
             expression to $wire.<name>, so those two become $wire.$item and
             $wire.$position — always undefined — and every drop was silently
             dropped. Named bare, the dragged id and its new position arrive as
             reorderBlocks()'s two real arguments. wire:sort:item is written bare
             too, never quoted: it is handed over as the literal attribute text and
             never parsed as JavaScript, so a quoted id never matches on drop.

             Everything below the accent bar is styled from $design inline, not
             Tailwind classes: these are the same values EmailRenderService::document()
             renders with, chosen freely as hex/px by the admin, so a fixed Tailwind
             swatch could never track them. --}}
        <div
            wire:sort="reorderBlocks"
            wire:sort:config="{ handle: '[wire\\:sort\\:handle]' }"
            style="{{ $designCss['style'] }}"
        >
            @if (! empty($design['accent_bar']))
                <div style="height: 6px; background: {{ $brand }};"></div>
            @endif

            @forelse ($blocks as $index => $block)
                @php($case = \App\Enums\EmailBlockTypeEnum::tryFrom($block['type']))
                @continue(! $case)

                @if ($case === \App\Enums\EmailBlockTypeEnum::COLUMNS)
                    @include('pages.admin.marketing.partials._columns-canvas-item', [
                        'block' => $block,
                        'index' => $index,
                        'selectedBlockId' => $selectedBlockId,
                    ])
                @else
                    @include('pages.admin.marketing.partials._block-canvas-item', [
                        'block' => $block,
                        'case' => $case,
                        'selectedBlockId' => $selectedBlockId,
                        'sortItem' => $block['id'],
                        'controls' => [
                            'select' => "selectBlock('{$block['id']}')",
                            'moveUp' => "moveBlockUp({$index})",
                            'moveDown' => "moveBlockDown({$index})",
                            'duplicate' => "duplicateBlock({$index})",
                            'remove' => "removeBlock({$index})",
                        ],
                    ])
                @endif
            @empty
                <div class="p-10 text-center">
                    <flux:icon name="rectangle-stack" class="mx-auto size-8 text-slate-300" />
                    <p class="mt-3 text-sm text-slate-500">Add a block from the left to start building.</p>
                </div>
            @endforelse
        </div>
    </div>

    {{-- SETTINGS --}}
    {{-- The overlay sits on a non-scrolling wrapper so it covers the pane at any
         scroll position. Selecting a block re-renders this whole panel, and the
         first one after page load is the slowest (every settings component is
         compiled and booted then) — without it the click looked like it did
         nothing, so it was clicked again. --}}
    <div class="relative border-t border-slate-200 bg-white lg:border-t-0 lg:border-s dark:border-slate-800 dark:bg-slate-950">
    <div
        wire:loading.flex
        wire:target="selectBlock, addBlock, addColumnBlock, duplicateBlock, duplicateColumnBlock"
        class="absolute inset-0 z-20 items-center justify-center bg-white/70 backdrop-blur-[1px] dark:bg-slate-950/70"
    >
        <flux:icon.loading class="size-5 text-lime-600 dark:text-lime-400" />
    </div>
    <div class="{{ $canvasHeight }} overflow-y-auto p-4 custom-scrollbar">
        @if (! $selected)
            <div class="py-10 text-center">
                <flux:icon name="cursor-arrow-rays" class="mx-auto size-6 text-slate-300" />
                <p class="mt-3 text-sm text-slate-500">Select a block to edit its settings.</p>
            </div>
        @elseif($blockCase)

            {{-- Keyed to the block, not to the panel. Two blocks of different types
                 both carry a "text" field, and without a key Livewire's morph keeps
                 the textarea it already had — leaving the heading's box still bound
                 to blocks.0.data.text while the paragraph is selected. --}}
            <div wire:key="block-settings-{{ data_get($selected, 'id') }}">
            <div class="mb-3">
                <p class="text-[11px] font-semibold uppercase tracking-[0.07em] text-lime-600 dark:text-lime-400">
                    {{ $blockCase->group() }}
                </p>
                <p class="font-medium text-slate-950 dark:text-white">
                    {{ $blockCase->label() }} settings
                </p>
            </div>

            @include('pages.admin.marketing.partials._block-settings', [
                'case' => $blockCase,
                'block' => $selected,
                'prefix' => $this->pathFor(data_get($selected, 'id')).'.data',
                'index' => $selectedIndex,
            ])
            </div>
        @endif
    </div>
    </div>
</div>
