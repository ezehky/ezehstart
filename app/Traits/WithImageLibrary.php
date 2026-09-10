<?php

namespace App\Traits;

use App\Enums\MediaVisibilityEnum;
use App\Enums\UserTypeEnum;
use App\Models\Image;
use App\Models\ImageFolder;
use App\Models\User;
use App\Services\ImageLibraryService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\WithPagination;

/**
 * Everything a screen needs to browse and tidy the image library.
 *
 * Two screens want all of it — the full-page library and the picker that opens
 * over whatever somebody is writing — and they must not drift apart again. The
 * behaviour lives here; each screen supplies only its own chrome, and the shared
 * markup lives in the `lv._library` partial they both include.
 *
 * Requires WithFormResponseMessage on the using component.
 */
trait WithImageLibrary
{
    use WithPagination;

    public User $user;

    #[Url]
    public string $search = '';

    #[Url]
    public ?int $folder = null;

    #[Url]
    public string $sort = 'newest';

    /**
     * Chosen images, by id. The picker hands these back to its caller; the page
     * uses them as the target of move, edit and delete.
     *
     * @var array<int, int>
     */
    public array $selected = [];

    /**
     * How many images may be chosen at once. A page manages in bulk by default;
     * a picker is told what its caller wants.
     */
    public bool $multiple = true;

    /** A ceiling on a multiple selection. Null means as many as they like. */
    public ?int $max = null;

    /**
     * Which slot on the calling screen asked, echoed back with the answer.
     *
     * The picker announces its choice to everything listening, so this is what
     * lets one screen hold a cover and a gallery without each pick landing in
     * both. Null means nobody named a slot — the tiptap editor's route, which
     * wants a URL rather than a place to put one.
     */
    public ?string $slot = null;

    /** 'library' or 'upload'. */
    public string $tab = 'library';

    /** Which inline panel is open: null, 'edit', 'move', 'delete' or 'folder'. */
    public ?string $panel = null;

    // Editing one image
    public ?int $edit_id = null;

    public string $title = '';

    public ?string $alt_text = null;

    public ?int $image_folder_id = null;

    public string $visibility = 'private';

    public ?string $visible_to_type = null;

    // Moving a batch
    public ?int $move_folder_id = null;

    // The folder form
    public ?int $folder_id = null;

    public string $folder_name = '';

    public ?int $folder_parent_id = null;

    public bool $folder_shared = false;

    public string $folder_visibility = 'private';

    public ?string $folder_visible_to_type = null;

    /**
     * Livewire calls boot{TraitName}() on every request, hydration included, so
     * neither screen has to remember to set the account in its own mount().
     */
    public function bootWithImageLibrary(): void
    {
        $this->user = auth()->user();
    }

    // ||||||||||||||||||||||||||||||||||||||||||||||||
    // READS

