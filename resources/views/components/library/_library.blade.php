{{-- The library body, shared by the full-page library and the picker that opens
     over whatever somebody is writing.

     Included rather than made a component, because @include shares the parent's
     scope: $this is the Livewire component either way, so one copy of this markup
     drives both screens and they cannot drift apart. Behaviour lives in
     App\Traits\WithImageLibrary. --}}

<div class="space-y-5">
    {{-- Tabs --}}
    <div class="flex gap-1.5">
        <flux:button
            size="sm"
            icon="photo"
            variant="{{ $tab === 'library' ? 'primary' : 'ghost' }}"
            wire:click="switchTab('library')"
        >
            Library
        </flux:button>
        <x-dashboard.gate.button
            gate="content.image-library"
            level="create"
            size="sm"
            icon="arrow-up-tray"
            variant="{{ $tab === 'upload' ? 'primary' : 'ghost' }}"
            wire:click="switchTab('upload')"
        >
            Upload
        </x-dashboard.gate.button>
    </div>

    @if ($tab === 'upload')
        <livewire:livewire.library.image-uploader
            :folder="$folder"
            heading="Upload images"
            :subheading="$this->currentFolder
                ? 'They will be filed in ' . $this->currentFolder->name . '.'
                : 'They will land in the root of your library.'"
            :key="'uploader-' . ($folder ?? 'root')"
        />
    @else
        {{-- Folders: a rail beside the grid on desktop, a strip above it on a
             phone, where a vertical rail would eat the width the images need. --}}
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
                        All images
                    </flux:button>

                    @foreach ($this->folders as $option)
                        <div wire:key="rail-{{ $option['id'] }}" class="group/folder relative shrink-0 lg:w-full">
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
                                    <x-dashboard.gate.menu-item gate="content.image-library" level="modify" icon="pencil-square" wire:click="editFolder({{ $option['id'] }})">
                                        Edit folder
                                    </x-dashboard.gate.menu-item>
                                    <x-dashboard.gate.menu-item gate="content.image-library" level="full" icon="folder-minus" variant="danger" wire:click="deleteFolder({{ $option['id'] }})">
                                        Delete folder
                                    </x-dashboard.gate.menu-item>
                                </flux:menu>
                            </flux:dropdown>
                        </div>
                    @endforeach

                    <x-dashboard.gate.button
                        gate="content.image-library"
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
                        <flux:button size="sm" variant="filled" icon="bars-arrow-up" title="Sort images" />

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

                        <flux:input wire:model="folder_name" label="Name" placeholder="Banners" />

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
                            This decides who sees the folder. Each image keeps its own visibility
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
                        <flux:heading size="md">Image details</flux:heading>

                        <flux:input wire:model="title" label="Title" description="Renaming here does not change the image's URL." />
                        <flux:input wire:model="alt_text" label="Alt text" description="Describes the image to screen readers." />

                        <flux:select wire:model="image_folder_id" label="Folder">
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

                        <div class="flex justify-end gap-3">
                            <flux:button variant="ghost" wire:click="closePanel">Cancel</flux:button>
                            <flux:button variant="primary" wire:click="saveImage">Save</flux:button>
                        </div>
                    </flux:card>
                @endif

                @if ($panel === 'move')
                    <flux:card class="space-y-4">
                        <flux:heading size="md">Move {{ $this->manageableSelection->count() }} image(s)</flux:heading>

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
                        <flux:heading size="md">Delete {{ $this->manageableSelection->count() }} image(s)?</flux:heading>

                        <flux:text>
                            The files are removed from storage and cannot be recovered. An image
                            still used somewhere is kept and named back to you.
                        </flux:text>

                        <div class="flex justify-end gap-3">
                            <flux:button variant="ghost" wire:click="closePanel">Keep them</flux:button>
                            <flux:button variant="danger" wire:click="deleteSelected">Delete</flux:button>
                        </div>
                    </flux:card>
                @endif

                {{-- Grid --}}
                @if ($this->images->isEmpty())
                    <x-dashboard.workspace-no-record
                        icon="photo"
                        label="No images"
                        :text="$search !== '' ? 'Nothing here matches that search.' : 'Switch to the Upload tab and they will appear here.'"
                    />
                @else
                    <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 xl:grid-cols-4">
                        @foreach ($this->images as $image)
                            @php($isSelected = in_array($image->id, $selected, true))

                            <div wire:key="pick-{{ $image->id }}" class="group relative space-y-2">
                                <button
                                    type="button"
                                    wire:click="toggle({{ $image->id }})"
                                    @class([
                                        'block w-full overflow-hidden rounded-lg border transition focus:outline-none focus-visible:ring-2 focus-visible:ring-accent',
                                        'border-accent ring-2 ring-accent' => $isSelected,
                                        'border-slate-200 hover:border-slate-300 dark:border-slate-700 dark:hover:border-slate-600' => ! $isSelected,
                                    ])
                                >
                                    <img
                                        src="{{ $image->url() }}"
                                        alt="{{ $image->alt_text ?: $image->title }}"
                                        class="aspect-square w-full object-cover"
                                        loading="lazy"
                                    />
                                </button>

                                @if ($isSelected)
                                    <span class="absolute start-1.5 top-1.5 flex size-5 items-center justify-center rounded-full bg-accent text-accent-foreground">
                                        <flux:icon.check class="size-3.5" />
                                    </span>
                                @endif

                                <div>
                                    <p class="truncate text-sm font-medium text-slate-950 dark:text-white" title="{{ $image->title }}">
                                        {{ $image->title }}
                                    </p>
                                    <p class="text-xs text-slate-500 dark:text-slate-400">
                                        {{ $image->readableSize() }}
                                        @if ($image->dimensions())
                                            &middot; {{ $image->dimensions() }}
                                        @endif
                                    </p>
                                    <flux:badge size="sm" :color="$image->visibility->color()" inset="top bottom">
                                        {{ $image->visibility->label() }}
                                    </flux:badge>
                                </div>
                            </div>
                        @endforeach
                    </div>

                    @if ($this->images->hasPages())
                        <div>{{ $this->images->links() }}</div>
                    @endif
                @endif

                {{-- What you can do with what is selected --}}
                @if ($this->manageableSelection->isNotEmpty())
                    <div class="flex flex-wrap items-center gap-2 border-t border-slate-200 pt-3 dark:border-slate-700">
                        <flux:text size="sm" class="me-auto">
                            {{ $this->manageableSelection->count() }} selected
                        </flux:text>

                        @if ($this->manageableSelection->count() === 1)
                            <x-dashboard.gate.button gate="content.image-library" level="modify" size="sm" variant="ghost" icon="pencil-square" wire:click="openPanel('edit')">
                                Edit
                            </x-dashboard.gate.button>
                        @endif

                        <x-dashboard.gate.button gate="content.image-library" level="modify" size="sm" variant="ghost" icon="folder-arrow-down" wire:click="openPanel('move')">
                            Move
                        </x-dashboard.gate.button>

                        <x-dashboard.gate.button gate="content.image-library" level="full" size="sm" variant="ghost" icon="trash" wire:click="openPanel('delete')">
                            Delete
                        </x-dashboard.gate.button>

                        <flux:button size="sm" variant="subtle" wire:click="clearSelection">
                            Clear
                        </flux:button>
                    </div>
                @endif

                <flux:text size="sm">
                    Up to {{ $this->maxSize }} KB per image.
                    @if ($this->remaining !== null)
                        {{ $this->remaining }} upload(s) left on your account.
                    @endif
                </flux:text>
            </div>
        </div>
    @endif
</div>
