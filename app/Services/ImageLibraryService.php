<?php

namespace App\Services;

use App\Enums\ActivityActionEnum;
use App\Enums\ImageVisibilityEnum;
use App\Enums\StatusDefault;
use App\Enums\UserRoleEnum;
use App\Models\Image;
use App\Models\ImageFolder;
use App\Models\ImageUsage;
use App\Models\User;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Spatie\LaravelImageOptimizer\Facades\ImageOptimizer;

/**
 * The shared image library: uploading, organising, and the rules about who may
 * see an image and when it may be deleted.
 */
#[Singleton]
class ImageLibraryService
{
    /**
     * Where uploads land on the public disk.
     */
    private const STORAGE_PATH = 'library';

    // |||||||||||||||||||||||||||||||||||||||||||||||||||||||||||
    // LIMITS

    /**
     * How many images one member may hold.
     *
     * Administrators are not counted: the library is also the site's media
     * manager, and a quota on the person maintaining the site is a quota on the
     * site itself. Zero or a negative number means no limit.
     */
    public function uploadLimitFor(User $user): ?int
    {
        if ($user->isAdmin()) {
            return null;
        }

        $limit = (int) kSiteFlag('uploads', 'user-image-limit', 50);

        return $limit > 0 ? $limit : null;
    }

    public function remainingUploadsFor(User $user): ?int
    {
        $limit = $this->uploadLimitFor($user);

        return $limit === null ? null : max(0, $limit - $user->images()->count());
    }

    /**
     * The per-file size ceiling, in kilobytes, for ImageRule.
     */
    public function maxImageSize(): int
    {
        $size = (int) kSiteFlag('uploads', 'max-image-size', 2048);

        return max(64, min($size ?: 2048, 20480));
    }

    public function optimizationEnabled(): bool
    {
        return (bool) kSiteFlag('uploads', 'optimize-images', true);
    }

    // |||||||||||||||||||||||||||||||||||||||||||||||||||||||||||
    // UPLOADING

    /**
     * Store one uploaded file and record it.
     *
     * Returns the Image, or null when the quota is already spent — the caller
     * decides how to report that, because a bulk upload wants to say how many
     * went through rather than failing the lot.
     */
    public function store(
        User $user,
        mixed $file,
        ?string $title = null,
        ?ImageFolder $folder = null,
        ImageVisibilityEnum $visibility = ImageVisibilityEnum::PRIVATE,
        ?UserRoleEnum $visibleToRole = null,
    ): ?Image {
        $remaining = $this->remainingUploadsFor($user);

        if ($remaining !== null && $remaining < 1) {
            return null;
        }

        $title = trim((string) ($title ?: pathinfo((string) $file->getClientOriginalName(), PATHINFO_FILENAME)));
        $title = $title !== '' ? $title : 'Untitled';

        // Stored under the uploader's id so one person's library is one prefix on
        // disk — which makes a bulk export or a per-account cleanup trivial.
        $path = kStoreFile($file, $title, self::STORAGE_PATH.'/'.$user->id);

        // Measured before optimising, because optimising is what changes it.
        $dimensions = $this->readDimensions($path);

        if ($this->optimizationEnabled()) {
            $this->optimize($path);
        }

        $image = $user->images()->create([
            'image_folder_id' => $folder?->id,
            'title' => $title,
            'file_path' => $path,
            'disk' => 'public',
            'mime_type' => (string) $file->getMimeType(),
            'extension' => mb_strtolower((string) $file->getClientOriginalExtension()),
            // Read back off the disk rather than from the upload: optimisation has
            // already run, and the stored size is the one that matters.
            'size' => Storage::disk('public')->size($path),
            'width' => $dimensions['width'],
            'height' => $dimensions['height'],
            ...$this->visibilityAttributes($visibility, $visibleToRole),
            'status' => StatusDefault::ACTIVE,
        ]);

        app(ActivityLogService::class)->logActivity(ActivityActionEnum::IMAGE_UPLOAD, $title, model: $image);

        return $image;
    }

