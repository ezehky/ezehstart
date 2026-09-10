<?php

use App\Models\Image;
use App\Models\ImageFolder;
use App\Models\User;
use App\Rules\ImageRule;
use App\Services\ImageLibraryService;
use App\Traits\WithFormResponseMessage;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * The library picker.
 *
 * Dropped once onto any screen that needs to choose an image. It answers two
 * kinds of caller:
 *
 *  - the tiptap editor, which listens for the `image-picked` browser event and
 *    only wants a URL back
 *  - a Livewire parent, which listens for the `imageSelected` component event
 *    and wants the image's id so it can record the usage
 *
 * It never decides what the image is *for* — that is the caller's business.
 */
new class extends Component
{
    use WithFileUploads, WithFormResponseMessage;

    public User $user;

    public bool $show = false;

    public string $search = '';

    public ?int $folder = null;

    /** @var array<int, mixed> */
    public array $imagesUpload = [];

    public function mount(): void
    {
        $this->user = auth()->user();
    }

    #[Computed]
    public function images(): Collection
    {
        return app(ImageLibraryService::class)
            ->libraryQuery(
                $this->user,
                $this->folder ? ImageFolder::query()->find($this->folder) : null,
                $this->search ?: null,
            )
            ->limit(40)
            ->get();
    }

    /**
     * @return Collection<int, array{id: int, label: string}>
     */
    #[Computed]
    public function folders(): Collection
    {
        return app(ImageLibraryService::class)->folderOptions($this->user);
    }

    #[Computed]
    public function maxSize(): int
    {
        return app(ImageLibraryService::class)->maxImageSize();
    }

    public function open(): void
    {
        $this->show = true;

        unset($this->images);
    }

    public function close(): void
    {
        $this->show = false;
        $this->reset('search', 'folder', 'imagesUpload');
    }

    protected function rules(): array
    {
        return [
            'imagesUpload' => ['required', 'array', 'min:1'],
            'imagesUpload.*' => [new ImageRule(size: $this->maxSize)],
        ];
    }

    /**
     * Upload straight from the picker, so somebody writing a post does not have
     * to leave it, go to the library, upload, and come back.
     *
     * Named uploadImages() and not upload(): Livewire aliases `upload` on the
     * $wire object to its own $upload() helper, so `wire:submit="upload"` calls
     * that helper with no arguments and dies in the browser before a request is
     * ever sent. Any component method sharing a name with a $wire alias is
     * unreachable from Blade.
     */
    public function uploadImages(): bool
    {
        $this->validate();

        $result = app(ImageLibraryService::class)->storeMany(
            $this->user,
            $this->imagesUpload,
            $this->folder ? ImageFolder::query()->find($this->folder) : null,
        );

        $this->reset('imagesUpload');
        unset($this->images);

        $this->respondError(
            'Your upload limit is full, so nothing was saved.',
            $result['stored']->isEmpty(),
        );

        return $this->respondSuccess("{$result['stored']->count()} image(s) uploaded.");
    }

    public function select(Image $image): void
    {
        abort_unless($image->isVisibleTo($this->user), 404);

        // Two audiences, two events. The browser event feeds the tiptap editor;
        // the Livewire event feeds a parent that needs the id to record a usage.
        $this->dispatch('image-picked', url: $image->url(), alt: $image->alt_text ?: $image->title);

        $this->dispatch('imageSelected', imageId: $image->id, url: $image->url());

        $this->close();
    }
};
?>

<div x-on:open-image-picker.window="$wire.open()">
    <flux:modal wire:model="show" name="imagePicker" class="max-w-3xl">
        <div class="space-y-4">
            <flux:heading size="lg">Choose an image</flux:heading>

            <div class="flex flex-wrap gap-3">
                <flux:input
                    wire:model.live.debounce.400ms="search"
                    placeholder="Search by name"
                    icon="magnifying-glass"
                    class="grow"
                />
                <flux:select wire:model.live="folder" class="max-w-48">
                    <flux:select.option value="">All folders</flux:select.option>
                    @foreach ($this->folders as $option)
                        <flux:select.option value="{{ $option['id'] }}">{{ $option['label'] }}</flux:select.option>
                    @endforeach
                </flux:select>
            </div>

            <form wire:submit="uploadImages" class="flex flex-wrap items-start gap-3">
                <div class="grow">
                    <flux:input type="file" wire:model="imagesUpload" multiple accept="image/*" />
                    <flux:error name="imagesUpload" />
                </div>
                <flux:button type="submit" size="sm" icon="arrow-up-tray" wire:loading.attr="disabled">
                    Upload
                </flux:button>
            </form>

            @if ($this->images->isEmpty())
                <x-dashboard.workspace-no-record
                    icon="photo"
                    label="No images"
                    text="Upload one above and it will appear here."
                />
            @else
                <div class="grid max-h-96 grid-cols-3 gap-3 overflow-y-auto sm:grid-cols-4">
                    @foreach ($this->images as $image)
                        <button
                            type="button"
                            wire:key="pick-{{ $image->id }}"
                            wire:click="select({{ $image->id }})"
                            class="group overflow-hidden rounded-lg border border-slate-200 focus:ring-2 focus:ring-lime-500 focus:outline-none dark:border-slate-700"
                        >
                            <img
                                src="{{ $image->url() }}"
                                alt="{{ $image->alt_text ?: $image->title }}"
                                class="aspect-square w-full object-cover transition group-hover:scale-105"
                                loading="lazy"
                            />
                            <span class="block truncate px-2 py-1 text-left text-xs text-slate-600 dark:text-slate-300">
                                {{ $image->title }}
                            </span>
                        </button>
                    @endforeach
                </div>
            @endif
        </div>
    </flux:modal>
</div>
