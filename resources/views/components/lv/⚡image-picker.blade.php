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
 *  - the tiptap editor, which listens for the `image-picked` browser event and
 *    only wants a URL back
 *  - a Livewire parent, which listens for `imageSelected` (one image) or
 *    `imagesSelected` (several) and wants ids so it can record the usage
 *
 * It never decides what the image is *for* — that is the caller's business.
 *
 * Browsing, folders, editing, moving and deleting all come from WithImageLibrary
 * and the lv._library partial, so this dialog and the full-page library are the
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
     * The caller may override the selection mode as it opens the picker, so one
     * screen can pick a single cover image in one place and a gallery in another
     * without mounting the component twice.
     */
    public function open(?bool $multiple = null, ?int $max = null): void
    {
        $this->show = true;
        $this->tab = 'library';
        $this->multiple = $multiple ?? false;

        if ($max !== null) {
            $this->max = $max;
        }

        $this->reset('selected', 'panel', 'search');
        $this->resetPage();

        unset($this->images, $this->folders);
    }

    public function close(): void
    {
        $this->show = false;

        $this->reset('search', 'folder', 'selected', 'panel');
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
            ->filter(fn (Image $image) => $image->isVisibleTo($this->user))
            ->values();

        $this->respondError('Choose an image first.', $images->isEmpty());

        $first = $images->first();

        // Two audiences, two events. The browser event feeds the tiptap editor;
        // the Livewire events feed a parent that needs ids to record usages.
        $this->dispatch('image-picked', url: $first->url(), alt: $first->alt_text ?: $first->title);

        $this->dispatch('imageSelected', imageId: $first->id, url: $first->url());

        $this->dispatch('imagesSelected', ids: $images->pluck('id')->all(), urls: $images->map->url()->all());

        $this->close();

        return true;
    }
};
?>

<div x-on:open-image-picker.window="$wire.open($event.detail?.multiple ?? null, $event.detail?.max ?? null)">
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

            @include('components.lv._library')

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
