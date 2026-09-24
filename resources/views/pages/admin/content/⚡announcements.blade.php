<?php

use App\Enums\ActivityActionEnum;
use App\Enums\AnnouncementLayoutEnum;
use App\Enums\GateAccessEnum;
use App\Enums\StatusDefault;
use App\Enums\StatusYes;
use App\Models\Announcement;
use App\Services\ActivityLogService;
use App\Services\AnnouncementService;
use App\Services\ImageLibraryService;
use App\Traits\WithDataTable;
use App\Traits\WithImagePicker;
use Flux\Flux;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Livewire\WithPagination;

new class extends Component
{
    use WithDataTable, WithImagePicker, WithPagination;

    public ?Announcement $announcement = null;

    public ?string $title = null;

    public ?string $body = null;

    /**
     * What sits where the picture goes: 'image' or 'icon'. Form state only — the
     * row carries whichever one was filled in, and save() clears the other.
     */
    public string $media = 'image';

    /** The picture, kept in step with the 'image' slot by WithImagePicker. */
    public ?int $image_id = null;

    public ?string $icon = null;

    public ?string $link_url = null;

    public ?string $link_label = null;

    public bool $show_newsletter = true;

    public string $layout = 'stacked';

    /** The running window, as the date field's YYYY-MM-DD strings. Empty is open-ended. */
    public string $starts_on = '';

    public string $ends_on = '';

    public bool $status = true;

    /** The announcement queued for deletion, held while the dialog asks. */
    public ?int $deleteId = null;

    public function mount(): void
    {
        kSetSiteTitle('content', 'announcements');
        $this->setPageGate('content.announcements');
    }

    protected function imageSlots(): array
    {
        return [
            'image' => ['multiple' => false, 'property' => 'image_id'],
        ];
    }

    /**
     * The listing as a table, for WithDataTable.
     */
    protected function tableColumns(): array
    {
        return [
            'title' => $this->columnMaker('Announcement', locked: true, sortable: true),
            'show_newsletter' => $this->columnMaker('Newsletter form'),
            'starts_at' => $this->columnMaker('Runs', sortable: true),
            'status' => $this->columnMaker('Status', sortable: true),
        ];
    }

    protected function tableQuery(): Builder
    {
        return Announcement::query()->with('image');
    }

    protected function tableSubject(): string
    {
        return 'announcements';
    }

    /**
     * @return iterable<int, \Illuminate\Database\Eloquent\Model>
     */
    protected function tableRows(): iterable
    {
        return $this->announcements;
    }

    #[Computed]
    public function announcements()
    {
        return $this->applySort($this->tableQuery(), 'id', 'desc')->paginate($this->tablePerPage());
    }

    /**
     * The one the public pages are showing right now, so the listing can say which.
     */
    #[Computed]
    public function currentId(): ?int
    {
        return app(AnnouncementService::class)->current()?->id;
    }

    public function create(): void
    {
        $this->checkGate(GateAccessEnum::CREATE);

        $this->resetForm();

        Flux::modal('announcementModal')->show();
    }

    public function edit(Announcement $announcement): void
    {
        $this->checkGate(GateAccessEnum::MODIFY);

        $this->resetForm();

        $this->announcement = $announcement;
        $this->title = $announcement->title;
        $this->body = $announcement->body;
        $this->media = $announcement->showsIcon() ? 'icon' : 'image';
        $this->icon = $announcement->icon;
        $this->link_url = $announcement->link_url;
        $this->link_label = $announcement->link_label;
        $this->show_newsletter = $announcement->showsNewsletter();
        $this->layout = $announcement->layout->value;
        $this->starts_on = (string) $announcement->starts_at?->toDateString();
        // Stored as the moment it stops, which is midnight after the last day.
        $this->ends_on = (string) $announcement->ends_at?->subDay()->toDateString();
        $this->status = $announcement->status->isActive();

        $this->loadImageSlots($announcement);

        Flux::modal('announcementModal')->show();
    }

    protected function rules(): array
    {
        return [
            'title' => [Rule::requiredIf(fn () => $this->media === 'icon' && ! $this->body), 'nullable', 'string', 'max:255'],
            'body' => ['nullable', 'string', 'max:1000'],
            'media' => ['required', Rule::in(['image', 'icon'])],
            // A popup needs something in it. Only the image is required outright,
            // unless there is copy to carry the message instead.
            'image_id' => [Rule::requiredIf(fn () => $this->media === 'image' && ! $this->title && ! $this->body), 'nullable', 'integer'],
            // An icon is decoration, not a message, so it never stands in for the copy.
            'icon' => [Rule::requiredIf(fn () => $this->media === 'icon'), 'nullable', 'string', Rule::in(kFluxIcons())],
            'link_url' => ['nullable', 'url:http,https', 'max:500'],
            'link_label' => ['nullable', 'string', 'max:60'],
            'show_newsletter' => ['boolean'],
            'layout' => ['required', Rule::enum(AnnouncementLayoutEnum::class)],
            'starts_on' => ['nullable', 'date'],
            'ends_on' => ['nullable', 'date', 'after_or_equal:starts_on'],
            'status' => ['boolean'],
            ...$this->imagePickerRules(),
        ];
    }

    protected function messages(): array
    {
        return [
            'image_id.required' => 'Add a picture, or write a title or some copy for the popup to show.',
            'icon.required' => 'Choose an icon, or switch back to a picture.',
            'title.required' => 'An icon needs a title or some copy beside it.',
        ];
    }

    public function save(): bool
    {
        $this->checkGate($this->announcement ? GateAccessEnum::MODIFY : GateAccessEnum::CREATE);

        $this->validate();

        $action = ActivityActionEnum::ANNOUNCEMENT_UPDATE;

        if (! $this->announcement) {
            $this->announcement = Announcement::make(['user_id' => auth()->id()]);
            $action = ActivityActionEnum::ANNOUNCEMENT_CREATE;
        }

        $this->announcement->title = $this->title ?: null;
        $this->announcement->body = $this->body ?: null;
        // One or the other, so switching the choice does not leave the old one
        // quietly attached — or its image usage row holding the picture.
        if ($this->media === 'icon') {
            $this->clearImages('image');
        }

        $this->announcement->image_id = $this->image_id;
        $this->announcement->icon = $this->media === 'icon' ? $this->icon : null;
        $this->announcement->link_url = $this->link_url ?: null;
        $this->announcement->link_label = $this->link_url ? ($this->link_label ?: null) : null;
        $this->announcement->show_newsletter = StatusYes::tryFrom((int) $this->show_newsletter);
        $this->announcement->layout = AnnouncementLayoutEnum::from($this->layout);
        $this->announcement->starts_at = $this->starts_on ? Carbon::parse($this->starts_on)->startOfDay() : null;
        // The whole of the last day counts, so the window closes at the midnight after it.
        $this->announcement->ends_at = $this->ends_on ? Carbon::parse($this->ends_on)->addDay()->startOfDay() : null;
        $this->announcement->status = StatusDefault::tryFrom((int) $this->status);

        $serviceInstance = app(ActivityLogService::class);
        $affectedColumns = $serviceInstance->affectedColumns($this->announcement);

        // A change of picture alone leaves the row clean, so the usage rows are
        // compared as well before deciding there was nothing to save.
        $imageChanged = $this->announcement->exists
            && $this->announcement->getOriginal('image_id') !== $this->image_id;

        $this->respondPrimary(if: $this->announcement->exists && $this->announcement->isClean() && ! $imageChanged);

        $this->announcement->save();

        $this->syncImageSlots($this->announcement);

        $serviceInstance->logActivity(
            $action,
            ' announcement: '.($this->announcement->title ?: "#{$this->announcement->id}"),
            $affectedColumns,
            model: $this->announcement,
        );

        Flux::modal('announcementModal')->close();
        $this->resetForm();
        unset($this->announcements, $this->currentId);

        return $this->respondSuccess('The announcement has been saved.');
    }

    public function confirmDelete(int $announcementId): void
    {
        $this->checkGate(GateAccessEnum::FULL);

        $this->deleteId = $announcementId;

        Flux::modal('deleteAnnouncementModal')->show();
    }

    public function delete(): bool
    {
        $this->checkGate(GateAccessEnum::FULL);

        $announcement = Announcement::query()->whereKey($this->deleteId)->first();

        abort_unless((bool) $announcement, 404);

        $description = ' announcement: '.($announcement->title ?: "#{$announcement->id}");

        // Releases the picture's usage row with it, so the image can be deleted
        // from the library once nothing else is using it.
        app(ImageLibraryService::class)->detach($announcement, 'image');

        $announcement->delete();

        app(ActivityLogService::class)->logActivity(ActivityActionEnum::ANNOUNCEMENT_DELETE, $description);

        Flux::modal('deleteAnnouncementModal')->close();
        $this->reset('deleteId');
        unset($this->announcements, $this->currentId);

        return $this->respondSuccess('The announcement has been deleted.');
    }

    private function resetForm(): void
    {
        $this->reset(
            'announcement', 'title', 'body', 'media', 'image_id', 'icon', 'link_url', 'link_label',
            'show_newsletter', 'layout', 'starts_on', 'ends_on', 'status', 'image_slots'
        );
        $this->resetValidation();
    }
};
?>

