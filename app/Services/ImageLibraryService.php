<?php

namespace App\Services;

use App\Enums\ActivityActionEnum;
use App\Enums\MediaVisibilityEnum;
use App\Enums\StatusDefault;
use App\Enums\UserTypeEnum;
use App\Models\Image;
use App\Models\ImageFolder;
use App\Models\ImageUsage;
use App\Models\User;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
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
     *
     * Public because DemoSeeder clears this directory between runs, and a
     * seeder repeating the literal is a seeder that keeps deleting the wrong
     * folder the day this changes.
     */
    public const STORAGE_PATH = 'library';

    /**
     * The longest side a converted photo is kept at - wider than any screen it
     * is shown on, so a full-width hero still looks sharp.
     */
    public const MAX_DIMENSION = 2560;

    /** GD's WebP quality, 0-100. Past about 80 the file grows faster than the eye can tell. */
    public const WEBP_QUALITY = 80;

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

    /**
     * Whether photos are re-encoded as WebP on the way in. Needs GD built with
     * WebP; a server without it keeps the original file rather than failing.
     */
    public function webpEnabled(): bool
    {
        return (bool) kSiteFlag('uploads', 'convert-to-webp', true) && \function_exists('imagewebp');
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
        MediaVisibilityEnum $visibility = MediaVisibilityEnum::PRIVATE,
        ?UserTypeEnum $visibleToType = null,
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

        $mimeType = (string) $file->getMimeType();
        $extension = mb_strtolower((string) $file->getClientOriginalExtension());

        // A phone photo arrives at 4000px and several megabytes, to be shown in a
        // card a few hundred pixels wide. Re-encoded here, once, the stored file
        // is the small one - and the path saved below already points at it.
        if ($this->webpEnabled() && $converted = $this->convertToWebp($path)) {
            [$path, $mimeType, $extension] = [$converted, 'image/webp', 'webp'];
        }

        // Measured after the conversion, which may have scaled the image down,
        // and before optimising, because optimising is what changes it.
        $dimensions = $this->readDimensions($path);

        if ($this->optimizationEnabled()) {
            $this->optimize($path);
        }

        // Read back off the disk rather than from the upload: optimisation has
        // already run, and the stored size is the one that matters. Measured here,
        // while the file is still on this server.
        $size = Storage::disk(CloudStorageService::LOCAL_DISK)->size($path);

        // Every step above needs a real path, so the file is handed to R2 only
        // now, when File storage has it on. The disk it ends up on is recorded:
        // an R2 refusal leaves it here, working, rather than losing the upload.
        $disk = app(CloudStorageService::class)->promote($path);

        $image = $user->images()->create([
            'image_folder_id' => $folder?->id,
            'title' => $title,
            'file_path' => $path,
            'disk' => $disk,
            'mime_type' => $mimeType,
            'extension' => $extension,
            'size' => $size,
            'width' => $dimensions['width'],
            'height' => $dimensions['height'],
            ...$this->visibilityAttributes($visibility, $visibleToType, $user),
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
    public function storeMany(User $user, array $files, ?ImageFolder $folder = null, MediaVisibilityEnum $visibility = MediaVisibilityEnum::PRIVATE, ?UserTypeEnum $visibleToType = null): array
    {
        $stored = collect();
        $skipped = 0;

        foreach ($files as $file) {
            $image = $this->store($user, $file, null, $folder, $visibility, $visibleToType);

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
        ?MediaVisibilityEnum $visibility = null,
        ?UserTypeEnum $visibleToType = null,
        ?string $altText = null,
    ): Image {
        $activity = app(ActivityLogService::class);

        $image->fill([
            'title' => trim($title) ?: $image->title,
            'image_folder_id' => $folder?->id,
            'alt_text' => $altText,
            ...($visibility ? $this->visibilityAttributes($visibility, $visibleToType, $image->user) : []),
        ]);

        $affected = $activity->affectedColumns($image);

        $image->save();

        $activity->logActivity(ActivityActionEnum::IMAGE_UPDATE, $image->title, $affected, $image);

        $this->tellOwner($image, "Your image \"{$image->title}\" was updated.");

        return $image;
    }

    /**
     * Whether this image can be cropped here. GIF is refused because GD keeps
     * only the first frame of an animation, and SVG because it has no pixels.
     */
    public function canCrop(Image $image): bool
    {
        return \in_array($image->mime_type, ['image/jpeg', 'image/png', 'image/webp'], true);
    }

    /**
     * Cut the stored file down to a box and write it back over itself.
     *
     * The box arrives as fractions of the picture (0 to 1), the way the crop
     * panel draws it, so the preview's size never matters. The path is kept,
     * exactly as a rename keeps it: every post and page already pointing at
     * this image shows the cropped one, and nothing needs relinking. Returns
     * null, leaving the file untouched, when the image cannot be cropped or the
     * box is too small to be a picture.
     */
    public function crop(Image $image, float $x, float $y, float $width, float $height): ?Image
    {
        if (! $this->canCrop($image)) {
            return null;
        }

        // GD needs a real path. An image on R2 is brought down for the crop and
        // sent back over itself afterwards, so its URL holds either way.
        $storage = app(CloudStorageService::class);
        $home = $image->disk ?: CloudStorageService::LOCAL_DISK;

        if (! $storage->pull($image->file_path, $home)) {
            return null;
        }

        $disk = Storage::disk(CloudStorageService::LOCAL_DISK);
        $source = $disk->path($image->file_path);
        $written = false;
        $cropWidth = $cropHeight = 0;

        try {
            [$fullWidth, $fullHeight, $type] = getimagesize($source) ?: [0, 0, 0];

            $picture = match ($type) {
                IMAGETYPE_JPEG => imagecreatefromjpeg($source),
                IMAGETYPE_PNG => imagecreatefrompng($source),
                IMAGETYPE_WEBP => imagecreatefromwebp($source),
                default => false,
            };

            if ($picture === false) {
                return null;
            }

            // Held inside the picture whatever the browser sent: a box hanging off
            // an edge is cut at the edge rather than padded with black.
            $left = (int) round(max(0, min(1, $x)) * $fullWidth);
            $top = (int) round(max(0, min(1, $y)) * $fullHeight);
            $cropWidth = (int) round(max(0, min(1, $width)) * $fullWidth);
            $cropHeight = (int) round(max(0, min(1, $height)) * $fullHeight);
            $cropWidth = min($cropWidth, $fullWidth - $left);
            $cropHeight = min($cropHeight, $fullHeight - $top);

            if ($cropWidth < 10 || $cropHeight < 10) {
                return null;
            }

            imagepalettetotruecolor($picture);
            $cropped = imagecrop($picture, ['x' => $left, 'y' => $top, 'width' => $cropWidth, 'height' => $cropHeight]);

            if ($cropped === false) {
                return null;
            }

            imagealphablending($cropped, false);
            imagesavealpha($cropped, true);

            $written = match ($type) {
                IMAGETYPE_JPEG => imagejpeg($cropped, $source, 90),
                IMAGETYPE_PNG => imagepng($cropped, $source),
                IMAGETYPE_WEBP => imagewebp($cropped, $source, self::WEBP_QUALITY),
            };
        } catch (\Throwable $e) {
            Log::channel('ezeh')->warning('Image crop failed: '.$e->getMessage(), [
                'image_id' => $image->id,
            ]);
        } finally {
            // Runs on every way out, the early returns above included. Measured
            // before the working copy goes home, and the copy always goes:
            // cropped, it replaces the original on R2; not, it is only a stray
            // duplicate of a file that lives there.
            $size = $written ? $disk->size($image->file_path) : null;

            if ($home !== CloudStorageService::LOCAL_DISK) {
                if ($written) {
                    $written = $storage->push($image->file_path, $home);
                } else {
                    $disk->delete($image->file_path);
                }
            }
        }

        if (! $written) {
            return null;
        }

        $activity = app(ActivityLogService::class);

        $image->fill([
            'width' => $cropWidth,
            'height' => $cropHeight,
            'size' => $size,
        ]);

        $affected = $activity->affectedColumns($image);

        // Saved even when the numbers came out the same, so updated_at moves and
        // the library's preview asks for the new file rather than its cached copy.
        $image->touch();

        $activity->logActivity(ActivityActionEnum::IMAGE_UPDATE, $image->title, $affected, $image);

        $this->tellOwner($image, "Your image \"{$image->title}\" was cropped.");

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
        $disk = $image->disk ?: CloudStorageService::LOCAL_DISK;
        $title = $image->title;
        $owner = $image->user;

        $image->delete();

        // From the disk the row names: an image uploaded while R2 was on is still
        // there after the switch went off.
        kDeleteFile($path, $disk);

        app(ActivityLogService::class)->logActivity(ActivityActionEnum::IMAGE_DELETE, $title);

        // Resolved before the row went, so there is still an owner to tell. The
        // isUser() guard matches tellOwner(): an admin's own media is the site's,
        // not something they need telling about.
        if ($owner?->isUser()) {
            app(NotificationSubscriberService::class)
                ->notifyOwnerOfChange($owner, "Your image \"{$title}\" was deleted.");
        }

        return null;
    }

    // |||||||||||||||||||||||||||||||||||||||||||||||||||||||||||
    // FOLDERS

    public function createFolder(
        User $user,
        string $name,
        ?ImageFolder $parent = null,
        bool $shared = false,
        ?MediaVisibilityEnum $visibility = null,
        ?UserTypeEnum $visibleToType = null,
    ): ImageFolder {
        // A shared folder belongs to nobody and everybody browses it, so only an
        // administrator can make one.
        $ownerId = ($shared && $user->isAdmin()) ? null : $user->id;

        // A platform folder nobody may browse is a folder nobody can use, so
        // sharing one implies public unless the caller says otherwise. An
        // administrator who wants a restricted shared folder passes ROLE.
        $visibility ??= $ownerId === null ? MediaVisibilityEnum::PUBLIC : MediaVisibilityEnum::PRIVATE;

        $folder = ImageFolder::query()->create([
            'user_id' => $ownerId,
            'parent_id' => $parent?->id,
            'name' => trim($name),
            'slug' => kSlug($name),
            ...$this->visibilityAttributes($visibility, $visibleToType, $ownerId ? $user : null),
            'status' => StatusDefault::ACTIVE,
        ]);

        app(ActivityLogService::class)->logActivity(ActivityActionEnum::IMAGE_FOLDER_CREATE, $folder->name, model: $folder);

        return $folder;
    }

    /**
     * The folder at the end of a path, made wherever it is missing.
     *
     * Used by the folder import, where "Holiday/Beach" on somebody's computer
     * becomes the same two folders in the library. A folder already sitting in
     * that place under that name, for the same owner, is reused rather than
     * doubled, so importing the same folder twice files into one tree.
     *
     * @param  array<int, string>  $segments
     */
    public function folderForPath(
        User $user,
        array $segments,
        ?ImageFolder $parent = null,
        bool $shared = false,
        ?MediaVisibilityEnum $visibility = null,
        ?UserTypeEnum $visibleToType = null,
    ): ?ImageFolder {
        $ownerId = ($shared && $user->isAdmin()) ? null : $user->id;

        foreach ($segments as $name) {
            $existing = ImageFolder::query()
                ->active()
                ->where('user_id', $ownerId)
                ->where('parent_id', $parent?->id)
                ->where('name', $name)
                ->first();

            $parent = $existing ?? $this->createFolder($user, $name, $parent, $shared, $visibility, $visibleToType);
        }

        return $parent;
    }

    /**
     * Rename a folder and reset who may browse it.
     *
     * Only the folder moves. The images inside keep the visibility they were
     * given, so tightening a folder never quietly hides an image somebody was
     * deliberately allowed to see, and loosening one never exposes anything.
     */
    public function updateFolder(
        ImageFolder $folder,
        string $name,
        ?MediaVisibilityEnum $visibility = null,
        ?UserTypeEnum $visibleToType = null,
    ): ImageFolder {
        $activity = app(ActivityLogService::class);

        $folder->fill([
            'name' => trim($name) ?: $folder->name,
            'slug' => kSlug(trim($name) ?: $folder->name),
            ...($visibility ? $this->visibilityAttributes($visibility, $visibleToType, $folder->user) : []),
        ]);

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
     * Refile a batch of images.
     *
     * Passing null moves them to the root. The caller has already decided which
     * of these the account may manage; this only does the move, and returns how
     * many actually landed so the screen can say so.
     *
     * @param  Collection<int, Image>  $images
     */
    public function moveImages(Collection $images, ?ImageFolder $folder): int
    {
        if ($images->isEmpty()) {
            return 0;
        }

        $activity = app(ActivityLogService::class);

        DB::transaction(function () use ($images, $folder, $activity): void {
            foreach ($images as $image) {
                $image->fill(['image_folder_id' => $folder?->id]);

                $affected = $activity->affectedColumns($image);

                $image->save();

                $activity->logActivity(ActivityActionEnum::IMAGE_UPDATE, $image->title, $affected, $image);
            }
        });

        // One message per owner rather than one per file: somebody whose ten
        // images were refiled in a single action was subject to one change, and
        // ten emails saying so is a worse way to be told.
        $destination = $folder?->name ?? 'the library root';

        $images->groupBy('user_id')->each(function (Collection $owned) use ($destination) {
            $owner = $owned->first()?->user;

            if (! $owner?->isUser()) {
                return;
            }

            $count = $owned->count();

            app(NotificationSubscriberService::class)->notifyOwnerOfChange(
                $owner,
                $count === 1
                    ? "Your image \"{$owned->first()->title}\" was moved to {$destination}."
                    : "{$count} of your images were moved to {$destination}.",
            );
        });

        return $images->count();
    }

    /**
     * Tell an image's owner that somebody else changed it.
     *
     * Silent when the owner is an administrator or is the one doing it — the
     * point is that a member's library is private, so a change they did not make
     * is worth hearing about.
     */
    private function tellOwner(Image $image, string $summary): void
    {
        $owner = $image->user;

        if (! $owner?->isUser()) {
            return;
        }

        app(NotificationSubscriberService::class)->notifyOwnerOfChange($owner, $summary);
    }

    /**
     * The folder tree this account may browse, flattened into a select-friendly
     * list with the depth expressed in the label.
     *
     * @return Collection<int, array{id: int, label: string}>
     */
    public function folderOptions(User $user, ?User $owner = null): Collection
    {
        $folders = ImageFolder::query()->active()->browsableBy($user, $owner)->orderBy('name')->get();

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
        $this->syncMany($image ? [$image->id] : [], $usable, $field);
    }

    /**
     * Point a field at any number of images, in the order given.
     *
     * Rows carry no sort column, so the order is the order they were inserted and
     * a reordered gallery is written out again from scratch. That is cheap — a
     * handful of rows — and it is what lets a slot be arranged without a migration.
     * Nothing reads a usage row's created_at, so recreating one costs nothing.
     *
     * @param  array<int, int>  $imageIds
     */
    public function syncMany(array $imageIds, Model $usable, string $field = 'image'): void
    {
        $ids = collect($imageIds)->map(fn ($id) => (int) $id)->filter()->unique()->values();

        // Untouched is the common case — a post saved twice with the same cover
        // should not churn the row the delete guard reads.
        if ($ids->all() === $this->usedImageIds($usable, $field)) {
            return;
        }

        DB::transaction(function () use ($ids, $usable, $field): void {
            $this->detach($usable, $field);

            foreach ($ids as $id) {
                ImageUsage::query()->create([
                    'image_id' => $id,
                    'usable_type' => $usable->getMorphClass(),
                    'usable_id' => $usable->getKey(),
                    'field' => $field,
                ]);
            }
        });
    }

    /**
     * What a record currently holds in one field, in the order it was attached.
     *
     * @return array<int, int>
     */
    public function usedImageIds(Model $usable, string $field = 'image'): array
    {
        if (! $usable->exists) {
            return [];
        }

        return ImageUsage::query()
            ->where('usable_type', $usable->getMorphClass())
            ->where('usable_id', $usable->getKey())
            ->where('field', $field)
            ->orderBy('id')
            ->pluck('image_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    // |||||||||||||||||||||||||||||||||||||||||||||||||||||||||||
    // QUERIES

    /**
     * The picker's query: what this account may choose from.
     */
    public function libraryQuery(User $user, ?ImageFolder $folder = null, ?string $search = null, string $sort = 'newest', ?User $owner = null): Builder
    {
        return Image::query()
            ->active()
            ->visibleTo($user, $owner)
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
    private function visibilityAttributes(MediaVisibilityEnum $visibility, ?UserTypeEnum $role, ?User $owner = null): array
    {
        // A member's library is theirs alone, whatever the form asked for. There
        // is no audience in this product for one member's uploads to reach
        // another, so PUBLIC or TYPE on a member-owned row would only ever be a
        // leak — enforced here rather than in the screens, because a screen is
        // not a boundary. A row owned by nobody is a shared platform folder and
        // is left alone.
        if ($owner?->isUser()) {
            return [
                'visibility' => MediaVisibilityEnum::PRIVATE,
                'visible_to_type' => null,
            ];
        }

        return [
            'visibility' => $visibility,
            'visible_to_type' => $visibility->needsType() ? $role : null,
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
     * Re-encode a stored JPEG or PNG as WebP, scaled down to MAX_DIMENSION on
     * its longest side, and remove the original. Returns the new path, or null
     * to keep the file as it was.
     *
     * GIF is left alone because GD would keep only the first frame of an
     * animation, and SVG because it is already text. A WebP upload is only
     * touched when it needs scaling: re-encoding it at the same size loses
     * quality for nothing. A conversion that comes out larger - a small, flat
     * PNG can - is thrown away. Any failure, a photo too large for the memory
     * limit included, keeps the original: an upload never fails here.
     */
    private function convertToWebp(string $path): ?string
    {
        $disk = Storage::disk('public');
        $source = $disk->path($path);
        $target = null;

        try {
            [$width, $height, $type] = getimagesize($source) ?: [0, 0, 0];

            $image = match ($type) {
                IMAGETYPE_JPEG => imagecreatefromjpeg($source),
                IMAGETYPE_PNG => imagecreatefrompng($source),
                IMAGETYPE_WEBP => imagecreatefromwebp($source),
                default => false,
            };

            if ($image === false) {
                return null;
            }

            $scale = max($width, $height) > self::MAX_DIMENSION;

            if ($type === IMAGETYPE_WEBP && ! $scale) {
                return null;
            }

            // A phone records which way up the photo was taken as a tag rather
            // than turning the pixels. The re-encoded file carries no tags, so
            // the turn is made here or the photo comes out on its side.
            if ($type === IMAGETYPE_JPEG && \function_exists('exif_read_data')) {
                $angle = match ((int) (@exif_read_data($source)['Orientation'] ?? 1)) {
                    3 => 180,
                    6 => -90,
                    8 => 90,
                    default => 0,
                };

                if ($angle !== 0) {
                    $image = imagerotate($image, $angle, 0);
                    [$width, $height] = [imagesx($image), imagesy($image)];
                }
            }

            if ($scale) {
                $ratio = self::MAX_DIMENSION / max($width, $height);
                $image = imagescale($image, (int) round($width * $ratio), (int) round($height * $ratio), IMG_BICUBIC);
            }

            // Keep a PNG's transparency through the conversion.
            imagepalettetotruecolor($image);
            imagealphablending($image, false);
            imagesavealpha($image, true);

            $stem = \dirname($path).'/'.pathinfo($path, PATHINFO_FILENAME);
            $target = $stem.'.webp';

            if ($target !== $path && $disk->exists($target)) {
                $target = $stem.'-'.Str::lower(Str::random(5)).'.webp';
            }

            if (! imagewebp($image, $disk->path($target), self::WEBP_QUALITY)) {
                return null;
            }

            if (! $scale && $disk->size($target) >= $disk->size($path)) {
                $disk->delete($target);

                return null;
            }

            if ($target !== $path) {
                $disk->delete($path);
            }

            return $target;
        } catch (\Throwable $e) {
            if ($target && $target !== $path && $disk->exists($target)) {
                $disk->delete($target);
            }

            Log::channel('ezeh')->warning('WebP conversion skipped: '.$e->getMessage(), [
                'path' => $path,
            ]);

            return null;
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
            Log::channel('ezeh')->warning('Image optimisation skipped: '.$e->getMessage(), [
                'path' => $path,
            ]);
        }
    }
}
