{{--
    A Columns block's own canvas presence: selectable/draggable among the other
    top-level blocks exactly like _block-canvas-item.blade.php's frame, but built
    inline here rather than reusing that partial, since a row's body is a grid of
    columns rather than one _block-preview include. Each column renders its own
    children through _block-canvas-item.blade.php — the same selectable frame a
    top-level block gets — and ends in a small appender that drops a new block
    straight into that column, the canvas equivalent of the left palette.

    Expects:
        $block             array   the Columns block itself (id/type/data)
        $index             int     its top-level index — every action below is
                                     scoped to this one Columns block, which is
                                     always top-level (columns don't nest)
        $selectedBlockId   ?string
--}}

@php
    $rowSelected = $selectedBlockId === $block['id'];
    $rowBgImage = ! empty($block['data']['background_image_id']) ? \App\Models\Image::find($block['data']['background_image_id']) : null;
    $columns = $block['data']['columns'] ?? [];
@endphp

<div
    wire:key="block-{{ $block['id'] }}"
    wire:sort:item="{{ $block['id'] }}"
    wire:click.stop="selectBlock('{{ $block['id'] }}')"
    @class([
        'group relative cursor-pointer',
        'outline outline-2 outline-offset-[-2px] outline-lime-500' => $rowSelected,
    ])
>
    <div @class([
        'pointer-events-none absolute -top-3 start-2.5 z-10 rounded bg-lime-500 px-1.5 py-0.5 text-[10px] font-bold text-slate-950 opacity-0 group-hover:opacity-100',
        'opacity-100' => $rowSelected,
    ])>Columns</div>

    <div @class([
        'absolute -top-3 end-2.5 z-10 flex gap-0.5 rounded bg-slate-900 p-0.5 opacity-0 group-hover:opacity-100',
        'opacity-100' => $rowSelected,
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

    <div
        class="grid gap-3 p-6"
        style="grid-template-columns: repeat({{ count($columns) ?: 2 }}, 1fr); {{ ! empty($block['data']['background']) ? 'background-color:'.$block['data']['background'].';' : '' }} {{ $rowBgImage ? 'background-image:url('.$rowBgImage->url().');background-size:cover;background-position:center;' : '' }}"
    >
        @foreach ($columns as $columnIndex => $column)
            @php($colBgImage = ! empty($column['background_image_id']) ? \App\Models\Image::find($column['background_image_id']) : null)
            <div
                wire:key="col-{{ $block['id'] }}-{{ $columnIndex }}"
                class="min-h-[70px] space-y-1.5 rounded-lg border border-dashed border-slate-300 p-1.5 dark:border-slate-600"
                style="{{ ! empty($column['background']) ? 'background-color:'.$column['background'].';' : '' }} {{ $colBgImage ? 'background-image:url('.$colBgImage->url().');background-size:cover;background-position:center;' : '' }}"
            >
                @forelse ($column['blocks'] ?? [] as $childIndex => $child)
                    @php($childCase = \App\Enums\EmailBlockTypeEnum::tryFrom($child['type']))
                    @continue(! $childCase)

                    @include('pages.admin.marketing.partials._block-canvas-item', [
                        'block' => $child,
                        'case' => $childCase,
                        'selectedBlockId' => $selectedBlockId,
                        'sortItem' => null,
                        'controls' => [
                            'select' => "selectBlock('{$child['id']}')",
                            'moveUp' => "moveColumnBlockUp({$index}, {$columnIndex}, {$childIndex})",
                            'moveDown' => "moveColumnBlockDown({$index}, {$columnIndex}, {$childIndex})",
                            'duplicate' => "duplicateColumnBlock({$index}, {$columnIndex}, {$childIndex})",
                            'remove' => "removeColumnBlock({$index}, {$columnIndex}, {$childIndex})",
                        ],
                    ])
                @empty
                    <p class="p-3 text-center text-[11px] text-slate-400">Empty column</p>
                @endforelse

                <div class="flex flex-wrap justify-center gap-1 pt-1" wire:click.stop>
                    @foreach (\App\Enums\EmailBlockTypeEnum::nestable() as $nestCase)
                        <button
                            type="button"
                            wire:click.stop="addColumnBlock('{{ $nestCase->value }}', {{ $index }}, {{ $columnIndex }})"
                            title="Add {{ $nestCase->label() }}"
                            class="press rounded border border-slate-200 p-1.5 text-slate-400 hover:border-lime-400 hover:text-lime-600 dark:border-slate-700 dark:hover:border-lime-400"
                        >
                            <flux:icon :name="$nestCase->icon()" class="size-3.5" />
                        </button>
                    @endforeach
                </div>
            </div>
        @endforeach
    </div>
</div>
