<?php

use App\Enums\GateAccessEnum;
use App\Enums\MediaVisibilityEnum;
use App\Enums\UserTypeEnum;
use App\Models\ImageFolder;
use App\Models\User;
use App\Rules\ImageRule;
use App\Services\ImageLibraryService;
use App\Traits\WithFormResponseMessage;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * Folder import: one or more folders from somebody's computer, brought into the
 * library with their subfolders, under one set of settings chosen beforehand.
 *
 * The browser walks the folders and sends the images a few at a time — PHP takes
 * twenty files per request at most, and storing hundreds of optimised images in
 * one request would outlive its time limit. Each batch arrives with the path every
 * file had on disk, and that path is all the server is told about folders: it
 * rebuilds the tree from it, reusing a folder already there under the same name.
 *
 * The settings are fixed by startImport() and held locked, so a batch halfway
 * through cannot be steered into another folder or another audience.
 *
 * Administrators only. The library hides the tab from everybody else, and every
 * method here checks again — a hidden tab is a courtesy, not a boundary.
 */
new class extends Component
{
    use WithFileUploads, WithFormResponseMessage;

    /** Batches never carry more than this, well under PHP's max_file_uploads. */
    private const BATCH = 10;

    /** Deep enough for any real photo archive, shallow enough to stop a crafted path. */
    private const MAX_DEPTH = 8;

    private const LIBRARY_GATE = 'content.image-library';

    public User $user;

    /** The folder being looked at when the tab opened; where the import lands by default. */
    public ?int $folder = null;

    // The settings, chosen once for the whole import
    public ?int $parent_id = null;

    public string $visibility = 'private';

    public ?string $visible_to_type = null;

    public bool $shared = false;

    public bool $keep_structure = true;

    /**
     * The batch on its way in. Replaced on every batch, never appended to.
     *
     * @var array<int, mixed>
     */
    public array $importFiles = [];

    /**
     * The settings as startImport() accepted them.
     *
     * @var array{parent_id?: int|null, visibility?: string, visible_to_type?: string|null, shared?: bool, keep_structure?: bool}
     */
    #[Locked]
    public array $plan = [];

    /**
     * Folder ids already resolved this import, by path, so a batch of ten files
     * from one folder asks the database once.
     *
     * @var array<string, int>
     */
    #[Locked]
    public array $folderMap = [];

    /** @var array<int, int> */
    #[Locked]
    public array $storedIds = [];

    /**
     * The folders made (or reused) at the top of the import — one per folder
     * somebody chose. When there is exactly one, the library opens it.
     *
     * @var array<int, int>
     */
    #[Locked]
    public array $topFolderIds = [];

    #[Locked]
    public int $skipped = 0;

    #[Locked]
    public int $rejected = 0;

    public function mount(): void
    {
        $this->user = auth()->user();

        $this->guard();

        $this->parent_id = $this->folder;
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
    public function visibilityOptions(): array
    {
        return MediaVisibilityEnum::forSelect();
    }

    protected function rules(): array
    {
        return [
            'parent_id' => ['nullable', 'integer', Rule::exists('image_folders', 'id')],
            'visibility' => ['required', Rule::enum(MediaVisibilityEnum::class)],
            'visible_to_type' => ['nullable', Rule::enum(UserTypeEnum::class)],
            'shared' => ['boolean'],
            'keep_structure' => ['boolean'],
        ];
    }

    /**
     * Check the settings and fix them for the run. Returns true only when the
     * browser may start sending — anything else has already been said in a toast
     * or under a field.
     */
    public function startImport(): bool
    {
        $this->guard();

        $this->validate();

        $visibility = MediaVisibilityEnum::from($this->visibility);

        $this->respondError(
            'Choose which role should be able to see these images.',
            $visibility->needsType() && ! $this->visible_to_type,
            field: 'visible_to_type'
        );

        // A folder they cannot browse is a folder they cannot file into.
        $this->respondError(
            'That folder is not available to you.',
            $this->parent_id && ! ImageFolder::query()->browsableBy($this->user)->whereKey($this->parent_id)->exists(),
            field: 'parent_id'
        );

        $this->respondError('Your upload limit is full, so nothing can be imported.', $this->remaining === 0);

        $this->plan = [
            'parent_id' => $this->parent_id,
            'visibility' => $visibility->value,
            'visible_to_type' => $visibility->needsType() ? $this->visible_to_type : null,
            'shared' => $this->shared,
            'keep_structure' => $this->keep_structure,
        ];

        $this->reset('importFiles', 'folderMap', 'storedIds', 'topFolderIds', 'skipped', 'rejected');

        return true;
    }

    /**
     * Store one batch. $paths are the files' paths on disk, in the order the
     * files were sent. Returns false once the upload limit is spent, which tells
     * the browser to stop sending.
     *
     * @param  array<int, string>  $paths
     */
    public function importChunk(array $paths): bool
    {
        $this->guard();

        abort_if($this->plan === [], 409);
        abort_unless(\count($paths) === \count($this->importFiles) && \count($paths) <= self::BATCH, 422);

        $service = app(ImageLibraryService::class);
        $visibility = MediaVisibilityEnum::from($this->plan['visibility']);
        $role = $this->plan['visible_to_type'] ? UserTypeEnum::from($this->plan['visible_to_type']) : null;
        $rule = [new ImageRule(size: $this->maxSize)];

        foreach (array_values($this->importFiles) as $index => $file) {
            // A stray document in a photo folder is left behind, not a reason to stop.
            if (Validator::make(['file' => $file], ['file' => $rule])->fails()) {
                $this->rejected++;

                continue;
            }

            $folder = $this->folderFor((string) ($paths[$index] ?? ''), $visibility, $role);
            $image = $service->store($this->user, $file, null, $folder, $visibility, $role);

            $image ? $this->storedIds[] = $image->id : $this->skipped++;
        }

        $this->reset('importFiles');
        unset($this->remaining);

        return $this->remaining !== 0;
    }

    /**
     * Say what happened and hand the result to the library. Called when the last
     * batch is in, and also when the browser gave up part-way, so whatever did
     * land is still reported.
     */
    public function finishImport(bool $interrupted = false): bool
    {
        $this->guard();

        // Already reported — a browser that retries after an error must not say it twice.
        if ($this->plan === []) {
            return false;
        }

        $stored = \count($this->storedIds);
        $folders = array_values(array_unique($this->topFolderIds));

        $this->dispatch('imagesUploaded', ids: $this->storedIds, folder: \count($folders) === 1 ? $folders[0] : null);

        $notes = array_filter([
            $this->skipped > 0 ? "{$this->skipped} skipped — your upload limit is full." : null,
            $this->rejected > 0 ? "{$this->rejected} not imported — not a JPEG, PNG or WEBP under {$this->maxSize} KB." : null,
            $interrupted ? 'The import stopped before the end; what arrived is kept.' : null,
        ]);

        $this->reset('plan', 'importFiles', 'folderMap', 'storedIds', 'topFolderIds', 'skipped', 'rejected');
        unset($this->folders, $this->remaining);

        $this->respondError(
            trim('Nothing was imported. '.implode(' ', $notes)),
            $stored === 0,
        );

        return $this->respondSuccess(trim("{$stored} image(s) imported. ".implode(' ', $notes)));
    }

    /**
     * The library folder for one file, built from the folders above it on disk.
     * A loose file dropped on its own has none, and lands where the import does.
     */
    private function folderFor(string $path, MediaVisibilityEnum $visibility, ?UserTypeEnum $role): ?ImageFolder
    {
        $segments = $this->segmentsFor($path);
        $parentId = $this->plan['parent_id'];
        $key = implode('/', $segments);

        if (isset($this->folderMap[$key])) {
            return ImageFolder::query()->find($this->folderMap[$key]);
        }

        $parent = $parentId ? ImageFolder::query()->find($parentId) : null;

        if ($segments === []) {
            return $parent;
        }

        $folder = app(ImageLibraryService::class)->folderForPath(
            $this->user,
            $segments,
            $parent,
            $this->plan['shared'],
            $visibility,
            $role,
        );

        $this->folderMap[$key] = $folder->id;

        // The top of this path is the folder somebody actually chose.
        $top = $folder;
        for ($depth = \count($segments); $depth > 1; $depth--) {
            $top = $top->parent;
        }
        $this->topFolderIds[] = $top->id;

        return $folder;
    }

    /**
     * The folder names in a path, with the file name dropped and anything that is
     * not a usable name — empty, a dot, control characters — taken out. The path
     * comes from the browser, so it is treated as text and nothing more.
     *
     * @return array<int, string>
     */
    private function segmentsFor(string $path): array
    {
        $parts = explode('/', str_replace('\\', '/', $path));

        array_pop($parts);

        $parts = collect($parts)
            ->map(fn (string $part) => mb_substr(trim((string) preg_replace('/[\x00-\x1F\x7F]/u', '', $part)), 0, 255))
            ->reject(fn (string $part) => \in_array($part, ['', '.', '..'], true))
            ->values()
            ->all();

        return \array_slice($parts, 0, $this->plan['keep_structure'] ? self::MAX_DEPTH : 1);
    }

    /**
     * Every control is hidden from somebody who is not an administrator with
     * create access; this is the boundary behind them.
     */
    private function guard(): void
    {
        abort_unless($this->user->isAdmin(), 403);

        $this->respondError(
            'You do not have upload access to the image library.',
            if: ! kGateAction(self::LIBRARY_GATE, GateAccessEnum::CREATE),
        );
    }
};
?>

<div
    x-data="folderImport({ maxSize: {{ $this->maxSize }}, batch: 10 })"
    class="space-y-5"
>
    <div>
        <flux:heading level="2" size="lg" class="font-heading font-bold">Import folders</flux:heading>
        <flux:text class="mt-1">
            Bring in whole folders, subfolders and all. Choose a folder, or drop several at once,
            then decide how they should be filed before anything is uploaded.
        </flux:text>
    </div>

    {{-- Everything in here is drawn by Alpine from files only the browser holds,
         so Livewire leaves it alone. --}}
    <div wire:ignore class="space-y-4">
        <div
            x-on:dragover.prevent="dragging = true"
            x-on:dragleave.prevent="dragging = false"
            x-on:drop.prevent="drop($event)"
            x-bind:class="dragging
                ? 'border-accent bg-accent/10'
                : 'border-slate-300 bg-slate-50 dark:border-white/15 dark:bg-white/5'"
            class="flex flex-col items-center justify-center gap-3 rounded-xl border border-dashed px-6 py-10 text-center transition"
        >
            <input x-ref="picker" type="file" class="sr-only" webkitdirectory directory multiple x-on:change="pick($event)" />

            <flux:icon.folder-open class="size-8 text-slate-300 dark:text-slate-600" />

            <p class="text-sm text-slate-600 dark:text-slate-300">
                Drop one or more folders here, or
            </p>

            <flux:button size="sm" icon="folder-plus" x-on:click="$refs.picker.click()" x-bind:disabled="running">
                Choose a folder
            </flux:button>

            <p class="text-xs text-slate-400 dark:text-slate-500">
                JPEG, PNG or WEBP up to {{ $this->maxSize }} KB each. Other files are left behind.
                @if ($this->remaining !== null)
                    · {{ $this->remaining }} left on your account
                @endif
            </p>
        </div>

        <template x-if="groups.length">
            <div class="space-y-3">
                <ul class="divide-y divide-slate-200 rounded-lg border border-slate-200 dark:divide-slate-700 dark:border-slate-700">
                    <template x-for="group in groups" x-bind:key="group.name">
                        <li class="flex items-center gap-3 px-3 py-2">
                            <flux:icon.folder class="size-5 shrink-0 text-slate-400" />

                            <div class="min-w-0 flex-1">
                                <p class="truncate text-sm font-medium text-slate-950 dark:text-white" x-text="group.label"></p>
                                <p class="text-xs text-slate-500 dark:text-slate-400" x-text="group.summary"></p>
                            </div>

                            <flux:button size="sm" variant="subtle" icon="x-mark" title="Leave this folder out" x-on:click="remove(group.name)" x-bind:disabled="running" />
                        </li>
                    </template>
                </ul>

                <p class="text-sm text-slate-600 dark:text-slate-300">
                    <span x-text="`${entries.length} image(s) ready`"></span><span x-show="ignored" x-text="` · ${ignored} other file(s) left behind`"></span>
                </p>
            </div>
        </template>

        <template x-if="running">
            <div class="space-y-1.5" aria-live="polite">
                <span class="text-sm font-semibold text-slate-600 dark:text-slate-300" x-text="`Importing ${sent} of ${total}`"></span>
                <div class="h-1.5 w-full overflow-hidden rounded-full bg-slate-200 dark:bg-white/10">
                    <div class="h-full rounded-full bg-accent transition-all" x-bind:style="`width: ${progress}%`"></div>
                </div>
            </div>
        </template>
    </div>

    {{-- One set of settings for everything in the import --}}
    <flux:card class="space-y-4">
        <flux:heading size="md">Settings for this import</flux:heading>

        <flux:select wire:model="parent_id" label="Import into">
            <flux:select.option value="">Top level</flux:select.option>
            @foreach ($this->folders as $option)
                <flux:select.option value="{{ $option['id'] }}">{{ $option['label'] }}</flux:select.option>
            @endforeach
        </flux:select>

        <flux:select wire:model.live="visibility" label="Who can see the images and folders">
            @foreach ($this->visibilityOptions as $value => $label)
                <flux:select.option value="{{ $value }}">{{ $label }}</flux:select.option>
            @endforeach
        </flux:select>

        @if ($visibility === App\Enums\MediaVisibilityEnum::TYPE->value)
            <flux:select wire:model="visible_to_type" label="Visible to role">
                <flux:select.option value="">Choose a role</flux:select.option>
                @foreach (App\Enums\UserTypeEnum::forSelect() as $value => $label)
                    <flux:select.option value="{{ $value }}">{{ $label }}</flux:select.option>
                @endforeach
            </flux:select>
            <flux:error name="visible_to_type" />
        @endif

        <flux:switch wire:model="shared" label="Shared folders" description="New folders are owned by the platform rather than by you." />

        <flux:switch
            wire:model="keep_structure"
            label="Keep subfolders"
            description="Off, every image from a chosen folder goes into one folder named after it."
        />

        <flux:text size="sm">
            A folder that already exists in that place under the same name is reused, so importing
            the same folder twice files into one tree.
        </flux:text>
    </flux:card>

    <div class="flex flex-col gap-2 sm:flex-row-reverse" wire:ignore>
        <flux:button
            variant="primary"
            icon="arrow-up-tray"
            class="w-full sm:w-auto"
            x-on:click="start()"
            x-bind:disabled="running || ! entries.length"
        >
            Import
        </flux:button>

        <flux:button variant="ghost" class="w-full sm:w-auto" x-on:click="clear()" x-bind:disabled="running">
            Clear
        </flux:button>
    </div>
</div>
