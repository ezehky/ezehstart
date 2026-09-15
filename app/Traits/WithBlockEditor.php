<?php

namespace App\Traits;

use App\Enums\EmailBlockTypeEnum;
use Illuminate\Support\Str;
use Livewire\Attributes\On;

/**
 * The block-array manipulation every email builder screen needs — the saved section
 * editor, the campaign builder, and the template builder all `use` this rather than
 * each carrying its own copy.
 *
 * A block is `['id' => uuid, 'type' => EmailBlockTypeEnum::value, 'data' => [...]]`.
 * Reordering is either drag-and-drop (`wire:sort` calls reorderBlocks() with the
 * dragged block's id and its new position) or the move-up/move-down buttons, which
 * stay for keyboard and touch. Everything else here works off a plain array index.
 *
 *     public array $blocks = [];
 *     public ?string $selectedBlockId = null;
 *
 *     protected function loadBlocks(array $content): void
 *     {
 *         $this->blocks = $content['blocks'] ?? [];
 *     }
 *
 * and in the markup, one row per block keyed by its own id:
 *
 *     @foreach ($blocks as $index => $block)
 *         <div wire:key="block-{{ $block['id'] }}">...</div>
 *
 *     @endforeach
 *
 * A Columns block is the one exception to "flat array of top-level blocks": each of
 * its `data.columns` entries is itself `['background' => ?, 'background_image_id' =>
 * ?, 'blocks' => [...]]`, holding its own list of blocks in exactly the same shape as
 * the top level. Nesting stops there — a column's own blocks are never Columns or
 * Section (see EmailBlockTypeEnum::nestable()), so every lookup below only ever has
 * to look one level down, never recurse arbitrarily deep. Everything that touches a
 * block by id rather than a top-level index (pathFor(), findBlock(), the image-slot
 * and list-field helpers) exists because of that one extra level: a plain int index
 * stops being enough once the same id might live inside a column instead of at the
 * top.
 */
trait WithBlockEditor
{
    /**
     * @var array<int, array{id: string, type: string, data: array<string, mixed>}>
     */
    public array $blocks = [];

    public ?string $selectedBlockId = null;

    public string $blockSettingsTab = 'content';

    public function addBlock(string $type, ?int $afterIndex = null): void
    {
        $case = EmailBlockTypeEnum::tryFrom($type);

        if (! $case) {
            return;
        }

        $block = ['id' => (string) Str::uuid(), 'type' => $case->value, 'data' => $case->defaultData()];

        if ($afterIndex === null || $afterIndex >= count($this->blocks) - 1) {
            $this->blocks[] = $block;
        } else {
            array_splice($this->blocks, $afterIndex + 1, 0, [$block]);
        }

        $this->selectedBlockId = $block['id'];
        $this->blockSettingsTab = 'content';
    }

    public function selectBlock(?string $blockId): void
    {
        $this->selectedBlockId = $blockId;
        $this->blockSettingsTab = 'content';
    }

    public function removeBlock(int $index): void
    {
        if (($this->blocks[$index]['id'] ?? null) === $this->selectedBlockId) {
            $this->selectedBlockId = null;
        }

        unset($this->blocks[$index]);
        $this->blocks = array_values($this->blocks);
    }

    public function duplicateBlock(int $index): void
    {
        if (! isset($this->blocks[$index])) {
            return;
        }

        $copy = $this->blocks[$index];
        $copy['id'] = (string) Str::uuid();

        array_splice($this->blocks, $index + 1, 0, [$copy]);

        $this->selectedBlockId = $copy['id'];
    }

    public function moveBlockUp(int $index): void
    {
        if ($index <= 0) {
            return;
        }

        [$this->blocks[$index - 1], $this->blocks[$index]] = [$this->blocks[$index], $this->blocks[$index - 1]];
    }

    public function moveBlockDown(int $index): void
    {
        if ($index >= count($this->blocks) - 1) {
            return;
        }

        [$this->blocks[$index + 1], $this->blocks[$index]] = [$this->blocks[$index], $this->blocks[$index + 1]];
    }

