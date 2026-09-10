<?php

use App\Models\Video;
use App\Traits\WithFormResponseMessage;
use App\Traits\WithVideoLibrary;
use Livewire\Component;

/**
 * The video library picker.
 *
 * Dropped once onto any screen that needs to choose a video. It answers two kinds
 * of caller, exactly as the image picker does:
 *
 *  - the tiptap editor, which names no slot, listens for the `video-picked`
 *    browser event and only wants an embed URL back
 *  - a Livewire parent, which names a slot and listens for `videoSelected` (one
 *    video) or `videosSelected` (several), wanting ids so it can record the usage
 *
 * The slot is echoed back with the answer and is what keeps those two apart: one
 * screen can hold a trailer and a playlist, and choosing a trailer no longer drops
 * the video into the body of whatever is being written. App\Traits\WithVideoPicker
 * is the far end of that conversation.
 *
 * It never decides what the video is *for* — that is the caller's business.
 *
 * Browsing, folders, adding, editing, moving and deleting all come from
 * WithVideoLibrary and the lv._video-library partial, so this dialog and the
 * full-page library are the same screen in two frames.
 */
new class extends Component
{
    use WithFormResponseMessage, WithVideoLibrary;

    public bool $show = false;

    /**
     * A dialog shows fewer at a time than a page does — it is a picker, not a
     * place to spend the afternoon.
     */
    protected function perPage(): int
    {
        return 9;
    }

    /**
     * The caller sets the terms as it opens the picker, so one screen can pick a
     * single trailer in one place and a playlist in another without mounting the
     * component twice.
     *
     * `$selected` is what the calling slot already holds. Reopening a playlist has
     * to show those ticked, or confirming would quietly replace them with whatever
     * was chosen this time round.
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

        unset($this->videos, $this->folders);
    }

    public function close(): void
    {
        $this->show = false;

        $this->reset('search', 'folder', 'selected', 'panel', 'slot');
    }

    /**
     * One video, chosen in single-pick mode. There is nothing to confirm when only
     * one can win, so the click is the answer.
     */
    protected function pickedSingle(): void
    {
        $this->confirmSelection();
    }

    public function confirmSelection(): bool
    {
        $videos = Video::query()
            ->whereKey($this->selected)
            ->get()
            ->filter(fn (Video $video) => $video->isVisibleTo($this->user))
            ->values();

        $this->respondError('Choose a video first.', $videos->isEmpty());

        $first = $videos->first();
        $slot = $this->slot;

        // Two audiences, two events. The browser event feeds the tiptap editor,
        // and only when nobody named a slot — a trailer chosen for a form must not
        // also drop itself into the body being written. The Livewire events feed a
        // parent that needs ids to record usages, and carry the slot that asked.
        //
        // The editor is handed the embed URL rather than the row, because it is the
        // only thing an iframe can use — and it is rebuilt from the provider and the
        // id, never read from a column somebody could have edited.
        if ($slot === null) {
            $this->dispatch('video-picked', url: $first->embedUrl(), title: $first->title);
        }

        $this->dispatch('videoSelected', videoId: $first->id, url: $first->embedUrl(), slot: $slot);

        $this->dispatch(
            'videosSelected',
            ids: $videos->pluck('id')->all(),
            urls: $videos->map->embedUrl()->all(),
            slot: $slot,
        );

        $this->close();

        return true;
    }
};
?>

<div
    x-on:open-video-picker.window="$wire.open(
        $event.detail?.multiple ?? null,
        $event.detail?.max ?? null,
        $event.detail?.slot ?? null,
        $event.detail?.selected ?? null,
    )"
>
    <flux:modal wire:model="show" name="videoPicker" class="max-w-4xl">
        <div class="space-y-5">
            <div>
                <flux:heading size="lg" class="font-heading font-bold">Choose a video</flux:heading>
                <flux:text class="mt-1">
                    @if ($multiple)
                        Pick as many as you need{{ $max ? ', up to '.$max : '' }}, or add one with a link.
                    @else
                        Pick one, or add one with a link.
                    @endif
                </flux:text>
            </div>

            @include('components.lv._video-library')

            @if ($multiple && $tab === 'library')
                <div class="flex justify-end gap-3 border-t border-slate-200 pt-4 dark:border-slate-700">
                    <flux:button variant="ghost" wire:click="close">Cancel</flux:button>
                    <flux:button variant="primary" wire:click="confirmSelection">
                        Use {{ count($selected) }} video(s)
                    </flux:button>
                </div>
            @endif
        </div>
    </flux:modal>
</div>
