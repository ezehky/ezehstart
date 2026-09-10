<?php

namespace App\Traits;

use App\Models\Image;
use App\Services\ImageLibraryService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Livewire\Attributes\On;

/**
 * The other end of the picker: everything a screen needs to *hold* images.
 *
 * WithImageLibrary is the picker's own behaviour — browsing, folders, uploading.
 * This is what the screen that opened it does with the answer. A screen names its
 * slots, drops a slot control per image, and the trait handles opening the picker
 * for the right slot, taking the choice, and writing the usage rows on save.
 *
 * A screen declares what it holds:
 *
 *     protected function imageSlots(): array
 *     {
 *         return [
 *             'cover' => ['multiple' => false, 'property' => 'image_id'],
 *             'gallery' => ['multiple' => true, 'max' => 6],
 *         ];
 *     }
 *
 * `property` names a component property the slot keeps in step, for a table that
 * carries its own image column — posts.image_id is one. A slot without it lives
 * only in image_usages, which is what a gallery on a table with no image column
 * needs: any number of images, no migration.
 *
 * Then, in the page:
 *
 *     $this->loadImageSlots($record);     // in mount(), for an existing record
 *     ...$this->imagePickerRules(),       // in rules()
 *     $this->syncImageSlots($record);     // after save(), once it has an id
 *
 * and in the markup:
 *
 *     <x-form.image-slot name="cover" label="Cover image" :images="$this->slotImages('cover')" />
 *     <livewire:lv.image-picker />
 *
 * Requires WithFormResponseMessage on the using component.
 */
trait WithImagePicker
{
    /**
     * The chosen images, keyed by slot. Ids only — the rows are read back when
     * something actually needs to draw them.
     *
     * @var array<string, array<int, int>>
     */
    public array $image_slots = [];

    /**
     * Which slot is waiting on the picker.
     *
     * The picker echoes the slot back with its answer, so this is only what the
     * screen shows as busy; the routing itself never trusts it. Two slots on one
     * screen used to need a flag each, and choosing a cover quietly replaced the
     * gallery whenever the flag was forgotten.
     */
    public ?string $picking_slot = null;

    /**
     * Slot name to loaded rows, for the length of one request. Blade asks for a
     * slot's images once per control and again per preview; this stops each ask
     * being a query.
     *
     * @var array<string, Collection<int, Image>>
     */
    private array $slotImageCache = [];

    /**
     * What this screen holds. Override it — an empty list means the screen took
     * the trait and never said what for.
     *
     * @return array<string, array{multiple?: bool, max?: int|null, property?: string|null}>
     */
    protected function imageSlots(): array
    {
        return [];
    }

    // ||||||||||||||||||||||||||||||||||||||||||||||||
    // READS

    /**
     * @return array<int, int>
     */
    public function imageIds(string $slot): array
    {
        return array_values($this->image_slots[$slot] ?? []);
    }

    /**
     * The one image in a single-pick slot.
     */
    public function imageId(string $slot): ?int
    {
        return $this->imageIds($slot)[0] ?? null;
    }

    /**
     * The rows behind a slot, in the order they were chosen.
     *
     * whereKey does not honour the order of the ids it is given, so the ids do the
     * sorting — a gallery has to come back in the order somebody arranged it.
     *
     * @return Collection<int, Image>
     */
    public function slotImages(string $slot): Collection
    {
        if (isset($this->slotImageCache[$slot])) {
            return $this->slotImageCache[$slot];
        }

        $ids = $this->imageIds($slot);

        if (blank($ids)) {
            return $this->slotImageCache[$slot] = collect();
        }

        return $this->slotImageCache[$slot] = Image::query()
            ->whereKey($ids)
            ->get()
            ->sortBy(fn (Image $image) => array_search($image->id, $ids, true))
            ->values();
    }

    /**
     * The first image of a slot, which is all a single-pick slot ever has.
     */
    public function slotImage(string $slot): ?Image
    {
        return $this->slotImages($slot)->first();
    }

