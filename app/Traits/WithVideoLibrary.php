<?php

namespace App\Traits;

use App\Enums\MediaVisibilityEnum;
use App\Enums\UserRoleEnum;
use App\Models\User;
use App\Models\Video;
use App\Models\VideoFolder;
use App\Rules\VideoUrlRule;
use App\Services\VideoLibraryService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\WithPagination;

/**
 * Everything a screen needs to browse and tidy the video library.
 *
 * The sibling of WithImageLibrary, and the same bargain: two screens want all of
 * it — the full-page library and the picker that opens over whatever somebody is
 * writing — and they must not drift apart. The behaviour lives here; each screen
 * supplies only its own chrome.
 *
 * The one real difference from the image trait is the second tab. There is no
 * upload: a video is added by pasting a link, which is a form rather than a file
 * queue, and it can fail for a reason a file cannot — a host we do not allow.
 *
 * Requires WithFormResponseMessage on the using component.
 */
trait WithVideoLibrary
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
     * Chosen videos, by id. The picker hands these back to its caller; the page
     * uses them as the target of move, edit and delete.
     *
     * @var array<int, int>
     */
    public array $selected = [];

    /**
     * How many videos may be chosen at once. A page manages in bulk by default;
     * a picker is told what its caller wants.
     */
    public bool $multiple = true;

    /** A ceiling on a multiple selection. Null means as many as they like. */
    public ?int $max = null;

    /**
     * Which slot on the calling screen asked, echoed back with the answer.
     *
     * The picker announces its choice to everything listening, so this is what
     * lets one screen hold a trailer and a playlist without each pick landing in
     * both. Null means nobody named a slot — the tiptap editor's route, which
     * wants a URL rather than a place to put one.
     */
    public ?string $slot = null;

    /** 'library' or 'add'. */
    public string $tab = 'library';

    /** Which inline panel is open: null, 'edit', 'move', 'delete' or 'folder'. */
    public ?string $panel = null;

    // Adding one video
    public string $video_url = '';

    public string $new_title = '';

    // Editing one video
    public ?int $edit_id = null;

    public string $title = '';

    public ?string $description = null;

    /**
     * Seconds. Typed rather than fetched — nothing here calls out to a provider
     * API, so a length nobody entered stays unknown and the tile simply omits it.
     */
    public ?int $duration = null;

    public ?int $video_folder_id = null;

    public string $visibility = 'private';

    public ?string $visible_to_role = null;

    // Moving a batch
    public ?int $move_folder_id = null;

    // The folder form
    public ?int $folder_id = null;

    public string $folder_name = '';

    public ?int $folder_parent_id = null;

    public bool $folder_shared = false;

    public string $folder_visibility = 'private';

    public ?string $folder_visible_to_role = null;

    /**
     * Livewire calls boot{TraitName}() on every request, hydration included, so
     * neither screen has to remember to set the account in its own mount().
     */
    public function bootWithVideoLibrary(): void
    {
        $this->user = auth()->user();
    }

    // ||||||||||||||||||||||||||||||||||||||||||||||||
    // READS

    /**
     * @return LengthAwarePaginator<int, Video>
     */
    #[Computed]
    public function videos(): LengthAwarePaginator
    {
        return app(VideoLibraryService::class)
            ->libraryQuery($this->user, $this->currentFolder, $this->search ?: null, $this->sort)
            ->paginate($this->perPage());
    }

    #[Computed]
    public function currentFolder(): ?VideoFolder
    {
        return $this->folder
            ? VideoFolder::query()->browsableBy($this->user)->whereKey($this->folder)->first()
            : null;
    }

    /**
     * @return Collection<int, array{id: int, label: string}>
     */
    #[Computed]
    public function folders(): Collection
    {
        return app(VideoLibraryService::class)->folderOptions($this->user);
    }

    /**
     * The videos behind the current selection, filtered to the ones this account
     * may actually change. Edit, move and delete all read this, so it is the one
     * place ownership is decided.
     *
     * @return Collection<int, Video>
     */
    #[Computed]
    public function manageableSelection(): Collection
    {
        if (blank($this->selected)) {
            return collect();
        }

        return Video::query()
            ->whereKey($this->selected)
            ->get()
            ->filter(fn (Video $video) => $this->canManage($video))
            ->values();
    }

    /**
     * @return array<string, string>
     */
    #[Computed]
    public function sortOptions(): array
    {
        return app(VideoLibraryService::class)->sortOptions();
    }

    #[Computed]
    public function remaining(): ?int
    {
        return app(VideoLibraryService::class)->remainingFor($this->user);
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
            : MediaVisibilityEnum::forSelect([MediaVisibilityEnum::ROLE->value]);
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

        unset($this->videos, $this->currentFolder);
    }

    public function switchTab(string $tab): void
    {
        $this->tab = \in_array($tab, ['library', 'add'], true) ? $tab : 'library';

        $this->panel = null;
    }

    // ||||||||||||||||||||||||||||||||||||||||||||||||
    // SELECTING

    public function toggle(int $videoId): void
    {
        $video = Video::query()->whereKey($videoId)->first();

        abort_unless($video && $video->isVisibleTo($this->user), 404);

        // A single-pick caller gets its answer on the click; there is nothing to
        // confirm when only one video can win.
        if (! $this->multiple) {
            $this->selected = [$videoId];

            $this->pickedSingle();

            return;
        }

        if (\in_array($videoId, $this->selected, true)) {
            $this->selected = array_values(array_diff($this->selected, [$videoId]));

            return;
        }

        $this->respondError(
            "You can choose up to {$this->max} video(s).",
            $this->max !== null && \count($this->selected) >= $this->max,
        );

        $this->selected[] = $videoId;
    }

    public function clearSelection(): void
    {
        $this->selected = [];
        $this->panel = null;
    }

    /**
     * What a screen does when one video is picked and the picking is over. The
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
            $this->respondError('Choose one video to edit.', $this->manageableSelection->count() !== 1);

            $video = $this->manageableSelection->first();

            $this->edit_id = $video->id;
            $this->title = $video->title;
            $this->description = $video->description;
            $this->duration = $video->duration;
            $this->video_folder_id = $video->video_folder_id;
            $this->visibility = $video->visibility->value;
            $this->visible_to_role = $video->visible_to_role?->value;
        }

        if ($panel === 'move') {
            $this->respondError('Choose a video to move.', $this->manageableSelection->isEmpty());

            $this->move_folder_id = $this->folder;
        }

        if ($panel === 'delete') {
            $this->respondError('Choose a video to delete.', $this->manageableSelection->isEmpty());
        }

        $this->panel = $panel;
    }

    public function closePanel(): void
    {
        $this->panel = null;

        $this->reset(
            'edit_id', 'title', 'description', 'duration', 'video_folder_id', 'visibility', 'visible_to_role',
            'move_folder_id', 'folder_id', 'folder_name', 'folder_parent_id', 'folder_shared',
            'folder_visibility', 'folder_visible_to_role',
        );

        $this->resetValidation();
    }

    // ||||||||||||||||||||||||||||||||||||||||||||||||
    // ADDING

    /**
     * Add one video from a pasted link.
     *
     * The URL is checked by VideoUrlRule so the form can say which hosts are
     * allowed, and checked again inside the service, which is what actually
     * decides — a rule only speaks for requests that came through this form.
     */
    public function addVideo(): bool
    {
        $this->validate([
            'video_url' => ['required', 'string', 'max:2048', new VideoUrlRule],
            'new_title' => ['nullable', 'string', 'max:255'],
        ]);

        $service = app(VideoLibraryService::class);
        $remaining = $service->remainingFor($this->user);

        $this->respondError(
            'You have reached your video limit. Delete one you no longer need first.',
            $remaining !== null && $remaining < 1,
        );

        $video = $service->store(
            $this->user,
            $this->video_url,
            $this->new_title ?: null,
            $this->currentFolder,
            MediaVisibilityEnum::PRIVATE,
        );

        $this->respondError('That video could not be added.', $video === null);

        $this->reset('video_url', 'new_title');

        // Landing back on the grid with the new video already ticked is the whole
        // point of adding from in here.
        $this->selected = $this->multiple
            ? array_values(array_unique([...$this->selected, $video->id]))
            : [$video->id];

        $this->tab = 'library';

        $this->resetPage();

        unset($this->videos, $this->remaining);

        return $this->respondSuccess('The video has been added to your library.');
    }

    // ||||||||||||||||||||||||||||||||||||||||||||||||
    // VIDEO WRITES

    /**
     * The title, folder and visibility change; the provider and the video id never
     * do. Repointing a row at a different video would silently change what every
     * post already embedding it shows.
     */
    public function saveVideo(): bool
    {
        $video = Video::query()->whereKey($this->edit_id)->first();

        abort_unless((bool) $video, 404);
        abort_unless($this->canManage($video), 403);

        $this->validate([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            // Twelve hours. Not a real ceiling on video, just one that keeps a
            // typo out of a column the grid reads.
            'duration' => ['nullable', 'integer', 'min:1', 'max:43200'],
            'video_folder_id' => ['nullable', 'integer', Rule::exists('video_folders', 'id')],
            'visibility' => ['required', Rule::enum(MediaVisibilityEnum::class)],
            'visible_to_role' => ['nullable', Rule::enum(UserRoleEnum::class)],
        ]);

        $visibility = MediaVisibilityEnum::from($this->visibility);

        $this->respondError(
            'Choose which role should be able to see this video.',
            $visibility->needsRole() && ! $this->visible_to_role,
            field: 'visible_to_role'
        );

        app(VideoLibraryService::class)->update(
            $video,
            $this->title,
            $this->video_folder_id ? VideoFolder::query()->find($this->video_folder_id) : null,
            $visibility,
            $this->visible_to_role ? UserRoleEnum::from($this->visible_to_role) : null,
            $this->description,
            $this->duration,
        );

        $this->closePanel();
        unset($this->videos);

        return $this->respondSuccess('The video has been updated.');
    }

    public function moveSelected(): bool
    {
        $this->validate([
            'move_folder_id' => ['nullable', 'integer', Rule::exists('video_folders', 'id')],
        ]);

        $videos = $this->manageableSelection;

        $this->respondError('Choose a video to move.', $videos->isEmpty());

        $folder = $this->move_folder_id
            ? VideoFolder::query()->browsableBy($this->user)->whereKey($this->move_folder_id)->first()
            : null;

        // A folder they cannot browse is a folder they cannot file into, or the
        // video would vanish from their own library the moment it moved.
        $this->respondError('That folder is not available to you.', $this->move_folder_id && ! $folder);

        $moved = app(VideoLibraryService::class)->moveVideos($videos, $folder);

        $this->closePanel();
        unset($this->videos);

        return $this->respondSuccess("{$moved} video(s) moved to ".($folder?->name ?? 'the library root').'.');
    }

    /**
     * An attached video is refused rather than deleted. The database would refuse
     * it anyway; failing here gives somebody a sentence instead of a foreign key
     * error, and a batch reports what it could not take.
     */
    public function deleteSelected(): bool
    {
        $videos = $this->manageableSelection;

        $this->respondError('Choose a video to delete.', $videos->isEmpty());

        $service = app(VideoLibraryService::class);
        $deleted = 0;
        $refused = [];

        foreach ($videos as $video) {
            $error = $service->delete($video);

            $error === null ? $deleted++ : $refused[] = $video->title;
        }

        $this->selected = [];
        $this->closePanel();
        unset($this->videos, $this->remaining);

        $this->respondError(
            'Still in use somewhere: '.implode(', ', $refused).'. Remove it from there first.',
            $deleted === 0 && filled($refused),
        );

        if (filled($refused)) {
            return $this->respondSuccess("{$deleted} video(s) deleted. Still in use: ".implode(', ', $refused).'.');
        }

        return $this->respondSuccess("{$deleted} video(s) deleted.");
    }

    // ||||||||||||||||||||||||||||||||||||||||||||||||
    // FOLDER WRITES

    public function newFolder(): void
    {
        $this->resetValidation();

        $this->reset('folder_id', 'folder_name', 'folder_shared', 'folder_visible_to_role');

        $this->folder_parent_id = $this->folder;
        $this->folder_visibility = MediaVisibilityEnum::PRIVATE->value;

        $this->panel = 'folder';
    }

    public function editFolder(int $folderId): void
    {
        $folder = VideoFolder::query()->browsableBy($this->user)->whereKey($folderId)->first();

        abort_unless((bool) $folder, 404);
        abort_unless($this->canManageFolder($folder), 403);

        $this->resetValidation();

        $this->folder_id = $folder->id;
        $this->folder_name = $folder->name;
        $this->folder_parent_id = $folder->parent_id;
        $this->folder_shared = $folder->isShared();
        $this->folder_visibility = $folder->visibility->value;
        $this->folder_visible_to_role = $folder->visible_to_role?->value;

        $this->panel = 'folder';
    }

    public function saveFolder(): bool
    {
        $this->validate([
            'folder_name' => ['required', 'string', 'max:255'],
            'folder_parent_id' => ['nullable', 'integer', Rule::exists('video_folders', 'id')],
            'folder_visibility' => ['required', Rule::enum(MediaVisibilityEnum::class)],
            'folder_visible_to_role' => ['nullable', Rule::enum(UserRoleEnum::class)],
        ]);

        $visibility = MediaVisibilityEnum::from($this->folder_visibility);

        $this->respondError(
            'Choose which role should be able to browse this folder.',
            $visibility->needsRole() && ! $this->folder_visible_to_role,
            field: 'folder_visible_to_role'
        );

        $role = $this->folder_visible_to_role ? UserRoleEnum::from($this->folder_visible_to_role) : null;
        $service = app(VideoLibraryService::class);

        if ($this->folder_id) {
            $folder = VideoFolder::query()->whereKey($this->folder_id)->first();

            abort_unless((bool) $folder, 404);
            abort_unless($this->canManageFolder($folder), 403);

            $service->updateFolder($folder, $this->folder_name, $visibility, $role);
        } else {
            $service->createFolder(
                $this->user,
                $this->folder_name,
                $this->folder_parent_id ? VideoFolder::query()->find($this->folder_parent_id) : null,
                $this->folder_shared,
                $visibility,
                $role,
            );
        }

        $this->closePanel();
        unset($this->folders, $this->videos);

        return $this->respondSuccess('The folder has been saved.');
    }

    public function deleteFolder(int $folderId): bool
    {
        $folder = VideoFolder::query()->whereKey($folderId)->first();

        abort_unless((bool) $folder, 404);
        abort_unless($this->canManageFolder($folder), 403);

        app(VideoLibraryService::class)->deleteFolder($folder);

        if ($this->folder === $folderId) {
            $this->folder = null;
        }

        $this->closePanel();
        unset($this->folders, $this->videos, $this->currentFolder);

        return $this->respondSuccess('The folder has been deleted. Its videos moved to the root.');
    }

    // ||||||||||||||||||||||||||||||||||||||||||||||||
    // GUARDS

    /**
     * Seeing a video is not the same as being allowed to change it. A public video
     * is visible to everybody and editable only by its owner.
     */
    protected function canManage(Video $video): bool
    {
        return $this->user->isAdmin() || $video->user_id === $this->user->id;
    }

    protected function canManageFolder(VideoFolder $folder): bool
    {
        return $this->user->isAdmin() || $folder->user_id === $this->user->id;
    }
}
