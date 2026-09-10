<?php

namespace App\Traits;

use App\Models\Video;
use App\Services\VideoLibraryService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Livewire\Attributes\On;

/**
 * The other end of the video picker: everything a screen needs to *hold* videos.
 *
 * WithVideoLibrary is the picker's own behaviour — browsing, folders, adding.
 * This is what the screen that opened it does with the answer. A screen names its
 * slots, drops a slot control per video, and the trait handles opening the picker
 * for the right slot, taking the choice, and writing the usage rows on save.
 *
 * The image sibling of this trait is WithImagePicker, and the two are deliberately
 * identical in shape — a screen that holds both a cover image and a trailer takes
 * both traits and reads the same way twice.
 *
 * A screen declares what it holds:
 *
 *     protected function videoSlots(): array
 *     {
 *         return [
 *             'trailer' => ['multiple' => false, 'property' => 'video_id'],
 *             'playlist' => ['multiple' => true, 'max' => 8],
 *         ];
 *     }
 *
 * `property` names a component property the slot keeps in step, for a table that
 * carries its own video column. A slot without it lives only in video_usages,
 * which is what a playlist on a table with no video column needs: any number of
 * videos, no migration.
 *
 * Then, in the page:
 *
 *     $this->loadVideoSlots($record);     // in mount(), for an existing record
 *     ...$this->videoPickerRules(),       // in rules()
 *     $this->syncVideoSlots($record);     // after save(), once it has an id
 *
 * and in the markup:
 *
 *     <x-form.video-slot name="trailer" label="Trailer" :videos="$this->slotVideos('trailer')" />
 *     <livewire:lv.video-picker />
 *
 * Requires WithFormResponseMessage on the using component.
 */
trait WithVideoPicker
{
    /**
     * The chosen videos, keyed by slot. Ids only — the rows are read back when
     * something actually needs to draw them.
     *
     * @var array<string, array<int, int>>
     */
    public array $video_slots = [];

    /**
     * Which slot is waiting on the picker.
     *
     * The picker echoes the slot back with its answer, so this is only what the
     * screen shows as busy; the routing itself never trusts it.
     */
    public ?string $picking_video_slot = null;

    /**
     * Slot name to loaded rows, for the length of one request. Blade asks for a
     * slot's videos once per control and again per preview; this stops each ask
     * being a query.
     *
     * @var array<string, Collection<int, Video>>
     */
    private array $slotVideoCache = [];

    /**
     * What this screen holds. Override it — an empty list means the screen took
     * the trait and never said what for.
     *
     * @return array<string, array{multiple?: bool, max?: int|null, property?: string|null}>
     */
    protected function videoSlots(): array
    {
        return [];
    }

    // ||||||||||||||||||||||||||||||||||||||||||||||||
    // READS

    /**
     * @return array<int, int>
     */
    public function videoIds(string $slot): array
    {
        return array_values($this->video_slots[$slot] ?? []);
    }

    /**
     * The one video in a single-pick slot.
     */
    public function videoId(string $slot): ?int
    {
        return $this->videoIds($slot)[0] ?? null;
    }

    /**
     * The rows behind a slot, in the order they were chosen.
     *
     * whereKey does not honour the order of the ids it is given, so the ids do the
     * sorting — a playlist has to come back in the order somebody arranged it.
     *
     * @return Collection<int, Video>
     */
    public function slotVideos(string $slot): Collection
    {
        if (isset($this->slotVideoCache[$slot])) {
            return $this->slotVideoCache[$slot];
        }

        $ids = $this->videoIds($slot);

        if (blank($ids)) {
            return $this->slotVideoCache[$slot] = collect();
        }

        return $this->slotVideoCache[$slot] = Video::query()
            ->whereKey($ids)
            ->get()
            ->sortBy(fn (Video $video) => array_search($video->id, $ids, true))
            ->values();
    }

    /**
     * The first video of a slot, which is all a single-pick slot ever has.
     */
    public function slotVideo(string $slot): ?Video
    {
        return $this->slotVideos($slot)->first();
    }

    /**
     * How a slot was declared, filled in with the defaults.
     *
     * A slot name arrives from the browser on every click, so an undeclared one is
     * refused here rather than quietly creating itself.
     *
     * @return array{multiple: bool, max: int|null, property: string|null}
     */
    public function videoSlot(string $slot): array
    {
        $config = $this->videoSlots()[$slot] ?? null;

        abort_unless($config !== null, 404);

        return [
            'multiple' => (bool) ($config['multiple'] ?? false),
            'max' => isset($config['max']) ? (int) $config['max'] : null,
            'property' => $config['property'] ?? null,
        ];
    }

