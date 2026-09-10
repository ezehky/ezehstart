<?php

use App\Enums\ImageVisibilityEnum;
use App\Models\ImageFolder;
use App\Models\User;
use App\Rules\ImageRule;
use App\Services\ImageLibraryService;
use App\Traits\WithFormResponseMessage;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * The upload panel.
 *
 * Lives on its own so it can be dropped anywhere an account needs to put images
 * into the library — the picker's Upload tab is only its first caller. It knows
 * how to stage files, show what is staged, and store the batch; it does not know
 * or care what the images are then used for.
 *
 * Files are staged rather than stored on selection, because somebody who has just
 * dragged in six photos and spotted a wrong one should be able to drop it before
 * anything is written.
 */
new class extends Component
{
    use WithFileUploads, WithFormResponseMessage;

    public User $user;

    /** The folder new uploads land in. Null is the root of the library. */
    public ?int $folder = null;

    public string $heading = 'Upload images';

    public string $subheading = 'Add high-quality images to reuse anywhere on the site.';

    public string $action = 'Continue';

    /**
     * Staged files, not stored ones. Livewire appends to this on every pick, so
     * dragging twice adds to the list rather than replacing it.
     *
     * @var array<int, mixed>
     */
    public array $imagesUpload = [];

    public function mount(): void
    {
        $this->user = auth()->user();
    }

    #[Computed]
    public function maxSize(): int
    {
        return app(ImageLibraryService::class)->maxImageSize();
    }

    #[Computed]
    public function remaining(): ?int
    {
        return app(ImageLibraryService::class)->remainingUploadsFor($this->user);
    }

    protected function rules(): array
    {
        return [
            'imagesUpload' => ['required', 'array', 'min:1'],
            'imagesUpload.*' => [new ImageRule(size: $this->maxSize)],
        ];
    }

    /**
     * Drop one staged file before anything is written.
     *
     * The array is re-indexed afterwards: Livewire renders it by position, and a
     * gap in the keys makes the wrong row disappear on the next render.
     */
    public function removeStaged(int $index): void
    {
        unset($this->imagesUpload[$index]);

        $this->imagesUpload = array_values($this->imagesUpload);

        $this->resetValidation();
    }

    public function clearStaged(): void
    {
        $this->reset('imagesUpload');
        $this->resetValidation();
    }

    /**
     * Named uploadImages() and not upload(): Livewire aliases `upload` on the
     * $wire object to its own $upload() helper, so `wire:submit="upload"` calls
     * that helper with no arguments and dies in the browser before a request is
     * ever sent. Any component method sharing a name with a $wire alias is
     * unreachable from Blade.
     */
    public function uploadImages(): bool
    {
        $this->validate();

        $folder = $this->folder ? ImageFolder::query()->find($this->folder) : null;

        // The folder's own visibility is where a new image starts, so uploading
        // into a role-restricted folder does not quietly produce private images
        // nobody else in that role can find.
        $result = app(ImageLibraryService::class)->storeMany(
            $this->user,
            $this->imagesUpload,
            $folder,
            $folder?->visibility ?? ImageVisibilityEnum::PRIVATE,
            $folder?->visible_to_role,
        );

        $this->reset('imagesUpload');
        unset($this->remaining);

        $this->respondError(
            'Your upload limit is full, so nothing was saved.',
            $result['stored']->isEmpty(),
        );

        // The ids go back to whoever mounted this, so the picker can preselect
        // what was just uploaded and any other caller can do as it likes.
        $this->dispatch('imagesUploaded', ids: $result['stored']->pluck('id')->all());

        if ($result['skipped'] > 0) {
            return $this->respondSuccess(
                "{$result['stored']->count()} image(s) uploaded. {$result['skipped']} skipped — your upload limit is full."
            );
        }

        return $this->respondSuccess("{$result['stored']->count()} image(s) uploaded.");
    }
};
?>

