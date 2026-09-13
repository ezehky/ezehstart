<?php

namespace App\Services;

use App\Enums\ActivityActionEnum;
use App\Enums\MediaVisibilityEnum;
use App\Enums\StatusDefault;
use App\Enums\UserTypeEnum;
use App\Enums\VideoProviderEnum;
use App\Models\User;
use App\Models\Video;
use App\Models\VideoFolder;
use App\Models\VideoUsage;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The shared video library: adding embeds, organising them, and the rules about
 * who may see a video and when it may be deleted.
 *
 * The sibling of ImageLibraryService, and deliberately shaped like it — the same
 * folder tree, the same visibility rules, the same usage-backed delete guard. It
 * is much smaller for one reason: a video row is a reference to a file on
 * somebody else's host, so there is no disk, no quota on bytes, and no optimiser.
 */
#[Singleton]
class VideoLibraryService
{
    // |||||||||||||||||||||||||||||||||||||||||||||||||||||||||||
    // LIMITS

    /**
     * How many videos one member may hold.
     *
     * Administrators are not counted: the library is also the site's media
     * manager, and a quota on the person maintaining the site is a quota on the
     * site itself. Zero or a negative number means no limit.
     */
    public function limitFor(User $user): ?int
    {
        if ($user->isAdmin()) {
            return null;
        }

        $limit = (int) kSiteFlag('uploads', 'user-video-limit', 25);

        return $limit > 0 ? $limit : null;
    }

    public function remainingFor(User $user): ?int
    {
        $limit = $this->limitFor($user);

        return $limit === null ? null : max(0, $limit - $user->videos()->count());
    }

    // |||||||||||||||||||||||||||||||||||||||||||||||||||||||||||
    // ADDING

    /**
     * Add one video to an account's library.
     *
     * Returns null when the quota is spent or when the URL belongs to no allowed
     * provider. Both are things VideoUrlRule has already reported on the form, so
     * null here is the belt to that braces rather than the primary report.
     *
     * A URL the account already holds returns the row it already holds instead of
     * failing on the unique key: adding the same video twice is a slip, and the
     * useful answer to a slip is the thing the person meant.
     */
    public function store(
        User $user,
        string $url,
        ?string $title = null,
        ?VideoFolder $folder = null,
        MediaVisibilityEnum $visibility = MediaVisibilityEnum::PRIVATE,
        ?UserTypeEnum $visibleToType = null,
        ?string $description = null,
        ?int $duration = null,
    ): ?Video {
        $resolved = VideoProviderEnum::resolve($url);

        if ($resolved === null) {
            return null;
        }

        $existing = $this->findFor($user, $resolved['provider'], $resolved['id']);

        if ($existing) {
            return $existing;
        }

        $remaining = $this->remainingFor($user);

        if ($remaining !== null && $remaining < 1) {
            return null;
        }

        $title = trim((string) $title);
        // Nothing here calls out to a provider for the real title — a library grid
        // is not worth a round trip per tile to a third party — so an untitled
        // video is named after what it is and renamed by whoever cares.
        $title = $title !== '' ? $title : $resolved['provider']->label().' video';

        $video = $user->videos()->create([
            'video_folder_id' => $folder?->id,
            'title' => $title,
            'provider' => $resolved['provider'],
            'video_id' => $resolved['id'],
            'description' => $description,
            'duration' => $duration,
            ...$this->visibilityAttributes($visibility, $visibleToType, $user),
            'status' => StatusDefault::ACTIVE,
        ]);

        app(ActivityLogService::class)->logActivity(ActivityActionEnum::VIDEO_ADD, $title, model: $video);

        return $video;
    }

    /**
     * The row this account already holds for one video, or null.
     */
    public function findFor(User $user, VideoProviderEnum $provider, string $videoId): ?Video
    {
        return Video::query()
            ->where('user_id', $user->id)
            ->where('provider', $provider)
            ->where('video_id', $videoId)
            ->first();
    }

    // |||||||||||||||||||||||||||||||||||||||||||||||||||||||||||
    // EDITING