<div class="space-y-6">
    <flux:card class="space-y-5">
        <div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
            <div>
                <flux:heading level="2" size="lg">Announcements</flux:heading>
                <flux:text class="mt-1">
                    The popup on the public pages — a promotion, a notice, or the newsletter with a
                    picture. Only the newest one that is running is shown. With none running, the
                    popup falls back to the plain newsletter sign-up.
                </flux:text>
            </div>

            <x-dashboard.gate.button :gate="$pageGate" :level="$gateCreate" variant="primary" icon="plus" wire:click="create">
                New announcement
            </x-dashboard.gate.button>
        </div>

        <flux:table :paginate="$this->announcements">
            <x-table.columns
                :columns="$this->tableColumnList"
                :sort="$sortColumn"
                :direction="$sortDirection"
                actions
                actions-label=""
            />

            <x-table.rows :columns="$this->tableColumnList">
                @forelse ($this->announcements as $item)
                    <flux:table.row wire:key="announcement-{{ $item->id }}">
                        <x-table.cell column="title">
                            <div class="flex items-center gap-3">
                                <div class="size-12 shrink-0 overflow-hidden rounded-lg bg-slate-100 dark:bg-slate-800">
                                    @if ($item->image)
                                        <img src="{{ $item->image->url() }}" alt="" class="size-full object-cover" />
                                    @elseif ($item->showsIcon())
                                        <div class="grid size-full place-items-center bg-lime-100 dark:bg-lime-400/10">
                                            <flux:icon :name="$item->icon" class="size-6 text-lime-700 dark:text-lime-300" />
                                        </div>
                                    @else
                                        <div class="grid size-full place-items-center">
                                            <flux:icon name="megaphone" class="size-5 text-slate-400" />
                                        </div>
                                    @endif
                                </div>
                                <div class="min-w-0">
                                    <p class="truncate font-medium text-slate-950 dark:text-white">{{ $item->title ?: 'Image only' }}</p>
                                    @if ($item->link_url)
                                        <p class="truncate text-xs text-slate-500 dark:text-slate-400">{{ $item->link_url }}</p>
                                    @endif
                                </div>
                                @if ($item->id === $this->currentId)
                                    <flux:badge size="sm" color="lime" inset="top bottom">Showing now</flux:badge>
                                @endif
                            </div>
                        </x-table.cell>

                        <x-table.cell column="show_newsletter"><x-util.e-badge :enum="$item->show_newsletter" /></x-table.cell>

                        <x-table.cell column="starts_at" class="text-sm">
                            {{ $item->starts_at ? kDatetimeConverter($item->starts_at, auth()->user(), dateFormat: true) : 'Now' }}
                            –
                            {{ $item->ends_at ? kDatetimeConverter($item->ends_at->subDay(), auth()->user(), dateFormat: true) : 'until replaced' }}
                        </x-table.cell>

                        <x-table.cell column="status"><x-util.e-badge :enum="$item->status" /></x-table.cell>

                        <x-table.cell class="flex justify-end gap-1">
                            <x-dashboard.gate.button :gate="$pageGate" :level="$gateModify" size="sm" variant="ghost" icon="pencil-square" wire:click="edit({{ $item->id }})" />
                            <x-dashboard.gate.button :gate="$pageGate" :level="$gateFull" size="sm" variant="ghost" icon="trash" wire:click="confirmDelete({{ $item->id }})" />
                        </x-table.cell>
                    </flux:table.row>
                @empty
                    <x-table.empty
                        :columns="$this->tableColumnList"
                        actions
                        label="No announcements yet"
                        icon="megaphone"
                        text="Until there is one, the popup is the plain newsletter sign-up."
                    />
                @endforelse
            </x-table.rows>
        </flux:table>
    </flux:card>

    <flux:modal name="announcementModal" class="max-w-2xl">
        <form wire:submit="save" class="space-y-5">
            <flux:heading size="lg">{{ $announcement ? 'Edit announcement' : 'New announcement' }}</flux:heading>

            <flux:radio.group wire:model.live="media" label="Beside the copy" variant="segmented">
                <flux:radio value="image" label="Picture" icon="photo" />
                <flux:radio value="icon" label="Icon" icon="sparkles" />
            </flux:radio.group>

            @if ($media === 'icon')
                <x-form.icon-picker wire:model="icon" label="Icon" :icon="$icon" :clearable="false" />
            @else
                <x-form.image-slot
                    name="image"
                    label="Picture"
                    :images="$this->slotImages('image')"
                    error="image_id"
                />
            @endif

            <div class="grid gap-4 sm:grid-cols-2">
                <flux:input wire:model="link_url" label="Link" placeholder="https://…" description="Where clicking the picture goes. Optional." />
                <flux:input wire:model="link_label" label="Button text" placeholder="Shop the sale" description="Adds a button under the copy. Needs a link." />
            </div>

            <flux:input wire:model="title" label="Title" placeholder="Get 25% off your first order" />
            <flux:textarea wire:model="body" label="Copy" rows="2" placeholder="A sentence or two under the title." />

            <flux:switch
                wire:model="show_newsletter"
                label="Include the newsletter form"
                description="Off, with no title or copy, shows the picture on its own."
            />

            <flux:radio.group wire:model="layout" label="Layout" variant="cards" class="grid gap-3 sm:grid-cols-2">
                @foreach (AnnouncementLayoutEnum::cases() as $case)
                    <flux:radio :value="$case->value" :label="$case->label()" :description="$case->description()" />
                @endforeach
            </flux:radio.group>

            <x-form.date-field
                mode="range"
                wire:model="starts_on"
                end-model="ends_on"
                label="Runs between"
                description="Leave empty to start now and run until a newer one replaces it."
            />

            <div class="flex items-center justify-between gap-3">
                <flux:switch wire:model="status" label="Active" />

                <div class="flex gap-3">
                    <flux:modal.close>
                        <flux:button variant="ghost" type="button">Cancel</flux:button>
                    </flux:modal.close>
                    <flux:button type="submit" variant="primary">Save</flux:button>
                </div>
            </div>
        </form>
    </flux:modal>

    <x-dashboard.confirm-modal
        name="deleteAnnouncementModal"
        title="Delete this announcement?"
        icon="trash"
        confirm="Delete it"
        cancel="Keep it"
        wire:click="delete"
    >
        The picture stays in the library. To take it off the site for a while, switch it off
        instead.
    </x-dashboard.confirm-modal>

    <livewire:livewire.library.image-picker />
</div>