    /**
     * Store several files, stopping cleanly when the quota runs out.
     *
     * @param  array<int, mixed>  $files
     * @return array{stored: Collection<int, Image>, skipped: int}
     */
    public function storeMany(User $user, array $files, ?ImageFolder $folder = null, ImageVisibilityEnum $visibility = ImageVisibilityEnum::PRIVATE, ?UserRoleEnum $visibleToRole = null): array
    {
        $stored = collect();
        $skipped = 0;

        foreach ($files as $file) {
            $image = $this->store($user, $file, null, $folder, $visibility, $visibleToRole);

            $image ? $stored->push($image) : $skipped++;
        }

        return ['stored' => $stored, 'skipped' => $skipped];
    }

    // |||||||||||||||||||||||||||||||||||||||||||||||||||||||||||
    // EDITING

    /**
     * Rename and re-file an image.
     *
     * The title changes; the stored path never does. Renaming the file would
     * break every page, post and email already pointing at the old URL, which is
     * exactly why the two are separate columns.
     */
    public function update(
        Image $image,
        string $title,
        ?ImageFolder $folder = null,
        ?ImageVisibilityEnum $visibility = null,
        ?UserRoleEnum $visibleToRole = null,
        ?string $altText = null,
    ): Image {
        $activity = app(ActivityLogService::class);

        $image->fill([
            'title' => trim($title) ?: $image->title,
            'image_folder_id' => $folder?->id,
            'alt_text' => $altText,
            ...($visibility ? $this->visibilityAttributes($visibility, $visibleToRole) : []),
        ]);

        $affected = $activity->affectedColumns($image);

        $image->save();

        $activity->logActivity(ActivityActionEnum::IMAGE_UPDATE, $image->title, $affected, $image);

        return $image;
    }

    /**
     * Delete an image and the file behind it.
     *
     * Returns the reason it was refused, or null when it worked. An attached
     * image must not go — the database would refuse it anyway, but failing here
     * gives somebody a sentence instead of a foreign key error.
     */
    public function delete(Image $image): ?string
    {
        if ($image->isAttached()) {
            $count = $image->usages()->count();

            return "This image is used in {$count} place(s). Remove it from those first.";
        }

        $path = $image->file_path;
        $title = $image->title;

        $image->delete();

        kDeleteFile($path);

        app(ActivityLogService::class)->logActivity(ActivityActionEnum::IMAGE_DELETE, $title);

        return null;
    }

    // |||||||||||||||||||||||||||||||||||||||||||||||||||||||||||
    // FOLDERS

    public function createFolder(User $user, string $name, ?ImageFolder $parent = null, bool $shared = false): ImageFolder
    {
        // A shared folder belongs to nobody and everybody browses it, so only an
        // administrator can make one.
        $ownerId = ($shared && $user->isAdmin()) ? null : $user->id;

        $folder = ImageFolder::query()->create([
            'user_id' => $ownerId,
            'parent_id' => $parent?->id,
            'name' => trim($name),
            'slug' => kSlug($name),
            'status' => StatusDefault::ACTIVE,
        ]);

        app(ActivityLogService::class)->logActivity(ActivityActionEnum::IMAGE_FOLDER_CREATE, $folder->name, model: $folder);

        return $folder;
    }

    public function renameFolder(ImageFolder $folder, string $name): ImageFolder
    {
        $activity = app(ActivityLogService::class);

        $folder->fill(['name' => trim($name), 'slug' => kSlug($name)]);

        $affected = $activity->affectedColumns($folder);

        $folder->save();

        $activity->logActivity(ActivityActionEnum::IMAGE_FOLDER_UPDATE, $folder->name, $affected, $folder);

        return $folder;
    }

    /**
     * Remove a folder. Its images fall back to the root and its subfolders are
     * promoted — both by foreign key, so nothing here has to walk the tree.
     */
    public function deleteFolder(ImageFolder $folder): void
    {
        $name = $folder->name;

        $folder->delete();

        app(ActivityLogService::class)->logActivity(ActivityActionEnum::IMAGE_FOLDER_DELETE, $name);
    }

    /**
     * The folder tree this account may browse, flattened into a select-friendly
     * list with the depth expressed in the label.
     *
     * @return Collection<int, array{id: int, label: string}>
     */
    public function folderOptions(User $user): Collection
    {
        $folders = ImageFolder::query()->active()->browsableBy($user)->orderBy('name')->get();

        $byParent = $folders->groupBy('parent_id');

        $flatten = function (?int $parentId, int $depth) use (&$flatten, $byParent): Collection {
            return ($byParent->get($parentId) ?? collect())
                ->flatMap(fn (ImageFolder $folder) => collect([[
                    'id' => $folder->id,
                    'label' => str_repeat('— ', $depth).$folder->name,
                ]])->concat($flatten($folder->id, $depth + 1)));
        };

        return $flatten(null, 0)->values();
    }