    // ||||||||||||||||||||||||||||||||||||||||||||||||
    // PICKING

    /**
     * Open the picker for one slot.
     *
     * What is already in the slot goes with it, so reopening a playlist shows the
     * ones already chosen ticked rather than starting from nothing.
     */
    public function chooseVideo(string $slot): void
    {
        $config = $this->videoSlot($slot);

        $this->picking_video_slot = $slot;

        $this->dispatch(
            'open-video-picker',
            slot: $slot,
            multiple: $config['multiple'],
            max: $config['max'],
            selected: $this->videoIds($slot),
        );
    }

    /**
     * The picker's answer.
     *
     * It announces to everything listening, so the slot it names is what decides
     * whether this screen is being spoken to. A picker opened by the tiptap editor
     * carries no slot and is none of this trait's business.
     *
     * @param  array<int, int>  $ids
     * @param  array<int, string>  $urls
     */
    #[On('videosSelected')]
    public function whenVideosSelected(array $ids, array $urls = [], ?string $slot = null): void
    {
        if ($slot === null || ! \array_key_exists($slot, $this->videoSlots())) {
            return;
        }

        $this->setVideos($slot, $ids);

        $this->picking_video_slot = null;
    }

    public function removeVideo(string $slot, int $videoId): void
    {
        $this->setVideos($slot, array_diff($this->videoIds($slot), [$videoId]));
    }

    public function clearVideos(string $slot): void
    {
        $this->setVideos($slot, []);
    }

    /**
     * Put ids into a slot, honouring what the slot said it would take.
     *
     * The ceiling is applied here rather than trusted from the picker: the picker
     * enforces it while somebody is choosing, but the ids arrive over the wire and
     * a slot that says one video must never end up holding four.
     *
     * @param  array<int, int|string>  $ids
     */
    protected function setVideos(string $slot, array $ids): void
    {
        $config = $this->videoSlot($slot);

        $ids = collect($ids)
            ->map(fn ($id) => (int) $id)
            ->filter()
            ->unique()
            ->values();

        $ids = $config['multiple']
            ? ($config['max'] ? $ids->take($config['max']) : $ids)
            : $ids->take(1);

        $this->video_slots[$slot] = $ids->values()->all();

        // A slot backed by a column keeps that column in step, so the page's own
        // fill(), rules() and save() go on reading the property they always did.
        if ($config['property']) {
            $this->{$config['property']} = $this->videoId($slot);
        }

        unset($this->slotVideoCache[$slot]);
    }

    // ||||||||||||||||||||||||||||||||||||||||||||||||
    // THE RECORD

    /**
     * Fill every slot from the record being edited.
     */
    protected function loadVideoSlots(Model $record): void
    {
        $service = app(VideoLibraryService::class);

        foreach ($this->videoSlots() as $slot => $config) {
            $ids = $service->usedVideoIds($record, $slot);

            // A column-backed slot falls back to its column. A record written
            // before the slot existed has the video but no usage row, and it
            // should still show up rather than look empty and be lost on save.
            $property = $config['property'] ?? null;

            if (blank($ids) && $property && filled($this->{$property})) {
                $ids = [(int) $this->{$property}];
            }

            $this->setVideos($slot, $ids);
        }
    }

    /**
     * Write every slot back as usage rows. Runs after the record's own save(),
     * because a new one has no id to attach anything to before that.
     *
     * The usage rows are what stop somebody deleting a video that is on a
     * published page, so a screen that skips this leaves its videos deletable.
     */
    protected function syncVideoSlots(Model $record): void
    {
        $service = app(VideoLibraryService::class);

        foreach (array_keys($this->videoSlots()) as $slot) {
            $service->syncMany($this->videoIds($slot), $record, $slot);
        }
    }

    /**
     * Spread into the host page's own rules().
     *
     * Existence is checked but visibility is not: the picker only ever offers what
     * the account may see, and a video whose visibility is tightened after it was
     * chosen must not start failing the form it is already on.
     *
     * @return array<string, array<int, mixed>>
     */
    protected function videoPickerRules(): array
    {
        return [
            'video_slots' => ['array'],
            'video_slots.*' => ['array'],
            'video_slots.*.*' => ['integer', Rule::exists('videos', 'id')],
        ];
    }
}
