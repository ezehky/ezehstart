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
     * the only thing that survives a canvas re-render mid-drag.
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
     * Append a variable token to the end of one block's text field — the
     * "+ Personalize" control's wire:click target.
     */
    public function insertToken(int $index, string $field, string $token): void
    {
        if (! isset($this->blocks[$index]['data'][$field])) {
            return;
        }

        $current = (string) $this->blocks[$index]['data'][$field];

        $this->blocks[$index]['data'][$field] = trim($current.' '.$token);
    }

    /**
     * Open the shared media library picker for one image block. The slot name
     * encodes the block's index ("block-3") rather than going through
     * WithImagePicker's declared slots — an email can hold any number of image
     * blocks, so there is no fixed slot list to declare them against.
     */
    public function chooseImage(string $blockKey): void
    {
        $this->dispatch('open-image-picker', slot: $blockKey, multiple: false, max: 1, selected: []);
    }

    /**
     * Clear one image block's picture without clearing the alt text, link and
     * sizing around it — the picker has no "nothing" to choose, so removing is a
     * control of its own next to it.
     */
    public function removeBlockImage(int $index): void
    {
        if (isset($this->blocks[$index]['data'])) {
            $this->blocks[$index]['data']['image_id'] = null;
        }
    }

    /**
     * @param  array<int, int>  $ids
     */
    #[On('imagesSelected')]
    public function whenBlockImageSelected(array $ids, array $urls = [], ?string $slot = null): void
    {
        if ($slot === null || ! str_starts_with($slot, 'block-')) {
            return;
        }

        $index = (int) substr($slot, strlen('block-'));

        if (isset($this->blocks[$index])) {
            $this->blocks[$index]['data']['image_id'] = $ids[0] ?? null;
        }
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
        $index = $this->selectedBlockIndex();

        return $index === null ? null : $this->blocks[$index];
    }

    /**
     * @return array{blocks: array<int, array{id: string, type: string, data: array<string, mixed>}>}
     */
    protected function blockContent(): array
    {
        return ['blocks' => $this->blocks];
    }
}