    // |||||||||||||||||||||||||||||||||||||||||||||||||||||||||||
    // USAGE

    /**
     * Record that a model is using an image, so the delete guard can see it.
     *
     * insertOrIgnore semantics via firstOrCreate against the unique index: saving
     * the same post twice must not stack up usage rows.
     */
    public function attach(Image $image, Model $usable, string $field = 'image'): void
    {
        $image->usages()->firstOrCreate([
            'usable_type' => $usable->getMorphClass(),
            'usable_id' => $usable->getKey(),
            'field' => $field,
        ]);
    }

    /**
     * Release a model's claim on whatever image it held in this field.
     */
    public function detach(Model $usable, string $field = 'image'): void
    {
        ImageUsage::query()
            ->where('usable_type', $usable->getMorphClass())
            ->where('usable_id', $usable->getKey())
            ->where('field', $field)
            ->delete();
    }

    /**
     * Point a field at one image, replacing whatever it pointed at before.
     */
    public function syncSingle(?Image $image, Model $usable, string $field = 'image'): void
    {
        $this->detach($usable, $field);

        if ($image) {
            $this->attach($image, $usable, $field);
        }
    }

    // |||||||||||||||||||||||||||||||||||||||||||||||||||||||||||
    // QUERIES

    /**
     * The picker's query: what this account may choose from.
     */
    public function libraryQuery(User $user, ?ImageFolder $folder = null, ?string $search = null, string $sort = 'newest'): Builder
    {
        return Image::query()
            ->active()
            ->visibleTo($user)
            ->when($folder, fn (Builder $query) => $query->where('image_folder_id', $folder->id))
            ->when($search, fn (Builder $query) => $query->where('title', 'like', '%'.$search.'%'))
            ->tap(fn (Builder $query) => $this->applySort($query, $sort));
    }

    /**
     * The orders the library offers, for the sort control.
     *
     * @return array<string, string>
     */
    public function sortOptions(): array
    {
        return [
            'newest' => 'Newest first',
            'oldest' => 'Oldest first',
            'name' => 'Name A-Z',
            'largest' => 'Largest first',
        ];
    }

    // |||||||||||||||||||||||||||||||||||||||||||||||||||||||||||
    // PRIVATE

    /**
     * Only ROLE carries a role. Forcing the column to null otherwise means a
     * visibility changed twice cannot quietly restore an old audience.
     *
     * @return array<string, mixed>
     */
    private function visibilityAttributes(ImageVisibilityEnum $visibility, ?UserRoleEnum $role): array
    {
        return [
            'visibility' => $visibility,
            'visible_to_role' => $visibility->needsRole() ? $role : null,
        ];
    }

    /**
     * An order the request did not ask for falls through to newest first, so a
     * hand-typed ?sort= cannot leave the grid in an undefined order.
     */
    private function applySort(Builder $query, string $sort): void
    {
        match ($sort) {
            'oldest' => $query->orderBy('created_at')->orderBy('id'),
            'name' => $query->orderBy('title')->orderBy('id'),
            'largest' => $query->orderByDesc('size')->orderByDesc('id'),
            default => $query->newestFirst(),
        };
    }

    /**
     * @return array{width: int|null, height: int|null}
     */
    private function readDimensions(string $path): array
    {
        try {
            $size = getimagesize(Storage::disk('public')->path($path));

            return ['width' => $size[0] ?? null, 'height' => $size[1] ?? null];
        } catch (\Throwable) {
            // An SVG, or a driver with no local path. Not knowing the dimensions
            // is not a reason to refuse the upload.
            return ['width' => null, 'height' => null];
        }
    }

    /**
     * Shrink the file in place.
     *
     * The optimiser shells out to jpegoptim, optipng and friends. Where those are
     * not installed it does nothing at all — which is the right outcome, so this
     * must never be allowed to fail an upload.
     */
    private function optimize(string $path): void
    {
        try {
            ImageOptimizer::optimize(Storage::disk('public')->path($path));
        } catch (\Throwable $e) {
            Log::channel('code')->warning('Image optimisation skipped: '.$e->getMessage(), [
                'path' => $path,
            ]);
        }
    }
}