    /**
     * Rename and re-file a video.
     *
     * The title, folder and visibility change; the provider and the video id never
     * do. Repointing an existing row at a different video would silently change
     * what every post already embedding it shows, which is why replacing a video
     * means adding the new one and detaching the old.
     */
    public function update(
        Video $video,
        string $title,
        ?VideoFolder $folder = null,
        ?MediaVisibilityEnum $visibility = null,
        ?UserTypeEnum $visibleToType = null,
        ?string $description = null,
        ?int $duration = null,
    ): Video {
        $activity = app(ActivityLogService::class);

        $video->fill([
            'title' => trim($title) ?: $video->title,
            'video_folder_id' => $folder?->id,
            'description' => $description,
            'duration' => $duration,
            ...($visibility ? $this->visibilityAttributes($visibility, $visibleToType, $video->user) : []),
        ]);

        $affected = $activity->affectedColumns($video);

        $video->save();

        $activity->logActivity(ActivityActionEnum::VIDEO_UPDATE, $video->title, $affected, $video);

        $this->tellOwner($video, "Your video \"{$video->title}\" was updated.");

        return $video;
    }

    /**
     * Remove a video from the library.
     *
     * Returns the reason it was refused, or null when it worked. An attached video
     * must not go — the database would refuse it anyway, but failing here gives
     * somebody a sentence instead of a foreign key error. Nothing is deleted at
     * the provider, and nothing should be: the file was never ours.
     */
    public function delete(Video $video): ?string
    {
        if ($video->isAttached()) {
            $count = $video->usages()->count();

            return "This video is used in {$count} place(s). Remove it from those first.";
        }

        $title = $video->title;

        // Resolved before the row goes, so there is still an owner to tell.
        $owner = $video->user;

        $video->delete();

        app(ActivityLogService::class)->logActivity(ActivityActionEnum::VIDEO_DELETE, $title);

        if ($owner?->isUser()) {
            app(NotificationSubscriberService::class)
                ->notifyOwnerOfChange($owner, "Your video \"{$title}\" was deleted.");
        }

        return null;
    }

    // |||||||||||||||||||||||||||||||||||||||||||||||||||||||||||
    // FOLDERS

    public function createFolder(
        User $user,
        string $name,
        ?VideoFolder $parent = null,
        bool $shared = false,
        ?MediaVisibilityEnum $visibility = null,
        ?UserTypeEnum $visibleToType = null,
    ): VideoFolder {
        // A shared folder belongs to nobody and everybody browses it, so only an
        // administrator can make one.
        $ownerId = ($shared && $user->isAdmin()) ? null : $user->id;

        // A platform folder nobody may browse is a folder nobody can use, so
        // sharing one implies public unless the caller says otherwise. An
        // administrator who wants a restricted shared folder passes ROLE.
        $visibility ??= $ownerId === null ? MediaVisibilityEnum::PUBLIC : MediaVisibilityEnum::PRIVATE;

        $folder = VideoFolder::query()->create([
            'user_id' => $ownerId,
            'parent_id' => $parent?->id,
            'name' => trim($name),
            'slug' => kSlug($name),
            ...$this->visibilityAttributes($visibility, $visibleToType, $ownerId ? $user : null),
            'status' => StatusDefault::ACTIVE,
        ]);

        app(ActivityLogService::class)->logActivity(ActivityActionEnum::VIDEO_FOLDER_CREATE, $folder->name, model: $folder);

        return $folder;
    }

    /**
     * Rename a folder and reset who may browse it.
     *
     * Only the folder moves. The videos inside keep the visibility they were
     * given, so tightening a folder never quietly hides a video somebody was
     * deliberately allowed to see, and loosening one never exposes anything.
     */
    public function updateFolder(
        VideoFolder $folder,
        string $name,
        ?MediaVisibilityEnum $visibility = null,
        ?UserTypeEnum $visibleToType = null,
    ): VideoFolder {
        $activity = app(ActivityLogService::class);

        $folder->fill([
            'name' => trim($name) ?: $folder->name,
            'slug' => kSlug(trim($name) ?: $folder->name),
            ...($visibility ? $this->visibilityAttributes($visibility, $visibleToType, $folder->user) : []),
        ]);

        $affected = $activity->affectedColumns($folder);

        $folder->save();

        $activity->logActivity(ActivityActionEnum::VIDEO_FOLDER_UPDATE, $folder->name, $affected, $folder);

        return $folder;
    }