    /**
     * The drag-and-drop landing. `wire:sort` hands back the dragged block's own id
     * and the index it was dropped at, rather than the whole new order — an id is
     * the only thing that survives a canvas re-render mid-drag. Only top-level
     * blocks are draggable this way; a column's children reorder with the
     * move-up/move-down pair instead (see moveColumnBlockUp()/Down()).
     */
    public function reorderBlocks(?string $blockId, ?int $position): void
    {
        if ($blockId === null || $position === null) {
            return;
        }

        $from = null;

        foreach ($this->blocks as $index => $block) {
            if ($block['id'] === $blockId) {
                $from = $index;

                break;
            }
        }

        if ($from === null) {
            return;
        }

        $moved = array_splice($this->blocks, $from, 1);

        array_splice($this->blocks, max(0, min($position, count($this->blocks))), 0, $moved);
    }

    /**
     * Add a block straight into one column of a Columns block — the canvas's own
     * per-column appender, the equivalent of addBlock() for anything that isn't
     * top-level. Silently ignores a type EmailBlockTypeEnum::nestable() doesn't
     * list rather than trusting the click came from that same list: the palette
     * only ever renders nestable() cases, but the action name is still reachable
     * directly.
     */
    public function addColumnBlock(string $type, int $index, int $column): void
    {
        $case = EmailBlockTypeEnum::tryFrom($type);

        if (! $case || ! in_array($case, EmailBlockTypeEnum::nestable(), true)) {
            return;
        }

        if (! isset($this->blocks[$index]['data']['columns'][$column])) {
            return;
        }

        $block = ['id' => (string) Str::uuid(), 'type' => $case->value, 'data' => $case->defaultData()];

        $this->blocks[$index]['data']['columns'][$column]['blocks'][] = $block;
        $this->selectedBlockId = $block['id'];
        $this->blockSettingsTab = 'content';
    }

    public function removeColumnBlock(int $index, int $column, int $child): void
    {
        if (! isset($this->blocks[$index]['data']['columns'][$column]['blocks'][$child])) {
            return;
        }

        if ($this->blocks[$index]['data']['columns'][$column]['blocks'][$child]['id'] === $this->selectedBlockId) {
            $this->selectedBlockId = null;
        }

        unset($this->blocks[$index]['data']['columns'][$column]['blocks'][$child]);
        $this->blocks[$index]['data']['columns'][$column]['blocks'] = array_values($this->blocks[$index]['data']['columns'][$column]['blocks']);
    }

    public function duplicateColumnBlock(int $index, int $column, int $child): void
    {
        if (! isset($this->blocks[$index]['data']['columns'][$column]['blocks'][$child])) {
            return;
        }

        $copy = $this->blocks[$index]['data']['columns'][$column]['blocks'][$child];
        $copy['id'] = (string) Str::uuid();

        array_splice($this->blocks[$index]['data']['columns'][$column]['blocks'], $child + 1, 0, [$copy]);

        $this->selectedBlockId = $copy['id'];
    }

    public function moveColumnBlockUp(int $index, int $column, int $child): void
    {
        if ($child <= 0 || ! isset($this->blocks[$index]['data']['columns'][$column]['blocks'][$child - 1])) {
            return;
        }

        [
            $this->blocks[$index]['data']['columns'][$column]['blocks'][$child - 1],
            $this->blocks[$index]['data']['columns'][$column]['blocks'][$child],
        ] = [
            $this->blocks[$index]['data']['columns'][$column]['blocks'][$child],
            $this->blocks[$index]['data']['columns'][$column]['blocks'][$child - 1],
        ];
    }

    public function moveColumnBlockDown(int $index, int $column, int $child): void
    {
        $count = count($this->blocks[$index]['data']['columns'][$column]['blocks'] ?? []);

        if ($child >= $count - 1) {
            return;
        }

        [
            $this->blocks[$index]['data']['columns'][$column]['blocks'][$child + 1],
            $this->blocks[$index]['data']['columns'][$column]['blocks'][$child],
        ] = [
            $this->blocks[$index]['data']['columns'][$column]['blocks'][$child],
            $this->blocks[$index]['data']['columns'][$column]['blocks'][$child + 1],
        ];
    }

