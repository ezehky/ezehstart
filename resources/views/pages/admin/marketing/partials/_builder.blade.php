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
--}}

@php
    $palette = collect(\App\Enums\EmailBlockTypeEnum::cases())
        ->when(! ($allowSectionBlocks ?? true), fn ($cases) => $cases->reject(
            fn ($case) => $case === \App\Enums\EmailBlockTypeEnum::SECTION,
        ))
        ->groupBy(fn ($case) => $case->group());

    $selectedIndex = $this->selectedBlockIndex();
    $selected = $selectedIndex !== null ? $blocks[$selectedIndex] : null;
@endphp

<div class="grid grid-cols-1 overflow-hidden rounded-2xl border border-slate-200 lg:grid-cols-[240px_1fr_300px] dark:border-slate-800">
    {{-- PALETTE --}}
    <div class="{{ $canvasHeight ?? 'max-h-[75vh]' }} overflow-y-auto border-b border-slate-200 bg-white p-4 lg:border-b-0 lg:border-e dark:border-slate-800 dark:bg-slate-950 custom-scrollbar">
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
    <div class="{{ $canvasHeight ?? 'max-h-[75vh]' }} overflow-y-auto bg-slate-100 p-6 dark:bg-slate-900 custom-scrollbar">
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
             never parsed as JavaScript, so a quoted id never matches on drop. --}}
        <div
            wire:sort="reorderBlocks"
            wire:sort:config="{ handle: '[wire\\:sort\\:handle]' }"
            class="mx-auto w-full max-w-[640px] rounded-md bg-white shadow-sm"
        >
            @forelse ($blocks as $index => $block)
                @php($case = \App\Enums\EmailBlockTypeEnum::tryFrom($block['type']))
                @continue(! $case)

                <div
                    wire:key="block-{{ $block['id'] }}"
                    wire:sort:item="{{ $block['id'] }}"
                    wire:click="selectBlock('{{ $block['id'] }}')"
                    @class([
                        'group relative cursor-pointer',
                        'outline outline-2 outline-offset-[-2px] outline-lime-500' => $selectedBlockId === $block['id'],
                    ])
                >
                    <div @class([
                        'pointer-events-none absolute -top-3 start-2.5 z-10 rounded bg-lime-500 px-1.5 py-0.5 text-[10px] font-bold text-slate-950 opacity-0 group-hover:opacity-100',
                        'opacity-100' => $selectedBlockId === $block['id'],
                    ])>{{ $case->label() }}</div>

                    <div @class([
                        'absolute -top-3 end-2.5 z-10 flex gap-0.5 rounded bg-slate-900 p-0.5 opacity-0 group-hover:opacity-100',
                        'opacity-100' => $selectedBlockId === $block['id'],
                    ])>
                        <button type="button" wire:sort:handle class="cursor-grab rounded p-1 text-slate-300 hover:bg-white/15 hover:text-white active:cursor-grabbing" aria-label="Drag to reorder">
                            <flux:icon name="bars-3" class="size-3" />
                        </button>
                        <button type="button" wire:click.stop="moveBlockUp({{ $index }})" class="rounded p-1 text-slate-300 hover:bg-white/15 hover:text-white" aria-label="Move up">
                            <flux:icon name="chevron-up" class="size-3" />
                        </button>
                        <button type="button" wire:click.stop="moveBlockDown({{ $index }})" class="rounded p-1 text-slate-300 hover:bg-white/15 hover:text-white" aria-label="Move down">
                            <flux:icon name="chevron-down" class="size-3" />
                        </button>
                        <button type="button" wire:click.stop="duplicateBlock({{ $index }})" class="rounded p-1 text-slate-300 hover:bg-white/15 hover:text-white" aria-label="Duplicate">
                            <flux:icon name="document-duplicate" class="size-3" />
                        </button>
                        <button type="button" wire:click.stop="removeBlock({{ $index }})" class="rounded p-1 text-slate-300 hover:bg-white/15 hover:text-white" aria-label="Delete">
                            <flux:icon name="trash" class="size-3" />
                        </button>
                    </div>

                    <div class="pointer-events-none p-6">
                        @include('pages.admin.marketing.partials._block-preview', ['case' => $case, 'data' => $block['data']])
                    </div>
                </div>
            @empty
                <div class="p-10 text-center">
                    <flux:icon name="rectangle-stack" class="mx-auto size-8 text-slate-300" />
                    <p class="mt-3 text-sm text-slate-500">Add a block from the left to start building.</p>
                </div>
            @endforelse
        </div>
    </div>

    {{-- SETTINGS --}}
    <div class="{{ $canvasHeight ?? 'max-h-[75vh]' }} overflow-y-auto border-t border-slate-200 bg-white p-4 lg:border-t-0 lg:border-s dark:border-slate-800 dark:bg-slate-950 custom-scrollbar">
        @if (! $selected)
            <div class="py-10 text-center">
                <flux:icon name="cursor-arrow-rays" class="mx-auto size-6 text-slate-300" />
                <p class="mt-3 text-sm text-slate-500">Select a block to edit its settings.</p>
            </div>
        @else
            @php($blockCase = \App\Enums\EmailBlockTypeEnum::from($selected['type']))

            {{-- Keyed to the block, not to the panel. Two blocks of different types
                 both carry a "text" field, and without a key Livewire's morph keeps
                 the textarea it already had — leaving the heading's box still bound
                 to blocks.0.data.text while the paragraph is selected. --}}
            <div wire:key="block-settings-{{ $selected['id'] }}">
            <div class="mb-3">
                <p class="text-[11px] font-semibold uppercase tracking-[0.07em] text-lime-600 dark:text-lime-400">{{ $blockCase->group() }}</p>
                <p class="font-medium text-slate-950 dark:text-white">{{ $blockCase->label() }} settings</p>
            </div>

            @include('pages.admin.marketing.partials._block-settings', ['case' => $blockCase, 'index' => $selectedIndex])
            </div>
        @endif
    </div>
</div>