    /**
     * Remove a folder. Its videos fall back to the root and its subfolders are
     * promoted — both by foreign key, so nothing here has to walk the tree.
     */
    public function deleteFolder(VideoFolder $folder): void
    {
        $name = $folder->name;

        $folder->delete();

        app(ActivityLogService::class)->logActivity(ActivityActionEnum::VIDEO_FOLDER_DELETE, $name);
    }

    /**
     * Refile a batch of videos.
     *
     * Passing null moves them to the root. The caller has already decided which of
     * these the account may manage; this only does the move, and returns how many
     * actually landed so the screen can say so.
     *
     * @param  Collection<int, Video>  $videos
     */
    public function moveVideos(Collection $videos, ?VideoFolder $folder): int
    {
        if ($videos->isEmpty()) {
            return 0;
        }

        $activity = app(ActivityLogService::class);

        DB::transaction(function () use ($videos, $folder, $activity): void {
            foreach ($videos as $video) {
                $video->fill(['video_folder_id' => $folder?->id]);

                $affected = $activity->affectedColumns($video);

                $video->save();

                $activity->logActivity(ActivityActionEnum::VIDEO_UPDATE, $video->title, $affected, $video);
            }
        });

        // One message per owner rather than one per row: somebody whose ten
        // videos were refiled in a single action was subject to one change.
        $destination = $folder?->name ?? 'the library root';

        $videos->groupBy('user_id')->each(function (Collection $owned) use ($destination) {
            $owner = $owned->first()?->user;

            if (! $owner?->isUser()) {
                return;
            }

            $count = $owned->count();

            app(NotificationSubscriberService::class)->notifyOwnerOfChange(
                $owner,
                $count === 1
                    ? "Your video \"{$owned->first()->title}\" was moved to {$destination}."
                    : "{$count} of your videos were moved to {$destination}.",
            );
        });

        return $videos->count();
    }

    /**
     * Tell a video's owner that somebody else changed it.
     *
     * Silent when the owner is an administrator or is the one doing it — the
     * point is that a member's library is private, so a change they did not make
     * is worth hearing about.
     */
    private function tellOwner(Video $video, string $summary): void
    {
        $owner = $video->user;

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
        $folders = VideoFolder::query()->active()->browsableBy($user, $owner)->orderBy('name')->get();

        $byParent = $folders->groupBy('parent_id');

        $flatten = function (?int $parentId, int $depth) use (&$flatten, $byParent): Collection {
            return ($byParent->get($parentId) ?? collect())
                ->flatMap(fn (VideoFolder $folder) => collect([[
                    'id' => $folder->id,
                    'label' => str_repeat('— ', $depth).$folder->name,
                ]])->concat($flatten($folder->id, $depth + 1)));
        };

        return $flatten(null, 0)->values();
    }

    // |||||||||||||||||||||||||||||||||||||||||||||||||||||||||||
    // USAGE

    /**
     * Record that a model is using a video, so the delete guard can see it.
     *
     * insertOrIgnore semantics via firstOrCreate against the unique index: saving
     * the same post twice must not stack up usage rows.
     */
    public function attach(Video $video, Model $usable, string $field = 'video'): void
    {
        $video->usages()->firstOrCreate([
            'usable_type' => $usable->getMorphClass(),
            'usable_id' => $usable->getKey(),
            'field' => $field,
        ]);
    }

    /**
     * Release a model's claim on whatever video it held in this field.
     */
    public function detach(Model $usable, string $field = 'video'): void
    {
        VideoUsage::query()
            ->where('usable_type', $usable->getMorphClass())
            ->where('usable_id', $usable->getKey())
            ->where('field', $field)
            ->delete();
    }