    /**
     * Append a variable token to the end of one block's text field — the
     * "+ Personalize" control's wire:click target. Takes a block id rather than a
     * top-level index because the field it is appending to might belong to a
     * block nested inside a column.
     */
    public function insertToken(string $blockId, string $field, string $token): void
    {
        $block = $this->findBlock($blockId);

        if (! $block || ! isset($block['data'][$field])) {
            return;
        }

        $current = (string) $block['data'][$field];

        $this->setBlockDataField($blockId, $field, trim($current.' '.$token));
    }

    /**
     * Open the shared media library picker for one image slot. The slot name
     * carries both which value it fills and where that value lives, rather than
     * going through WithImagePicker's declared slots — an email can hold any
     * number of image-bearing blocks and columns, so there is no fixed slot list
     * to declare them against:
     *
     *     "img:{blockId}"              a block's own image_id
     *     "bg:{blockId}"                a block's own background_image_id
     *     "colbg:{blockId}:{column}"    one column's background_image_id, on the
     *                                    Columns block identified by blockId —
     *                                    columns have no id of their own, so this
     *                                    one case stays positional
     */
    public function chooseImage(string $slot): void
    {
        $this->dispatch('open-image-picker', slot: $slot, multiple: false, max: 1, selected: []);
    }

    /**
     * Clear one image slot's picture without clearing the alt text, link and
     * sizing around it — the picker has no "nothing" to choose, so removing is a
     * control of its own next to it.
     */
    public function removeBlockImage(string $slot): void
    {
        $this->applyImageSlot($slot, null);
    }

    /**
     * @param  array<int, int>  $ids
     */
    #[On('imagesSelected')]
    public function whenBlockImageSelected(array $ids, array $urls = [], ?string $slot = null): void
    {
        if ($slot === null) {
            return;
        }

        $this->applyImageSlot($slot, $ids[0] ?? null);
    }

    private function applyImageSlot(string $slot, ?int $imageId): void
    {
        if (preg_match('/^colbg:(?<id>.+):(?<column>\d+)$/', $slot, $m)) {
            foreach ($this->blocks as $index => $block) {
                if ($block['id'] === $m['id'] && isset($this->blocks[$index]['data']['columns'][(int) $m['column']])) {
                    $this->blocks[$index]['data']['columns'][(int) $m['column']]['background_image_id'] = $imageId;
                }
            }

            return;
        }

        if (preg_match('/^(?<scope>img|bg):(?<id>.+)$/', $slot, $m)) {
            $this->setBlockDataField($m['id'], $m['scope'] === 'bg' ? 'background_image_id' : 'image_id', $imageId);
        }
    }

    /**
     * Add a column to a Columns block, up to four — a row wide enough to hold a
     * fifth would stop reading as a row in most inboxes' width. New columns start
     * empty, with no background and no blocks of their own.
     */
    public function addColumn(int $index): void
    {
        if (! isset($this->blocks[$index]['data']['columns']) || count($this->blocks[$index]['data']['columns']) >= 4) {
            return;
        }

        $this->blocks[$index]['data']['columns'][] = [
            'background' => null,
            'background_image_id' => null,
            'blocks' => [],
        ];
    }

    /**
     * Remove one column from a Columns block, never down to zero — an empty
     * columns block has nothing left to render.
     */
    public function removeColumn(int $index, int $column): void
    {
        if (! isset($this->blocks[$index]['data']['columns']) || count($this->blocks[$index]['data']['columns']) <= 1) {
            return;
        }

        unset($this->blocks[$index]['data']['columns'][$column]);
        $this->blocks[$index]['data']['columns'] = array_values($this->blocks[$index]['data']['columns']);
    }

    /**
     * Add a row to a Socials block's own link list — only reachable while its
     * source is "custom"; a "config" block has nothing of its own to add to.
     * Takes a block id, not a top-level index, since the Socials block itself
     * might now be nested inside a column.
     */
    public function addSocialLink(string $blockId): void
    {
        $this->pushBlockDataListItem($blockId, 'custom_links', ['label' => '', 'url' => '', 'platform' => '']);
    }

