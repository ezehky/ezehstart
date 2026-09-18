{{--
    One block's interactive canvas presence — the selectable/hoverable frame with
    its label, drag/move/duplicate/delete controls, and its actual content
    (rendered by _block-preview.blade.php, which stays purely visual). Shared
    between the top-level canvas loop and every column's child list, so a nested
    block gets exactly the same controls a top-level one does — only the actions
    behind those controls differ between the two, which is why they arrive
    pre-built rather than reconstructed here: a top-level block's "move up" is
    moveBlockUp($index), a column child's is
    moveColumnBlockUp($index, $column, $child).

    Expects:
        $block            array   the block itself (id/type/data)
        $case             EmailBlockTypeEnum
        $selectedBlockId  ?string
        $controls         array{select: string, moveUp: string, moveDown: string,
                                  duplicate: string, remove: string} — each a
                                  ready-to-use Livewire action-call expression
        $sortItem         ?string wire:sort:item value. Only top-level blocks are
                                    draggable this way; a nested block omits it and
                                    relies on the up/down arrows instead — nesting
                                    wire:sort inside wire:sort has no clean drop
                                    target once a column is also a drag surface.
--}}
@php
    if ($case instanceof \App\Enums\EmailBlockTypeEnum) {
        $css = app(\App\Services\EmailRenderService::class)->getCss($case, $block['data']);
    }
@endphp

<div
    wire:key="block-{{ $block['id'] }}"
    @if (! empty($sortItem)) wire:sort:item="{{ $sortItem }}" @endif
    wire:click.stop="{{ $controls['select'] }}"
    @class([
        'group relative cursor-pointer',
        'outline outline-2 outline-offset-[-2px] outline-lime-500' => $selectedBlockId === $block['id'],
    ])
>
    {{-- -top-3 --}}
    <div @class([
        'pointer-events-none absolute group-hover:-top-3 start-2.5 z-10',
        'rounded bg-lime-500 px-1.5 py-0.5 text-[10px] font-bold text-slate-950 opacity-0 group-hover:opacity-100',
        'opacity-100 -top-3' => $selectedBlockId === $block['id'],
    ])>{{ $case->label() }}</div>

    <div @class([
        'absolute group-hover:-top-3 end-2.5 z-10 flex gap-0.5 rounded bg-slate-900 p-0.5 opacity-0 group-hover:opacity-100',
        'opacity-100 -top-3' => $selectedBlockId === $block['id'],
    ])>
        @if (! empty($sortItem))
            <button type="button" wire:sort:handle class="cursor-grab rounded p-1 text-slate-300 hover:bg-white/15 hover:text-white active:cursor-grabbing" aria-label="Drag to reorder">
                <flux:icon name="bars-3" class="size-3" />
            </button>
        @endif
        <button type="button" wire:click.stop="{{ $controls['moveUp'] }}" class="rounded p-1 text-slate-300 hover:bg-white/15 hover:text-white" aria-label="Move up">
            <flux:icon name="chevron-up" class="size-3" />
        </button>
        <button type="button" wire:click.stop="{{ $controls['moveDown'] }}" class="rounded p-1 text-slate-300 hover:bg-white/15 hover:text-white" aria-label="Move down">
            <flux:icon name="chevron-down" class="size-3" />
        </button>
        <button type="button" wire:click.stop="{{ $controls['duplicate'] }}" class="rounded p-1 text-slate-300 hover:bg-white/15 hover:text-white" aria-label="Duplicate">
            <flux:icon name="document-duplicate" class="size-3" />
        </button>
        <button type="button" wire:click.stop="{{ $controls['remove'] }}" class="rounded p-1 text-slate-300 hover:bg-white/15 hover:text-white" aria-label="Delete">
            <flux:icon name="trash" class="size-3" />
        </button>
    </div>

    {{-- The base padding is fixed room for the hover controls above; the bottom
         edge alone tracks the block's own "Spacing (px)" field — the same value
         EmailRenderService::padding() renders the real email's row with — so the
         canvas actually shows what changing it does instead of staying flat. --}}
    <div class="pointer-events-none" style="{{ $css['container'] }}">
        @include('pages.admin.marketing.partials._block-preview', ['case' => $case, 'data' => $block['data'], 'css' => $css])
    </div>
</div>
