<?php

use App\Models\Image;
use App\Traits\WithFormResponseMessage;
use App\Traits\WithImageLibrary;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * The library picker.
 *
 * Dropped once onto any screen that needs to choose an image. It answers two
 * kinds of caller:
 *
 *  - the tiptap editor, which names no slot, listens for the `image-picked`
 *    browser event and only wants a URL back
 *  - a Livewire parent, which names a slot and listens for `imageSelected` (one
 *    image) or `imagesSelected` (several), wanting ids so it can record the usage
 *
 * The slot is echoed back with the answer and is what keeps those two apart: one
 * screen can hold a cover and a gallery, and choosing a cover no longer drops the
 * image into the body of whatever is being written. App\Traits\WithImagePicker is
 * the far end of that conversation.
 *
 * It never decides what the image is *for* — that is the caller's business.
 *
 * Browsing, folders, editing, moving and deleting all come from WithImageLibrary
 * and the library._library partial, so this dialog and the full-page library are the
 * same screen in two frames. What is added here is the frame and the answer.
 */
new class extends Component
{
    use WithFormResponseMessage, WithImageLibrary;

    public bool $show = false;

    /**
     * A dialog shows fewer at a time than a page does — it is a picker, not a
     * place to spend the afternoon.
     */
    protected function perPage(): int
    {
        return 12;
    }

    /**
     * The caller sets the terms as it opens the picker, so one screen can pick a
     * single cover image in one place and a gallery in another without mounting
     * the component twice.
     *
     * `$selected` is what the calling slot already holds. Reopening a gallery has
     * to show those six ticked, or confirming would quietly replace them with
     * whatever was chosen this time round.
     *
     * @param  array<int, int>|null  $selected
     */
    public function open(?bool $multiple = null, ?int $max = null, ?string $slot = null, ?array $selected = null): void
    {
        $this->show = true;
        $this->tab = 'library';
        $this->multiple = $multiple ?? false;
        $this->max = $max;
        $this->slot = $slot;

        $this->reset('selected', 'panel', 'search');

        if ($this->multiple && filled($selected)) {
            $this->selected = collect($selected)->map(fn ($id) => (int) $id)->filter()->unique()->values()->all();
        }

        $this->resetPage();

        unset($this->images, $this->folders);
    }

    public function close(): void
    {
        $this->show = false;

        $this->reset('search', 'folder', 'selected', 'panel', 'slot');
    }

    /**
     * @param  array<int, int>  $ids
     */
    #[On('imagesUploaded')]
    public function whenUploaded(array $ids): void
    {
        $this->afterUpload($ids);
    }

    /**
     * One image, chosen in single-pick mode. There is nothing to confirm when
     * only one can win, so the click is the answer.
     */
    protected function pickedSingle(): void
    {
        $this->confirmSelection();
    }

    public function confirmSelection(): bool
    {
        $images = Image::query()
            ->whereKey($this->selected)
            ->get()
            ->filter(fn (Image $image) => $image->isVisibleTo($this->user, $this->owner !== null))
            ->values();

        $this->respondError('Choose an image first.', $images->isEmpty());

        $first = $images->first();
        $slot = $this->slot;

        // Two audiences, two events. The browser event feeds the tiptap editor,
        // and only when nobody named a slot — a cover chosen for a form must not
        // also drop itself into the body being written. The Livewire events feed a
        // parent that needs ids to record usages, and carry the slot that asked.
        if ($slot === null) {
            $this->dispatch('image-picked', url: $first->url(), alt: $first->alt_text ?: $first->title);
        }

        $this->dispatch('imageSelected', imageId: $first->id, url: $first->url(), slot: $slot);

        $this->dispatch(
            'imagesSelected',
            ids: $images->pluck('id')->all(),
            urls: $images->map->url()->all(),
            slot: $slot,
        );

        $this->close();

        return true;
    }
};
?>

<div
    x-on:open-image-picker.window="$wire.open(
        $event.detail?.multiple ?? null,
        $event.detail?.max ?? null,
        $event.detail?.slot ?? null,
        $event.detail?.selected ?? null,
    )"
>
    <flux:modal wire:model="show" name="imagePicker" class="max-w-4xl">
        <div class="space-y-5">
            <div>
                <flux:heading size="lg" class="font-heading font-bold">Choose an image</flux:heading>
                <flux:text class="mt-1">
                    @if ($multiple)
                        Pick as many as you need{{ $max ? ', up to '.$max : '' }}, or upload something new.
                    @else
                        Pick one, or upload something new.
                    @endif
                </flux:text>
            </div>

            @include('components.library._library')

            @if ($multiple && $tab === 'library')
                <div class="flex justify-end gap-3 border-t border-slate-200 pt-4 dark:border-slate-700">
                    <flux:button variant="ghost" wire:click="close">Cancel</flux:button>
                    <flux:button variant="primary" wire:click="confirmSelection">
                        Use {{ count($selected) }} image(s)
                    </flux:button>
                </div>
            @endif
        </div>
    </flux:modal>
</div>