    /**
     * How a slot was declared, filled in with the defaults.
     *
     * A slot name arrives from the browser on every click, so an undeclared one is
     * refused here rather than quietly creating itself.
     *
     * @return array{multiple: bool, max: int|null, property: string|null}
     */
    public function imageSlot(string $slot): array
    {
        $config = $this->imageSlots()[$slot] ?? null;

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
     * What is already in the slot goes with it, so reopening a gallery shows the
     * six already chosen ticked rather than starting from nothing.
     */
    public function chooseImage(string $slot): void
    {
        $config = $this->imageSlot($slot);

        $this->picking_slot = $slot;

        $this->dispatch(
            'open-image-picker',
            slot: $slot,
            multiple: $config['multiple'],
            max: $config['max'],
            selected: $this->imageIds($slot),
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
    #[On('imagesSelected')]
    public function whenImagesSelected(array $ids, array $urls = [], ?string $slot = null): void
    {
        if ($slot === null || ! \array_key_exists($slot, $this->imageSlots())) {
            return;
        }

        $this->setImages($slot, $ids);

        $this->picking_slot = null;
    }

    public function removeImage(string $slot, int $imageId): void
    {
        $this->setImages($slot, array_diff($this->imageIds($slot), [$imageId]));
    }

    public function clearImages(string $slot): void
    {
        $this->setImages($slot, []);
    }

    /**
     * Put ids into a slot, honouring what the slot said it would take.
     *
     * The ceiling is applied here rather than trusted from the picker: the picker
     * enforces it while somebody is choosing, but the ids arrive over the wire and
     * a slot that says one image must never end up holding four.
     *
     * @param  array<int, int|string>  $ids
     */
    protected function setImages(string $slot, array $ids): void
    {
        $config = $this->imageSlot($slot);

        $ids = collect($ids)
            ->map(fn ($id) => (int) $id)
            ->filter()
            ->unique()
            ->values();

        $ids = $config['multiple']
            ? ($config['max'] ? $ids->take($config['max']) : $ids)
            : $ids->take(1);

        $this->image_slots[$slot] = $ids->values()->all();

        // A slot backed by a column keeps that column in step, so the page's own
        // fill(), rules() and save() go on reading the property they always did.
        if ($config['property']) {
            $this->{$config['property']} = $this->imageId($slot);
        }

        unset($this->slotImageCache[$slot]);
    }

    // ||||||||||||||||||||||||||||||||||||||||||||||||
    // THE RECORD

    /**
     * Fill every slot from the record being edited.
     */
    protected function loadImageSlots(Model $record): void
    {
        $service = app(ImageLibraryService::class);

        foreach ($this->imageSlots() as $slot => $config) {
            $ids = $service->usedImageIds($record, $slot);

            // A column-backed slot falls back to its column. A record written
            // before the slot existed has the image but no usage row, and it
            // should still show up rather than look empty and be lost on save.
            $property = $config['property'] ?? null;

            if (blank($ids) && $property && filled($this->{$property})) {
                $ids = [(int) $this->{$property}];
            }

            $this->setImages($slot, $ids);
        }
    }

    /**
     * Write every slot back as usage rows. Runs after the record's own save(),
     * because a new one has no id to attach anything to before that.
     *
     * The usage rows are what stop somebody deleting an image that is on a
     * published page, so a screen that skips this leaves its images deletable.
     */
    protected function syncImageSlots(Model $record): void
    {
        $service = app(ImageLibraryService::class);

        foreach (array_keys($this->imageSlots()) as $slot) {
            $service->syncMany($this->imageIds($slot), $record, $slot);
        }
    }

    /**
     * Spread into the host page's own rules().
     *
     * Existence is checked but visibility is not: the picker only ever offers what
     * the account may see, and an image whose visibility is tightened after it was
     * chosen must not start failing the form it is already on.
     *
     * @return array<string, array<int, mixed>>
     */
    protected function imagePickerRules(): array
    {
        return [
            'image_slots' => ['array'],
            'image_slots.*' => ['array'],
            'image_slots.*.*' => ['integer', Rule::exists('images', 'id')],
        ];
    }
}
