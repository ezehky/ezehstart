{{-- The video library body, shared by the full-page library and the picker that
     opens over whatever somebody is writing.

     Included rather than made a component, because @include shares the parent's
     scope: $this is the Livewire component either way, so one copy of this markup
     drives both screens and they cannot drift apart. Behaviour lives in
     App\Traits\WithVideoLibrary.

     The image sibling is library._library, and the two are deliberately alike. The one
     real difference is the second tab: a video is added by pasting a link, so this
     is a small form rather than a file queue. --}}

<div class="space-y-5">
    {{-- Tabs --}}
    <div class="flex gap-1.5">
        <flux:button
            size="sm"
            icon="film"
            variant="{{ $tab === 'library' ? 'primary' : 'ghost' }}"
            wire:click="switchTab('library')"
        >
            Library
        </flux:button>
        <x-dashboard.gate.button
            gate="content.video-library"
            level="create"
            size="sm"
            icon="link"
            variant="{{ $tab === 'add' ? 'primary' : 'ghost' }}"
            wire:click="switchTab('add')"
        >
            Add a video
        </x-dashboard.gate.button>
    </div>

    @if ($tab === 'add')
        <flux:card class="space-y-4">
            <div>
                <flux:heading size="md">Add a video</flux:heading>
                <flux:text class="mt-1">
                    @if ($this->currentFolder)
                        It will be filed in {{ $this->currentFolder->name }}.
                    @else
                        It will land in the root of your library.
                    @endif
                </flux:text>
            </div>

            <flux:input
                wire:model="video_url"
                label="Video link"
                name="video_url"
                placeholder="https://www.youtube.com/watch?v=..."
                description="Paste the link from the share button. {{ collect(App\Enums\VideoProviderEnum::cases())->map(fn ($provider) => $provider->domainHint())->implode(', ') }}."
            />

            <flux:input
                wire:model="new_title"
                label="Title"
                name="new_title"
                placeholder="What is this video?"
                description="Optional. Only ever used inside the library, so name it however you will find it."
            />

            {{-- Nothing calls out to the provider, so the video is added private and
                 shared afterwards from the edit panel. Saying so here is cheaper
                 than somebody discovering it on a published page. --}}
            <flux:text size="sm">
                A new video is private until you change it. Nothing is uploaded — the
                file stays with the provider, and removing it there removes it here.
            </flux:text>

            <div class="flex justify-end gap-3">
                <flux:button variant="ghost" wire:click="switchTab('library')">Cancel</flux:button>
                <flux:button variant="primary" wire:click="addVideo">Add video</flux:button>
            </div>
        </flux:card>
    @else
        {{-- Folders: a rail beside the grid on desktop, a strip above it on a
             phone, where a vertical rail would eat the width the tiles need. --}}
        <div class="grid gap-5 lg:grid-cols-[14rem_1fr] lg:items-start">
            <div>
                <flux:subheading class="mb-2 font-semibold">Folders</flux:subheading>

                <div class="scrollbar-hover -mx-1 flex gap-1.5 overflow-x-auto px-1 pb-2 lg:mx-0 lg:flex-col lg:gap-0.5 lg:overflow-visible lg:px-0 lg:pb-0">
                    <flux:button
                        size="sm"
                        icon="folder"
                        variant="{{ $folder === null ? 'primary' : 'ghost' }}"
                        class="shrink-0 lg:w-full lg:justify-start"
                        wire:click="selectFolder(null)"
                    >
                        All videos
                    </flux:button>

                    @foreach ($this->folders as $option)
                        <div wire:key="video-rail-{{ $option['id'] }}" class="group/folder relative shrink-0 lg:w-full">
                            <flux:button
                                size="sm"
                                icon="folder"
                                variant="{{ $folder === $option['id'] ? 'primary' : 'ghost' }}"
                                class="w-full justify-start pe-8!"
                                wire:click="selectFolder({{ $option['id'] }})"
                            >
                                {{ $option['label'] }}
                            </flux:button>

                            <flux:dropdown position="bottom" align="end" class="absolute end-0.5 top-1/2 -translate-y-1/2">
                                <flux:button
                                    icon="ellipsis-vertical"
                                    variant="subtle"
                                    size="xs"
                                    title="Folder options"
                                    class="opacity-0 transition group-hover/folder:opacity-100 focus-visible:opacity-100"
                                />

                                <flux:menu>
                                    <x-dashboard.gate.menu-item gate="content.video-library" level="modify" icon="pencil-square" wire:click="editFolder({{ $option['id'] }})">
                                        Edit folder
                                    </x-dashboard.gate.menu-item>
                                    <x-dashboard.gate.menu-item gate="content.video-library" level="full" icon="folder-minus" variant="danger" wire:click="deleteFolder({{ $option['id'] }})">
                                        Delete folder
                                    </x-dashboard.gate.menu-item>
                                </flux:menu>
                            </flux:dropdown>
                        </div>
                    @endforeach

                    <x-dashboard.gate.button
                        gate="content.video-library"
                        level="create"
                        size="sm"
                        icon="plus"
                        variant="ghost"
                        class="shrink-0 lg:w-full lg:justify-start"
                        wire:click="newFolder"
                    >
                        New folder
                    </x-dashboard.gate.button>
                </div>
            </div>

            <div class="space-y-4">
                {{-- Toolbar --}}
                <div class="flex items-center gap-2">
                    <flux:input
                        wire:model.live.debounce.400ms="search"
                        placeholder="Search by name"
                        icon="magnifying-glass"
                        size="sm"
                        class="grow"
                    />

                    <flux:dropdown position="bottom" align="end">
                        <flux:button size="sm" variant="filled" icon="bars-arrow-up" title="Sort videos" />

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

                {{-- Inline panels. A dialog inside a dialog traps focus in the
                     wrong layer and closes both on Escape, so the picker cannot
                     use modals — and the page uses the same panels so the two
                     screens behave identically. --}}
                @if ($panel === 'folder')
                    <flux:card class="space-y-4">
                        <flux:heading size="md">{{ $folder_id ? 'Edit folder' : 'New folder' }}</flux:heading>

                        <flux:input wire:model="folder_name" label="Name" placeholder="Tutorials" />

                        @unless ($folder_id)
                            <flux:select wire:model="folder_parent_id" label="Inside">
                                <flux:select.option value="">Top level</flux:select.option>
                                @foreach ($this->folders as $option)
                                    <flux:select.option value="{{ $option['id'] }}">{{ $option['label'] }}</flux:select.option>
                                @endforeach
                            </flux:select>
                        @endunless

                        <flux:select wire:model.live="folder_visibility" label="Who can browse it">
                            @foreach ($this->visibilityOptions as $value => $label)
                                <flux:select.option value="{{ $value }}">{{ $label }}</flux:select.option>
                            @endforeach
                        </flux:select>

                        @if ($folder_visibility === 'role')
                            <flux:select wire:model="folder_visible_to_type" label="Visible to role">
                                <flux:select.option value="">Choose a role</flux:select.option>
                                @foreach (App\Enums\UserTypeEnum::forSelect() as $value => $label)
                                    <flux:select.option value="{{ $value }}">{{ $label }}</flux:select.option>
                                @endforeach
                            </flux:select>
                            <flux:error name="folder_visible_to_type" />
                        @endif

                        @if ($this->user->isAdmin() && ! $folder_id)
                            <flux:switch wire:model="folder_shared" label="Shared folder" description="Owned by the platform rather than by you." />
                        @endif

                        <flux:text size="sm">
                            This decides who sees the folder. Each video keeps its own visibility
                            wherever it is filed.
                        </flux:text>

                        <div class="flex justify-end gap-3">
                            <flux:button variant="ghost" wire:click="closePanel">Cancel</flux:button>
                            <flux:button variant="primary" wire:click="saveFolder">Save folder</flux:button>
                        </div>
                    </flux:card>
                @endif

                @if ($panel === 'edit')
                    <flux:card class="space-y-4">
                        <flux:heading size="md">Video details</flux:heading>

                        <flux:input wire:model="title" label="Title" description="Only ever used inside the library." />
                        <flux:textarea wire:model="description" label="Notes" rows="3" description="Optional. For whoever picks this video later." />

                        {{-- Typed rather than fetched: nothing here calls out to a
                             provider API, so a length nobody enters stays unknown and
                             the tile simply omits it. --}}
                        <x-form.number-field
                            wire:model="duration"
                            label="Length (seconds)"
                            min="1"
                            max="43200"
                            description="Optional. Shown on the tile and used by the Longest first sort."
                        />

                        <flux:select wire:model="video_folder_id" label="Folder">
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
                            <flux:select wire:model="visible_to_type" label="Visible to role">
                                <flux:select.option value="">Choose a role</flux:select.option>
                                @foreach (App\Enums\UserTypeEnum::forSelect() as $value => $label)
                                    <flux:select.option value="{{ $value }}">{{ $label }}</flux:select.option>
                                @endforeach
                            </flux:select>
                            <flux:error name="visible_to_type" />
                        @endif

                        {{-- The provider and the id are not editable. Repointing a row
                             would change what every post already embedding it shows. --}}
                        <flux:text size="sm">
                            To use a different video, add that one and remove this. Changing the
                            link here would change every page already showing it.
                        </flux:text>

                        <div class="flex justify-end gap-3">
                            <flux:button variant="ghost" wire:click="closePanel">Cancel</flux:button>
                            <flux:button variant="primary" wire:click="saveVideo">Save</flux:button>
                        </div>
                    </flux:card>
                @endif

                @if ($panel === 'move')
                    <flux:card class="space-y-4">
                        <flux:heading size="md">Move {{ $this->manageableSelection->count() }} video(s)</flux:heading>

                        <flux:select wire:model="move_folder_id" label="Into">
                            <flux:select.option value="">Library root</flux:select.option>
                            @foreach ($this->folders as $option)
                                <flux:select.option value="{{ $option['id'] }}">{{ $option['label'] }}</flux:select.option>
                            @endforeach
                        </flux:select>

                        <div class="flex justify-end gap-3">
                            <flux:button variant="ghost" wire:click="closePanel">Cancel</flux:button>
                            <flux:button variant="primary" wire:click="moveSelected">Move</flux:button>
                        </div>
                    </flux:card>
                @endif

                @if ($panel === 'delete')
                    <flux:card class="space-y-4">
                        <flux:heading size="md">Remove {{ $this->manageableSelection->count() }} video(s)?</flux:heading>

                        <flux:text>
                            This removes the video from your library only — nothing is deleted at
                            the provider. A video still used somewhere is kept and named back to you.
                        </flux:text>

                        <div class="flex justify-end gap-3">
                            <flux:button variant="ghost" wire:click="closePanel">Keep them</flux:button>
                            <flux:button variant="danger" wire:click="deleteSelected">Remove</flux:button>
                        </div>
                    </flux:card>
                @endif

                {{-- Grid --}}
                @if ($this->videos->isEmpty())
                    <x-dashboard.workspace-no-record
                        icon="film"
                        label="No videos"
                        :text="$search !== '' ? 'Nothing here matches that search.' : 'Switch to the Add a video tab and paste a link.'"
                    />
                @else
                    {{-- Only for a caller that can hold more than one video; a
                         single-pick picker has nothing to select all of. --}}
                    @if ($multiple)
                        <div class="flex items-center justify-between gap-3 pb-1">
                            <flux:checkbox
                                wire:click="toggleSelectAll"
                                :checked="$this->allOnPageSelected()"
                                label="{{ $this->allOnPageSelected() ? 'Deselect all' : 'Select all' }}"
                            />

                            <flux:text size="sm" class="text-slate-500 dark:text-slate-400">
                                {{ $this->videos->count() }} on this page
                            </flux:text>
                        </div>
                    @endif

                    <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-3">
                        @foreach ($this->videos as $video)
                            @php($isSelected = in_array($video->id, $selected, true))

                            <div wire:key="video-pick-{{ $video->id }}" class="group relative space-y-2">
                                <button
                                    type="button"
                                    wire:click="toggle({{ $video->id }})"
                                    @class([
                                        'relative block w-full overflow-hidden rounded-lg border transition focus:outline-none focus-visible:ring-2 focus-visible:ring-accent',
                                        'border-accent ring-2 ring-accent' => $isSelected,
                                        'border-slate-200 hover:border-slate-300 dark:border-slate-700 dark:hover:border-slate-600' => ! $isSelected,
                                    ])
                                >
                                    @if ($video->thumbnailUrl())
                                        <img
                                            src="{{ $video->thumbnailUrl() }}"
                                            alt="{{ $video->title }}"
                                            class="aspect-video w-full bg-slate-900 object-cover"
                                            loading="lazy"
                                        />
                                    @else
                                        {{-- Vimeo has no thumbnail URL that can be built without
                                             an API call, and a grid is not worth a round trip per
                                             tile to a third party. --}}
                                        <div class="flex aspect-video w-full items-center justify-center bg-slate-900">
                                            <flux:icon.film class="size-8 text-slate-500" />
                                        </div>
                                    @endif

                                    <span class="absolute inset-0 flex items-center justify-center">
                                        <span class="flex size-11 items-center justify-center rounded-full bg-black/55 text-white transition group-hover:bg-black/70">
                                            <flux:icon.play class="size-5" />
                                        </span>
                                    </span>

                                    @if ($video->readableDuration())
                                        <span class="absolute end-1.5 bottom-1.5 rounded bg-black/70 px-1.5 py-0.5 text-xs font-medium text-white">
                                            {{ $video->readableDuration() }}
                                        </span>
                                    @endif
                                </button>

                                @if ($isSelected)
                                    <span class="absolute start-1.5 top-1.5 flex size-5 items-center justify-center rounded-full bg-accent text-accent-foreground">
                                        <flux:icon.check class="size-3.5" />
                                    </span>
                                @endif

                                <div>
                                    <p class="truncate text-sm font-medium text-slate-950 dark:text-white" title="{{ $video->title }}">
                                        {{ $video->title }}
                                    </p>
                                    <p class="text-xs text-slate-500 dark:text-slate-400">
                                        {{ $video->provider->label() }}
                                    </p>
                                    <flux:badge size="sm" :color="$video->visibility->color()" inset="top bottom">
                                        {{ $video->visibility->label() }}
                                    </flux:badge>
                                </div>
                            </div>
                        @endforeach
                    </div>

                    @if ($this->videos->hasPages())
                        <div>{{ $this->videos->links() }}</div>
                    @endif
                @endif

                {{-- What you can do with what is selected --}}
                @if ($this->manageableSelection->isNotEmpty())
                    <div class="flex flex-wrap items-center gap-2 border-t border-slate-200 pt-3 dark:border-slate-700">
                        <flux:text size="sm" class="me-auto">
                            {{ $this->manageableSelection->count() }} selected
                        </flux:text>

                        @if ($this->manageableSelection->count() === 1)
                            <x-dashboard.gate.button gate="content.video-library" level="modify" size="sm" variant="ghost" icon="pencil-square" wire:click="openPanel('edit')">
                                Edit
                            </x-dashboard.gate.button>
                        @endif

                        <x-dashboard.gate.button gate="content.video-library" level="modify" size="sm" variant="ghost" icon="folder-arrow-down" wire:click="openPanel('move')">
                            Move
                        </x-dashboard.gate.button>

                        <x-dashboard.gate.button gate="content.video-library" level="full" size="sm" variant="ghost" icon="trash" wire:click="openPanel('delete')">
                            Remove
                        </x-dashboard.gate.button>

                        <flux:button size="sm" variant="subtle" wire:click="clearSelection">
                            Clear
                        </flux:button>
                    </div>
                @endif

                @if ($this->remaining !== null)
                    <flux:text size="sm">
                        {{ $this->remaining }} video(s) left on your account.
                    </flux:text>
                @endif
            </div>
        </div>
    @endif
</div>