<div class="space-y-5">
    <div>
        <flux:heading level="2" size="lg" class="font-heading font-bold">{{ $heading }}</flux:heading>
        <flux:text class="mt-1">{{ $subheading }}</flux:text>
    </div>

    {{-- Drop zone --}}
    <div
        x-data="{ dragging: false, uploading: false, progress: 0 }"
        x-on:livewire-upload-start="uploading = true; progress = 0"
        x-on:livewire-upload-progress="progress = $event.detail.progress"
        x-on:livewire-upload-error="uploading = false"
        x-on:livewire-upload-cancel="uploading = false"
        x-on:livewire-upload-finish="uploading = false"
        x-on:dragover.prevent="dragging = true"
        x-on:dragleave.prevent="dragging = false"
        x-on:drop.prevent="
            dragging = false;
            $refs.input.files = $event.dataTransfer.files;
            $refs.input.dispatchEvent(new Event('change'));
        "
    >
        <input
            x-ref="input"
            type="file"
            class="sr-only"
            wire:model="imagesUpload"
            accept="image/jpeg,image/png,image/webp"
            multiple
        />

        <button
            type="button"
            x-on:click="$refs.input.click()"
            x-bind:disabled="uploading"
            x-bind:class="dragging
                ? 'border-accent bg-accent/10'
                : 'border-slate-300 bg-slate-50 hover:border-slate-400 hover:bg-slate-100 dark:border-white/15 dark:bg-white/5 dark:hover:border-white/30 dark:hover:bg-white/10'"
            class="flex w-full flex-col items-center justify-center gap-2 rounded-xl border border-dashed px-6 py-10 text-center transition focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2 disabled:cursor-wait dark:border-white/15 dark:focus-visible:ring-white dark:focus-visible:ring-offset-slate-950"
        >
            <template x-if="! uploading">
                <div class="flex flex-col items-center gap-2">
                    <flux:icon.photo class="size-8 text-slate-300 dark:text-slate-600" />

                    <p class="text-sm text-slate-600 dark:text-slate-300">
                        Drag and drop an image or <span class="font-semibold underline">Browse</span>
                    </p>

                    <p class="text-xs text-slate-400 dark:text-slate-500">
                        JPEG, PNG or WEBP up to {{ $this->maxSize }} KB
                        @if ($this->remaining !== null)
                            · {{ $this->remaining }} left on your account
                        @endif
                    </p>
                </div>
            </template>

            <template x-if="uploading">
                <div class="flex w-full max-w-xs flex-col items-center gap-2" aria-live="polite">
                    <span class="text-sm font-semibold text-slate-600 dark:text-slate-300" x-text="`Uploading ${progress}%`"></span>
                    <div class="h-1.5 w-full overflow-hidden rounded-full bg-slate-200 dark:bg-white/10">
                        <div class="h-full rounded-full bg-accent transition-all" x-bind:style="`width: ${progress}%`"></div>
                    </div>
                </div>
            </template>
        </button>
    </div>

    <flux:error name="imagesUpload" />
    @foreach ($errors->get('imagesUpload.*') as $messages)
        @foreach ($messages as $message)
            <flux:text size="sm" class="text-red-600 dark:text-red-400">{{ $message }}</flux:text>
        @endforeach
    @endforeach

    {{-- Staged files --}}
    @if (filled($imagesUpload))
        <ul class="space-y-2">
            @foreach ($imagesUpload as $index => $staged)
                <li wire:key="staged-{{ $index }}-{{ $staged->getFilename() }}" class="flex items-center gap-3">
                    <div class="size-11 shrink-0 overflow-hidden rounded-lg border border-slate-200 bg-slate-50 dark:border-slate-700 dark:bg-slate-800">
                        @if ($staged->isPreviewable())
                            <img src="{{ $staged->temporaryUrl() }}" alt="" class="size-full object-cover" />
                        @endif
                    </div>

                    <div class="min-w-0 flex-1">
                        <p class="truncate text-sm font-medium text-slate-950 dark:text-white">
                            {{ $staged->getClientOriginalName() }}
                        </p>
                        <p class="text-xs text-slate-500 dark:text-slate-400">
                            {{ kFileSize($staged->getSize()) }}
                        </p>
                    </div>

                    <flux:dropdown position="bottom" align="end">
                        <flux:button icon="ellipsis-vertical" variant="ghost" size="sm" title="File options" />

                        <flux:menu>
                            <flux:menu.item icon="trash" variant="danger" wire:click="removeStaged({{ $index }})">
                                Remove
                            </flux:menu.item>
                        </flux:menu>
                    </flux:dropdown>
                </li>
            @endforeach
        </ul>

        <div class="flex flex-col gap-2 sm:flex-row-reverse">
            <flux:button
                variant="primary"
                class="w-full sm:w-auto"
                wire:click="uploadImages"
                wire:loading.attr="disabled"
            >
                {{ $action }}
            </flux:button>

            <flux:button variant="ghost" class="w-full sm:w-auto" wire:click="clearStaged">
                Clear
            </flux:button>
        </div>
    @endif
</div>