    /**
     * @return LengthAwarePaginator<int, Image>
     */
    #[Computed]
    public function images(): LengthAwarePaginator
    {
        return app(ImageLibraryService::class)
            ->libraryQuery($this->user, $this->currentFolder, $this->search ?: null, $this->sort)
            ->paginate($this->perPage());
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
     * The images behind the current selection, filtered to the ones this account
     * may actually change. Edit, move and delete all read this, so it is the one
     * place ownership is decided.
     *
     * @return Collection<int, Image>
     */
    #[Computed]
    public function manageableSelection(): Collection
    {
        if (blank($this->selected)) {
            return collect();
        }

        return Image::query()
            ->whereKey($this->selected)
            ->get()
            ->filter(fn (Image $image) => $this->canManage($image))
            ->values();
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
    public function maxSize(): int
    {
        return app(ImageLibraryService::class)->maxImageSize();
    }

    #[Computed]
    public function remaining(): ?int
    {
        return app(ImageLibraryService::class)->remainingUploadsFor($this->user);
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
            ? MediaVisibilityEnum::forSelect()
            : MediaVisibilityEnum::forSelect([MediaVisibilityEnum::TYPE->value]);
    }

    /**
     * A full page shows more than a dialog does. Override it on the screen rather
     * than branching in here.
     */
    protected function perPage(): int
    {
        return 24;
    }

    // ||||||||||||||||||||||||||||||||||||||||||||||||
    // BROWSING

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
        $this->panel = null;

        $this->resetPage();

        unset($this->images, $this->currentFolder);
    }

    public function switchTab(string $tab): void
    {
        $this->tab = \in_array($tab, ['library', 'upload'], true) ? $tab : 'library';

        $this->panel = null;
    }

    // ||||||||||||||||||||||||||||||||||||||||||||||||
    // SELECTING

    public function toggle(int $imageId): void
    {
        $image = Image::query()->whereKey($imageId)->first();

        abort_unless($image && $image->isVisibleTo($this->user), 404);

        // A single-pick caller gets its answer on the click; there is nothing to
        // confirm when only one image can win.
        if (! $this->multiple) {
            $this->selected = [$imageId];

            $this->pickedSingle();

            return;
        }

        if (\in_array($imageId, $this->selected, true)) {
            $this->selected = array_values(array_diff($this->selected, [$imageId]));

            return;
        }

        $this->respondError(
            "You can choose up to {$this->max} image(s).",
            $this->max !== null && \count($this->selected) >= $this->max,
        );

        $this->selected[] = $imageId;
    }

    public function clearSelection(): void
    {
        $this->selected = [];
        $this->panel = null;
    }

    /**
     * What a screen does when one image is picked and the picking is over. The
     * page has nowhere to send it, so by default this is nothing.
     */
    protected function pickedSingle(): void
    {
        //
    }

    // ||||||||||||||||||||||||||||||||||||||||||||||||
    // PANELS

    public function openPanel(string $panel): void
    {
        $this->resetValidation();

        if ($panel === 'edit') {
            $this->respondError('Choose one image to edit.', $this->manageableSelection->count() !== 1);

            $image = $this->manageableSelection->first();

            $this->edit_id = $image->id;
            $this->title = $image->title;
            $this->alt_text = $image->alt_text;
            $this->image_folder_id = $image->image_folder_id;
            $this->visibility = $image->visibility->value;
            $this->visible_to_type = $image->visible_to_type?->value;
        }

        if ($panel === 'move') {
            $this->respondError('Choose an image to move.', $this->manageableSelection->isEmpty());

            $this->move_folder_id = $this->folder;
        }

        if ($panel === 'delete') {
            $this->respondError('Choose an image to delete.', $this->manageableSelection->isEmpty());
        }

        $this->panel = $panel;
    }

    public function closePanel(): void
    {
        $this->panel = null;

        $this->reset(
            'edit_id', 'title', 'alt_text', 'image_folder_id', 'visibility', 'visible_to_type',
            'move_folder_id', 'folder_id', 'folder_name', 'folder_parent_id', 'folder_shared',
            'folder_visibility', 'folder_visible_to_type',
        );

        $this->resetValidation();
    }

    // ||||||||||||||||||||||||||||||||||||||||||||||||
    // IMAGE WRITES

    /**
     * The title changes; the stored path never does. Renaming the file would
     * break every page, post and email already pointing at the old URL, which is
     * exactly why the two are separate columns.
     */
    public function saveImage(): bool
    {
        $image = Image::query()->whereKey($this->edit_id)->first();

        abort_unless((bool) $image, 404);
        abort_unless($this->canManage($image), 403);

        $this->validate([
            'title' => ['required', 'string', 'max:255'],
            'alt_text' => ['nullable', 'string', 'max:500'],
            'image_folder_id' => ['nullable', 'integer', Rule::exists('image_folders', 'id')],
            'visibility' => ['required', Rule::enum(MediaVisibilityEnum::class)],
            'visible_to_type' => ['nullable', Rule::enum(UserTypeEnum::class)],
        ]);

        $visibility = MediaVisibilityEnum::from($this->visibility);

        $this->respondError(
            'Choose which role should be able to see this image.',
            $visibility->needsType() && ! $this->visible_to_type,
            field: 'visible_to_type'
        );

        app(ImageLibraryService::class)->update(
            $image,
            $this->title,
            $this->image_folder_id ? ImageFolder::query()->find($this->image_folder_id) : null,
            $visibility,
            $this->visible_to_type ? UserTypeEnum::from($this->visible_to_type) : null,
            $this->alt_text,
        );

        $this->closePanel();
        unset($this->images);

        return $this->respondSuccess('The image has been updated. Its URL has not changed.');
    }

    public function moveSelected(): bool
    {
        $this->validate([
            'move_folder_id' => ['nullable', 'integer', Rule::exists('image_folders', 'id')],
        ]);

        $images = $this->manageableSelection;

        $this->respondError('Choose an image to move.', $images->isEmpty());

        $folder = $this->move_folder_id
            ? ImageFolder::query()->browsableBy($this->user)->whereKey($this->move_folder_id)->first()
            : null;

        // A folder they cannot browse is a folder they cannot file into, or the
        // image would vanish from their own library the moment it moved.
        $this->respondError('That folder is not available to you.', $this->move_folder_id && ! $folder);

        $moved = app(ImageLibraryService::class)->moveImages($images, $folder);

        $this->closePanel();
        unset($this->images);

        return $this->respondSuccess("{$moved} image(s) moved to ".($folder?->name ?? 'the library root').'.');
    }

    /**
     * An attached image is refused rather than deleted. The database would refuse
     * it anyway; failing here gives somebody a sentence instead of a foreign key
     * error, and a batch reports what it could not take.
     */
    public function deleteSelected(): bool
    {
        $images = $this->manageableSelection;

        $this->respondError('Choose an image to delete.', $images->isEmpty());

        $service = app(ImageLibraryService::class);
        $deleted = 0;
        $refused = [];

        foreach ($images as $image) {
            $error = $service->delete($image);

            $error === null ? $deleted++ : $refused[] = $image->title;
        }

        $this->selected = [];
        $this->closePanel();
        unset($this->images, $this->remaining);

        $this->respondError(
            'Still in use somewhere: '.implode(', ', $refused).'. Remove it from there first.',
            $deleted === 0 && filled($refused),
        );

        if (filled($refused)) {
            return $this->respondSuccess("{$deleted} image(s) deleted. Still in use: ".implode(', ', $refused).'.');
        }

        return $this->respondSuccess("{$deleted} image(s) deleted.");
    }

    // ||||||||||||||||||||||||||||||||||||||||||||||||
    // FOLDER WRITES

    public function newFolder(): void
    {
        $this->resetValidation();

        $this->reset('folder_id', 'folder_name', 'folder_shared', 'folder_visible_to_type');

        $this->folder_parent_id = $this->folder;
        $this->folder_visibility = MediaVisibilityEnum::PRIVATE->value;

        $this->panel = 'folder';
    }

    public function editFolder(int $folderId): void
    {
        $folder = ImageFolder::query()->browsableBy($this->user)->whereKey($folderId)->first();

        abort_unless((bool) $folder, 404);
        abort_unless($this->canManageFolder($folder), 403);

        $this->resetValidation();

        $this->folder_id = $folder->id;
        $this->folder_name = $folder->name;
        $this->folder_parent_id = $folder->parent_id;
        $this->folder_shared = $folder->isShared();
        $this->folder_visibility = $folder->visibility->value;
        $this->folder_visible_to_type = $folder->visible_to_type?->value;

        $this->panel = 'folder';
    }

    public function saveFolder(): bool
    {
        $this->validate([
            'folder_name' => ['required', 'string', 'max:255'],
            'folder_parent_id' => ['nullable', 'integer', Rule::exists('image_folders', 'id')],
            'folder_visibility' => ['required', Rule::enum(MediaVisibilityEnum::class)],
            'folder_visible_to_type' => ['nullable', Rule::enum(UserTypeEnum::class)],
        ]);

        $visibility = MediaVisibilityEnum::from($this->folder_visibility);

        $this->respondError(
            'Choose which role should be able to browse this folder.',
            $visibility->needsType() && ! $this->folder_visible_to_type,
            field: 'folder_visible_to_type'
        );

        $role = $this->folder_visible_to_type ? UserTypeEnum::from($this->folder_visible_to_type) : null;
        $service = app(ImageLibraryService::class);

        if ($this->folder_id) {
            $folder = ImageFolder::query()->whereKey($this->folder_id)->first();

            abort_unless((bool) $folder, 404);
            abort_unless($this->canManageFolder($folder), 403);

            $service->updateFolder($folder, $this->folder_name, $visibility, $role);
        } else {
            $service->createFolder(
                $this->user,
                $this->folder_name,
                $this->folder_parent_id ? ImageFolder::query()->find($this->folder_parent_id) : null,
                $this->folder_shared,
                $visibility,
                $role,
            );
        }

        $this->closePanel();
        unset($this->folders, $this->images);

        return $this->respondSuccess('The folder has been saved.');
    }

    public function deleteFolder(int $folderId): bool
    {
        $folder = ImageFolder::query()->whereKey($folderId)->first();

        abort_unless((bool) $folder, 404);
        abort_unless($this->canManageFolder($folder), 403);

        app(ImageLibraryService::class)->deleteFolder($folder);

        if ($this->folder === $folderId) {
            $this->folder = null;
        }

        $this->closePanel();
        unset($this->folders, $this->images, $this->currentFolder);

        return $this->respondSuccess('The folder has been deleted. Its images moved to the root.');
    }

    // ||||||||||||||||||||||||||||||||||||||||||||||||
    // UPLOAD

    /**
     * The uploader announces what it stored. Landing back on the grid with the
     * new images already ticked is the whole point of uploading from in here.
     *
     * @param  array<int, int>  $ids
     */
    public function afterUpload(array $ids): void
    {
        if (blank($ids)) {
            return;
        }

        $this->selected = $this->multiple
            ? array_values(array_unique([...$this->selected, ...$ids]))
            : [(int) $ids[0]];

        $this->tab = 'library';

        $this->resetPage();

        unset($this->images, $this->remaining);
    }

    // ||||||||||||||||||||||||||||||||||||||||||||||||
    // GUARDS

    /**
     * Seeing an image is not the same as being allowed to change it. A public
     * image is visible to everybody and editable only by its owner.
     */
    protected function canManage(Image $image): bool
    {
        return $this->user->isAdmin() || $image->user_id === $this->user->id;
    }

    protected function canManageFolder(ImageFolder $folder): bool
    {
        return $this->user->isAdmin() || $folder->user_id === $this->user->id;
    }
}
