<?php

use App\Enums\ImageVisibilityEnum;
use App\Enums\UserRoleEnum;
use App\Models\Image;
use App\Models\ImageFolder;
use App\Models\User;
use App\Rules\ImageRule;
use App\Services\ImageLibraryService;
use App\Traits\WithFormResponseMessage;
use Flux\Flux;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithFileUploads;
use Livewire\WithPagination;

new class extends Component
{
    use WithFileUploads, WithFormResponseMessage, WithPagination;

    public User $user;

    /**
     * Several at once — picking twenty images one file at a time is the single
     * most tedious thing a media library can ask of anybody.
     *
     * @var array<int, mixed>
     */
    public array $imagesUpload = [];

    #[Url]
    public string $search = '';

    #[Url]
    public ?int $folder = null;

    #[Url]
    public string $sort = 'newest';

    // Editing one image
    public ?Image $editing = null;

    public string $title = '';

    public ?string $alt_text = null;

    public ?int $image_folder_id = null;

    public string $visibility = 'private';

    public ?string $visible_to_role = null;

    // Folder form
    public string $folder_name = '';

    public ?int $folder_parent_id = null;

    public bool $folder_shared = false;

    /** The image queued for deletion, held while the dialog asks. */
    public ?int $deleteId = null;

    /** The folder queued for deletion, held while its own dialog asks. */
    public ?int $deleteFolderId = null;

    public function mount(): void
    {
        $this->user = auth()->user();

        kSetSiteTitle('image library');
    }

    // ||||||||||||||||||||||||||||||||||||||||||||||||
    // READS

    #[Computed]
    public function images()
    {
        return app(ImageLibraryService::class)
            ->libraryQuery($this->user, $this->currentFolder, $this->search ?: null, $this->sort)
            ->paginate(24);
    }

    #[Computed]
    public function currentFolder(): ?ImageFolder
    {
        return $this->folder
            ? ImageFolder::query()->browsableBy($this->user)->whereKey($this->folder)->first()
            : null;
    }

    /**
     * @return Collection<int, array{id: int, label: string}>
     */
    #[Computed]
    public function folders(): Collection
    {
        return app(ImageLibraryService::class)->folderOptions($this->user);
    }

    /**
     * @return array<string, string>
     */
    #[Computed]
    public function sortOptions(): array
    {
        return app(ImageLibraryService::class)->sortOptions();
    }

    #[Computed]
    public function remaining(): ?int
    {
        return app(ImageLibraryService::class)->remainingUploadsFor($this->user);
    }

    #[Computed]
    public function maxSize(): int
    {
        return app(ImageLibraryService::class)->maxImageSize();
    }

    /**
     * Role visibility is only meaningful to somebody who can see more than their
     * own role, so members get the two simple options and administrators get all
     * three.
     *
     * @return array<string, string>
     */
    #[Computed]
    public function visibilityOptions(): array
    {
        return $this->user->isAdmin()
            ? ImageVisibilityEnum::forSelect()
            : ImageVisibilityEnum::forSelect([ImageVisibilityEnum::ROLE->value]);
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedSort(): void
    {
        $this->resetPage();
    }

    public function selectFolder(?int $folderId): void
    {
        $this->folder = $folderId;
        $this->resetPage();
    }

    // ||||||||||||||||||||||||||||||||||||||||||||||||
    // UPLOAD

    protected function rules(): array
    {
        return [
            'imagesUpload' => ['required', 'array', 'min:1'],
            'imagesUpload.*' => [new ImageRule(size: $this->maxSize)],
        ];
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

        $result = app(ImageLibraryService::class)->storeMany(
            $this->user,
            $this->imagesUpload,
            $this->currentFolder,
        );

        $this->reset('imagesUpload');
        unset($this->images, $this->remaining);

        // A partial upload is reported as a partial upload. Saying "saved" when
        // four of ten went through is how people lose work without noticing.
        $this->respondError(
            "Your upload limit is full — {$result['skipped']} file(s) were not saved.",
            $result['skipped'] > 0 && $result['stored']->isEmpty(),
        );

        if ($result['skipped'] > 0) {
            return $this->respondSuccess(
                "{$result['stored']->count()} image(s) uploaded. {$result['skipped']} skipped — your upload limit is full."
            );
        }

        return $this->respondSuccess("{$result['stored']->count()} image(s) uploaded.");
    }

    // ||||||||||||||||||||||||||||||||||||||||||||||||
    // EDIT

    public function edit(Image $image): void
    {
        // The picker's query is the authority on what this account may see, so an
        // id typed into a request cannot reach past it.
        abort_unless($image->isVisibleTo($this->user), 404);

        $this->editing = $image;
        $this->title = $image->title;
        $this->alt_text = $image->alt_text;
        $this->image_folder_id = $image->image_folder_id;
        $this->visibility = $image->visibility->value;
        $this->visible_to_role = $image->visible_to_role?->value;

        $this->resetValidation();

        Flux::modal('imageModal')->show();
    }

    public function save(): bool
    {
        abort_unless((bool) $this->editing, 404);
        abort_unless($this->canManage($this->editing), 403);

        $this->validate([
            'title' => ['required', 'string', 'max:255'],
            'alt_text' => ['nullable', 'string', 'max:500'],
            'image_folder_id' => ['nullable', 'integer', Rule::exists('image_folders', 'id')],
            'visibility' => ['required', Rule::enum(ImageVisibilityEnum::class)],
            'visible_to_role' => ['nullable', Rule::enum(UserRoleEnum::class)],
        ]);

        $visibility = ImageVisibilityEnum::from($this->visibility);

        $this->respondError(
            'Choose which role should be able to see this image.',
            $visibility->needsRole() && ! $this->visible_to_role,
            field: 'visible_to_role'
        );

        app(ImageLibraryService::class)->update(
            $this->editing,
            $this->title,
            $this->image_folder_id ? ImageFolder::query()->find($this->image_folder_id) : null,
            $visibility,
            $this->visible_to_role ? UserRoleEnum::from($this->visible_to_role) : null,
            $this->alt_text,
        );

        Flux::modal('imageModal')->close();
        $this->reset('editing', 'title', 'alt_text', 'image_folder_id', 'visibility', 'visible_to_role');
        unset($this->images);

        return $this->respondSuccess('The image has been updated.');
    }

    // ||||||||||||||||||||||||||||||||||||||||||||||||
    // DELETE

    public function confirmDelete(int $imageId): void
    {
        $this->deleteId = $imageId;

        Flux::modal('deleteImageModal')->show();
    }

    public function delete(): bool
    {
        $image = Image::query()->whereKey($this->deleteId)->first();

        abort_unless((bool) $image, 404);
        abort_unless($this->canManage($image), 403);

        $error = app(ImageLibraryService::class)->delete($image);

        Flux::modal('deleteImageModal')->close();
        $this->reset('deleteId');
        unset($this->images, $this->remaining);

        $this->respondError($error, $error !== null);

        return $this->respondSuccess('The image has been deleted.');
    }

    // ||||||||||||||||||||||||||||||||||||||||||||||||
    // FOLDERS

    public function createFolder(): bool
    {
        $this->validate([
            'folder_name' => ['required', 'string', 'max:255'],
            'folder_parent_id' => ['nullable', 'integer', Rule::exists('image_folders', 'id')],
        ]);

        app(ImageLibraryService::class)->createFolder(
            $this->user,
            $this->folder_name,
            $this->folder_parent_id ? ImageFolder::query()->find($this->folder_parent_id) : null,
            $this->folder_shared,
        );

        Flux::modal('folderModal')->close();
        $this->reset('folder_name', 'folder_parent_id', 'folder_shared');
        unset($this->folders);

        return $this->respondSuccess('The folder has been created.');
    }

    public function confirmDeleteFolder(int $folderId): void
    {
        $this->deleteFolderId = $folderId;

        Flux::modal('deleteFolderModal')->show();
    }

    public function deleteFolder(): bool
    {
        $folder = ImageFolder::query()->whereKey($this->deleteFolderId)->first();

        abort_unless((bool) $folder, 404);
        abort_unless($this->user->isAdmin() || $folder->user_id === $this->user->id, 403);

        app(ImageLibraryService::class)->deleteFolder($folder);

        if ($this->folder === $this->deleteFolderId) {
            $this->folder = null;
        }

        Flux::modal('deleteFolderModal')->close();
        $this->reset('deleteFolderId');
        unset($this->folders, $this->images);

        return $this->respondSuccess('The folder has been deleted. Its images moved to the root.');
    }

    /**
     * Seeing an image is not the same as being allowed to change it. A public
     * image is visible to everybody and editable only by its owner.
     */
    private function canManage(Image $image): bool
    {
        return $this->user->isAdmin() || $image->user_id === $this->user->id;
    }
};
?>

<div class="space-y-6">
    <div>
        <flux:heading level="1" size="xl">Image library</flux:heading>
        <flux:text class="mt-1">Upload once, reuse anywhere.</flux:text>
    </div>

    <div class="grid gap-6 lg:grid-cols-[15rem_1fr] lg:items-start">
        {{-- Folders --}}
        <flux:card class="space-y-3">
            <flux:subheading class="font-semibold">Folders</flux:subheading>

            <flux:navlist>
                <flux:navlist.item
                    icon="folder"
                    :current="$folder === null"
                    wire:click="selectFolder(null)"
                >
                    All images
                </flux:navlist.item>

                @foreach ($this->folders as $option)
                    {{-- The row is the button; the bin rides on top of it rather than
                         beside it, so a long folder name is not squeezed by an
                         affordance nobody is reaching for most of the time. --}}
                    <div wire:key="folder-{{ $option['id'] }}" class="group/folder relative">
                        <flux:navlist.item
                            icon="folder"
                            :current="$folder === $option['id']"
                            wire:click="selectFolder({{ $option['id'] }})"
                            class="pe-9!"
                        >
                            {{ $option['label'] }}
                        </flux:navlist.item>

                        <flux:button
                            size="xs"
                            variant="subtle"
                            icon="trash"
                            title="Delete folder"
                            class="absolute end-1 top-1/2 -translate-y-1/2 opacity-0 transition group-hover/folder:opacity-100 focus-visible:opacity-100"
                            wire:click="confirmDeleteFolder({{ $option['id'] }})"
                        />
                    </div>
                @endforeach

                <flux:navlist.item icon="plus" x-on:click="$flux.modal('folderModal').show()">
                    New folder
                </flux:navlist.item>
            </flux:navlist>
        </flux:card>

        {{-- Library --}}
        <flux:card class="space-y-5">
            <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <flux:heading level="2" size="lg">
                    {{ $this->currentFolder?->name ?? 'All images' }}
                </flux:heading>

                <div class="flex items-center gap-2">
                    <flux:input
                        wire:model.live.debounce.400ms="search"
                        placeholder="Search"
                        icon="magnifying-glass"
                        size="sm"
                        class="sm:max-w-64"
                    />

                    <flux:dropdown position="bottom" align="end">
                        <flux:button size="sm" variant="filled" icon="bars-arrow-up" title="Sort images" />

                        <flux:menu>
                            @foreach ($this->sortOptions as $value => $label)
                                <flux:menu.item
                                    :icon="$sort === $value ? 'check' : null"
                                    wire:click="$set('sort', '{{ $value }}')"
                                >
                                    {{ $label }}
                                </flux:menu.item>
                            @endforeach
                        </flux:menu>
                    </flux:dropdown>
                </div>
            </div>

            <div class="grid grid-cols-2 gap-4 sm:grid-cols-3 xl:grid-cols-4">
                {{-- Drop zone. It sits in the grid rather than above it: the place you
                     add an image is the place the images are, and it keeps its square
                     whether the library holds nothing or four hundred. --}}
                <div
                    x-data="{ dragging: false, uploading: false, progress: 0 }"
                    x-on:livewire-upload-start="uploading = true; progress = 0"
                    x-on:livewire-upload-progress="progress = $event.detail.progress"
                    x-on:livewire-upload-error="uploading = false"
                    x-on:livewire-upload-cancel="uploading = false"
                    {{-- Livewire has finished putting the files on the property by the
                         time this fires, so the save needs no second click. --}}
                    x-on:livewire-upload-finish="uploading = false; $wire.uploadImages()"
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
                            ? 'border-accent bg-accent/10 dark:border-accent dark:bg-accent/10'
                            : 'border-slate-300 bg-slate-50 hover:border-slate-400 hover:bg-slate-100 dark:border-white/15 dark:bg-white/5 dark:hover:border-white/30 dark:hover:bg-white/10'"
                        class="flex aspect-square w-full flex-col items-center justify-center gap-2 rounded-lg border border-dashed px-3 text-center transition focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2 disabled:cursor-wait dark:focus-visible:ring-white dark:focus-visible:ring-offset-slate-950"
                    >
                        <template x-if="! uploading">
                            <div class="flex flex-col items-center gap-2">
                                <flux:icon.cloud-arrow-up class="size-7 text-slate-400 dark:text-slate-500" />
                                <span class="text-xs font-medium text-slate-500 dark:text-slate-400">
                                    Drop photos here, or click to select files
                                </span>
                            </div>
                        </template>

                        <template x-if="uploading">
                            <div class="flex w-full flex-col items-center gap-2" aria-live="polite">
                                <span class="text-xs font-semibold text-slate-600 dark:text-slate-300" x-text="`Uploading ${progress}%`"></span>
                                <div class="h-1.5 w-3/4 overflow-hidden rounded-full bg-slate-200 dark:bg-white/10">
                                    <div class="h-full rounded-full bg-accent transition-all" x-bind:style="`width: ${progress}%`"></div>
                                </div>
                            </div>
                        </template>
                    </button>
                </div>

                @foreach ($this->images as $image)
                    <div wire:key="image-{{ $image->id }}" class="group space-y-2">
                        <div class="relative aspect-square overflow-hidden rounded-lg border border-slate-200 bg-slate-50 ring-2 ring-transparent transition group-hover:ring-accent dark:border-slate-800 dark:bg-slate-900">
                            <img
                                src="{{ $image->url() }}"
                                alt="{{ $image->alt_text ?: $image->title }}"
                                class="h-full w-full object-cover"
                                loading="lazy"
                            />
                            <div class="absolute inset-x-0 bottom-0 flex justify-end gap-1 bg-linear-to-t from-black/60 to-transparent p-2 opacity-0 transition group-hover:opacity-100">
                                <flux:button size="xs" icon="pencil-square" title="Edit details" wire:click="edit({{ $image->id }})" />
                                <flux:button size="xs" variant="danger" icon="trash" title="Delete image" wire:click="confirmDelete({{ $image->id }})" />
                            </div>
                        </div>
                        <div>
                            <p class="truncate text-sm font-medium text-slate-950 dark:text-white" title="{{ $image->title }}">
                                {{ $image->title }}
                            </p>
                            <p class="text-xs text-slate-500 dark:text-slate-400">
                                {{ $image->readableSize() }}
                                @if ($image->dimensions())
                                    &middot; {{ $image->dimensions() }}
                                @endif
                            </p>
                            <flux:badge size="sm" :color="$image->visibility->color()" inset="top bottom">
                                {{ $image->visibility->label() }}
                            </flux:badge>
                        </div>
                    </div>
                @endforeach
            </div>

            {{-- The drop zone is the empty state, so this only has to explain the
                 emptiness a search caused. --}}
            @if ($this->images->isEmpty() && $search !== '')
                <flux:text class="text-center">Nothing here matches that search.</flux:text>
            @endif

            <flux:error name="imagesUpload" />
            @foreach ($errors->get('imagesUpload.*') as $messages)
                @foreach ($messages as $message)
                    <flux:text size="sm" class="text-red-600 dark:text-red-400">{{ $message }}</flux:text>
                @endforeach
            @endforeach

            <div class="space-y-3">
                <flux:text size="sm">
                    Up to {{ $this->maxSize }} KB each.
                    @if ($this->remaining !== null)
                        {{ $this->remaining }} upload(s) left on your account.
                    @endif
                </flux:text>

                @if ($this->images->hasPages())
                    <div>{{ $this->images->links() }}</div>
                @endif
            </div>
        </flux:card>
    </div>

    {{-- Edit --}}
    <flux:modal name="imageModal" class="max-w-lg">
        <form wire:submit="save" class="space-y-4">
            <flux:heading size="lg">Image details</flux:heading>

            <flux:input wire:model="title" label="Title" description="Renaming here does not change the image's URL." />
            <flux:input wire:model="alt_text" label="Alt text" description="Describes the image to screen readers." />

            <flux:select wire:model="image_folder_id" label="Folder">
                <flux:select.option value="">No folder</flux:select.option>
                @foreach ($this->folders as $option)
                    <flux:select.option value="{{ $option['id'] }}">{{ $option['label'] }}</flux:select.option>
                @endforeach
            </flux:select>

            <flux:select wire:model.live="visibility" label="Who can see it">
                @foreach ($this->visibilityOptions as $value => $label)
                    <flux:select.option value="{{ $value }}">{{ $label }}</flux:select.option>
                @endforeach
            </flux:select>

            @if ($visibility === 'role')
                <flux:select wire:model="visible_to_role" label="Visible to role">
                    <flux:select.option value="">Choose a role</flux:select.option>
                    @foreach (App\Enums\UserRoleEnum::forSelect() as $value => $label)
                        <flux:select.option value="{{ $value }}">{{ $label }}</flux:select.option>
                    @endforeach
                </flux:select>
            @endif

            <div class="flex justify-end gap-3">
                <flux:modal.close>
                    <flux:button variant="ghost" type="button">Cancel</flux:button>
                </flux:modal.close>
                <flux:button type="submit" variant="primary">Save</flux:button>
            </div>
        </form>
    </flux:modal>

    {{-- New folder --}}
    <flux:modal name="folderModal" class="max-w-md">
        <form wire:submit="createFolder" class="space-y-4">
            <flux:heading size="lg">New folder</flux:heading>

            <flux:input wire:model="folder_name" label="Name" placeholder="Banners" />

            <flux:select wire:model="folder_parent_id" label="Inside">
                <flux:select.option value="">Top level</flux:select.option>
                @foreach ($this->folders as $option)
                    <flux:select.option value="{{ $option['id'] }}">{{ $option['label'] }}</flux:select.option>
                @endforeach
            </flux:select>

            @if ($user->isAdmin())
                <flux:switch wire:model="folder_shared" label="Shared folder" description="Everybody can browse it." />
            @endif

            <div class="flex justify-end gap-3">
                <flux:modal.close>
                    <flux:button variant="ghost" type="button">Cancel</flux:button>
                </flux:modal.close>
                <flux:button type="submit" variant="primary">Create</flux:button>
            </div>
        </form>
    </flux:modal>

    <x-dashboard.confirm-modal
        name="deleteFolderModal"
        title="Delete this folder?"
        icon="folder-minus"
        confirm="Delete it"
        cancel="Keep it"
        wire:click="deleteFolder"
    >
        The images inside it are not deleted — they move to the root of your library. Any
        folders inside it move up a level.
    </x-dashboard.confirm-modal>

    <x-dashboard.confirm-modal
        name="deleteImageModal"
        title="Delete this image?"
        icon="trash"
        confirm="Delete it"
        cancel="Keep it"
        wire:click="delete"
    >
        The file is removed from storage and cannot be recovered. An image that is still
        being used somewhere cannot be deleted until it is removed from there first.
    </x-dashboard.confirm-modal>
</div>