    /**
     * Point a field at one video, replacing whatever it pointed at before.
     */
    public function syncSingle(?Video $video, Model $usable, string $field = 'video'): void
    {
        $this->syncMany($video ? [$video->id] : [], $usable, $field);
    }

    /**
     * Point a field at any number of videos, in the order given.
     *
     * Rows carry no sort column, so the order is the order they were inserted and
     * a reordered playlist is written out again from scratch. That is cheap — a
     * handful of rows — and it is what lets a slot be arranged without a migration.
     * Nothing reads a usage row's created_at, so recreating one costs nothing.
     *
     * @param  array<int, int>  $videoIds
     */
    public function syncMany(array $videoIds, Model $usable, string $field = 'video'): void
    {
        $ids = collect($videoIds)->map(fn ($id) => (int) $id)->filter()->unique()->values();

        // Untouched is the common case — a post saved twice with the same trailer
        // should not churn the row the delete guard reads.
        if ($ids->all() === $this->usedVideoIds($usable, $field)) {
            return;
        }

        DB::transaction(function () use ($ids, $usable, $field): void {
            $this->detach($usable, $field);

            foreach ($ids as $id) {
                VideoUsage::query()->create([
                    'video_id' => $id,
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
    public function usedVideoIds(Model $usable, string $field = 'video'): array
    {
        if (! $usable->exists) {
            return [];
        }

        return VideoUsage::query()
            ->where('usable_type', $usable->getMorphClass())
            ->where('usable_id', $usable->getKey())
            ->where('field', $field)
            ->orderBy('id')
            ->pluck('video_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * Reconcile the videos a body of post HTML embeds with the usage rows for it.
     *
     * The editor drops iframes into the content freely, so the usage table cannot
     * be maintained a click at a time — it is rebuilt from the saved HTML, which
     * is the only thing that actually knows what the post ended up embedding.
     */
    public function syncFromHtml(?string $html, Model $usable, string $field = 'body'): void
    {
        $this->syncMany($this->videoIdsInHtml($html), $usable, $field);
    }

    /**
     * The library ids of every video embedded in a body of HTML.
     *
     * Matches on the provider and the video id rather than the whole URL, so a row
     * still counts as used when the player URL changes shape — the src is rebuilt
     * from the enum on every render, and it has changed once already.
     *
     * @return array<int, int>
     */
    public function videoIdsInHtml(?string $html): array
    {
        if (blank($html)) {
            return [];
        }

        preg_match_all('/<iframe\b[^>]*\ssrc\s*=\s*"([^"]+)"/i', (string) $html, $matches);

        $pairs = collect($matches[1] ?? [])
            ->map(fn (string $src) => VideoProviderEnum::resolve(html_entity_decode($src, ENT_QUOTES)))
            ->filter()
            ->values();

        if ($pairs->isEmpty()) {
            return [];
        }

        return Video::query()
            ->where(function (Builder $query) use ($pairs): void {
                foreach ($pairs as $pair) {
                    $query->orWhere(fn (Builder $inner) => $inner
                        ->where('provider', $pair['provider'])
                        ->where('video_id', $pair['id']));
                }
            })
            ->orderBy('id')
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    // |||||||||||||||||||||||||||||||||||||||||||||||||||||||||||
    // QUERIES

    /**
     * The picker's query: what this account may choose from.
     */
    public function libraryQuery(User $user, ?VideoFolder $folder = null, ?string $search = null, string $sort = 'newest', ?User $owner = null): Builder
    {
        return Video::query()
            ->active()
            ->visibleTo($user, $owner)
            ->when($folder, fn (Builder $query) => $query->where('video_folder_id', $folder->id))
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
            'longest' => 'Longest first',
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
        // A member's library is theirs alone, whatever the form asked for — the
        // same rule as the image library, for the same reason. A row owned by
        // nobody is a shared platform folder and is left alone.
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
            // Nulls last, so the rows nobody typed a duration for do not head a
            // list sorted by duration.
            'longest' => $query->orderByRaw('duration is null')->orderByDesc('duration')->orderByDesc('id'),
            default => $query->newestFirst(),
        };
    }
}