    public function removeSocialLink(string $blockId, int $link): void
    {
        $this->removeBlockDataListItem($blockId, 'custom_links', $link);
    }

    private function pushBlockDataListItem(string $blockId, string $field, array $item): void
    {
        $block = $this->findBlock($blockId);

        if (! $block) {
            return;
        }

        $list = $block['data'][$field] ?? [];
        $list[] = $item;

        $this->setBlockDataField($blockId, $field, $list);
    }

    private function removeBlockDataListItem(string $blockId, string $field, int $itemIndex): void
    {
        $block = $this->findBlock($blockId);

        if (! $block || ! isset($block['data'][$field][$itemIndex])) {
            return;
        }

        $list = $block['data'][$field];
        unset($list[$itemIndex]);

        $this->setBlockDataField($blockId, $field, array_values($list));
    }

    protected function selectedBlockIndex(): ?int
    {
        foreach ($this->blocks as $index => $block) {
            if ($block['id'] === $this->selectedBlockId) {
                return $index;
            }
        }

        return null;
    }

    /**
     * @return array{id: string, type: string, data: array<string, mixed>}|null
     */
    public function selectedBlock(): ?array
    {
        return $this->selectedBlockId ? $this->findBlock($this->selectedBlockId) : null;
    }

    /**
     * A block by id, wherever it lives — top-level or one column deep. Blade reads
     * this for the settings panel and for every id-keyed lookup (an image's own
     * row, a Socials block's link list) rather than indexing into $blocks
     * directly, which only ever finds a top-level block.
     *
     * @return array{id: string, type: string, data: array<string, mixed>}|null
     */
    public function findBlock(string $blockId): ?array
    {
        foreach ($this->blocks as $block) {
            if ($block['id'] === $blockId) {
                return $block;
            }

            foreach ($block['data']['columns'] ?? [] as $column) {
                foreach ($column['blocks'] ?? [] as $child) {
                    if ($child['id'] === $blockId) {
                        return $child;
                    }
                }
            }
        }

        return null;
    }

    /**
     * The Livewire binding-path prefix for one block's own array entry — the
     * settings panel appends ".data" itself. "blocks.2" for a top-level block,
     * "blocks.2.data.columns.1.blocks.0" for the first child of the second column
     * of the Columns block at top-level index 2. Columns can't nest, so a match
     * found inside a column's children is always exactly this shape — never
     * deeper.
     */
    public function pathFor(string $blockId): ?string
    {
        foreach ($this->blocks as $index => $block) {
            if ($block['id'] === $blockId) {
                return "blocks.{$index}";
            }

            foreach ($block['data']['columns'] ?? [] as $columnIndex => $column) {
                foreach ($column['blocks'] ?? [] as $childIndex => $child) {
                    if ($child['id'] === $blockId) {
                        return "blocks.{$index}.data.columns.{$columnIndex}.blocks.{$childIndex}";
                    }
                }
            }
        }

        return null;
    }

    /**
     * Writes one field on a block's own data, wherever that block lives — the
     * shared landing spot for the image-slot and list-field helpers above, none
     * of which know or care whether the block they're touching is top-level or a
     * column's child.
     */
    private function setBlockDataField(string $blockId, string $field, mixed $value): void
    {
        foreach ($this->blocks as $index => $block) {
            if ($block['id'] === $blockId) {
                $this->blocks[$index]['data'][$field] = $value;

                return;
            }

            foreach ($block['data']['columns'] ?? [] as $columnIndex => $column) {
                foreach ($column['blocks'] ?? [] as $childIndex => $child) {
                    if ($child['id'] === $blockId) {
                        $this->blocks[$index]['data']['columns'][$columnIndex]['blocks'][$childIndex]['data'][$field] = $value;

                        return;
                    }
                }
            }
        }
    }

    /**
     * @return array{blocks: array<int, array{id: string, type: string, data: array<string, mixed>}>}
     */
    protected function blockContent(): array
    {
        return ['blocks' => $this->blocks];
    }
}
