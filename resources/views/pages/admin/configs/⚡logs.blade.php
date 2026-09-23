<?php

use App\Enums\ActivityActionEnum;
use App\Enums\GateAccessEnum;
use App\Enums\LogChannelEnum;
use App\Enums\LogLevelEnum;
use App\Services\ActivityLogService;
use App\Services\LogFileService;
use App\Traits\WithGateProps;
use Flux\Flux;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

new class extends Component
{
    use WithGateProps, WithPagination;

    #[Url]
    public string $channel = 'laravel';

    #[Url]
    public string $file = '';

    #[Url]
    public string $level = '';

    #[Url(as: 'q')]
    public string $search = '';

    public ?string $selectedEntryId = null;

    public function mount(): void
    {
        kSetSiteTitle('config', 'logs');
        $this->setPageGate('config.logs');
    }

    // ||||||||||||||||||||||||||||||||||||||||||||||||
    // FILTERS

    public function updatedChannel(): void
    {
        // A file name belongs to one channel. Carried over, it would match nothing
        // and the screen would read as an empty log rather than the newest one.
        $this->file = '';
        $this->resetPage();
    }

    public function updatedFile(): void
    {
        $this->resetPage();
    }

    public function updatedLevel(): void
    {
        $this->resetPage();
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    // ||||||||||||||||||||||||||||||||||||||||||||||||
    // DATA

    #[Computed]
    public function channelEnum(): LogChannelEnum
    {
        return LogChannelEnum::tryFrom($this->channel) ?? LogChannelEnum::LARAVEL;
    }

    #[Computed]
    public function files(): Collection
    {
        return app(LogFileService::class)->files($this->channelEnum);
    }

    #[Computed]
    public function selectedFile(): ?array
    {
        return app(LogFileService::class)->find($this->channelEnum, $this->file ?: null);
    }

    /**
     * Every entry in the file before the level filter, so the counts beside each
     * level describe the file rather than the level already picked.
     */
    #[Computed]
    public function allEntries(): Collection
    {
        if (! $file = $this->selectedFile) {
            return collect();
        }

        return app(LogFileService::class)->entries($file['path'], search: $this->search);
    }

    #[Computed]
    public function levelCounts(): array
    {
        return app(LogFileService::class)->levelCounts($this->allEntries);
    }

    #[Computed]
    public function entries(): LengthAwarePaginator
    {
        $level = LogLevelEnum::tryFrom($this->level);

        $entries = $level
            ? $this->allEntries->filter(fn (array $entry) => $entry['level'] === $level)->values()
            : $this->allEntries;

        $perPage = 25;
        $page = $this->getPage();

        return new LengthAwarePaginator(
            $entries->forPage($page, $perPage),
            $entries->count(),
            $perPage,
            $page,
            ['path' => Paginator::resolveCurrentPath(), 'pageName' => 'page'],
        );
    }

    #[Computed]
    public function isTruncated(): bool
    {
        return $this->selectedFile && app(LogFileService::class)->isTruncated($this->selectedFile['path']);
    }

    #[Computed]
    public function selectedEntry(): ?array
    {
        if (! $this->selectedEntryId || ! $file = $this->selectedFile) {
            return null;
        }

        return app(LogFileService::class)->entry($file['path'], $this->selectedEntryId);
    }

    #[Computed]
    public function selectedMail(): ?array
    {
        return $this->selectedEntry ? app(LogFileService::class)->mail($this->selectedEntry) : null;
    }

    // ||||||||||||||||||||||||||||||||||||||||||||||||
    // ACTIONS

    public function show(string $entryId): void
    {
        $this->selectedEntryId = $entryId;

        unset($this->selectedEntry, $this->selectedMail);

        Flux::modal('entryModal')->show();
    }

    public function download(): BinaryFileResponse
    {
        $this->checkGate(GateAccessEnum::VIEW);

        $file = $this->selectedFile;

        $this->respondError('That log file no longer exists.', ! $file);

        app(ActivityLogService::class)->logActivity(ActivityActionEnum::LOG_FILE_DOWNLOAD, $file['name']);

        return response()->download($file['path'], $file['name']);
    }

    public function confirmClear(): void
    {
        $this->checkGate(GateAccessEnum::FULL);

        Flux::modal('clearModal')->show();
    }

    public function clear(): bool
    {
        $this->checkGate(GateAccessEnum::FULL);

        $file = $this->selectedFile;

        $this->respondError('That log file no longer exists.', ! $file);

        // Emptied rather than deleted — see LogFileService::clear().
        $this->respondError('The log file could not be cleared.', ! app(LogFileService::class)->clear($file['path']));

        app(ActivityLogService::class)->logActivity(ActivityActionEnum::LOG_FILE_CLEAR, $file['name']);

        unset($this->files, $this->selectedFile, $this->allEntries, $this->levelCounts, $this->entries);

        $this->resetPage();

        Flux::modal('clearModal')->close();

        return $this->respondSuccess("{$file['name']} has been cleared.");
    }
};
?>

<div class="space-y-6">
    <flux:card class="space-y-5">
        <div class="flex flex-col gap-4 xl:flex-row xl:items-end xl:justify-between">
            <div>
                <flux:heading level="2" size="lg">Log files</flux:heading>
                <flux:text class="mt-1">What the application wrote to its log channels, newest first.</flux:text>
            </div>

            <div class="flex flex-wrap gap-2">
                @if ($this->selectedFile)
                    <x-dashboard.gate.button
                        :gate="$pageGate"
                        level="view"
                        type="button"
                        variant="ghost"
                        icon="arrow-down-tray"
                        wire:click="download"
                    >
                        Download
                    </x-dashboard.gate.button>

                    <x-dashboard.gate.button
                        :gate="$pageGate"
                        :level="$gateFull"
                        type="button"
                        variant="danger"
                        icon="trash"
                        wire:click="confirmClear"
                    >
                        Clear
                    </x-dashboard.gate.button>
                @endif
            </div>
        </div>

        <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
            <flux:select wire:model.live="channel" label="Channel">
                @foreach (LogChannelEnum::forSelect() as $value => $label)
                    <option value="{{ $value }}">{{ $label }}</option>
                @endforeach
            </flux:select>

            <flux:select wire:model.live="file" label="File" :disabled="$this->files->count() < 2">
                @forelse ($this->files as $item)
                    <option value="{{ $loop->first ? '' : $item['name'] }}">
                        {{ $item['name'] }} · {{ kFileSize($item['size']) }}
                    </option>
                @empty
                    <option value="">No files yet</option>
                @endforelse
            </flux:select>

            <flux:select wire:model.live="level" label="Level">
                <option value="">All levels ({{ array_sum($this->levelCounts) }})</option>
                @foreach (LogLevelEnum::cases() as $case)
                    <option value="{{ $case->value }}">{{ $case->label() }} ({{ $this->levelCounts[$case->value] }})</option>
                @endforeach
            </flux:select>

            <flux:input
                wire:model.live.debounce.350ms="search"
                label="Search"
                placeholder="Message or stack trace"
                icon="magnifying-glass"
            />
        </div>

        @if ($this->isTruncated)
            <flux:callout variant="warning" icon="exclamation-triangle">
                <flux:callout.text>
                    This file is larger than {{ kFileSize(LogFileService::MAX_BYTES) }}, so only its most recent
                    entries are shown. Download it to read the rest.
                </flux:callout.text>
            </flux:callout>
        @endif

        <flux:table :paginate="$this->entries">
            <flux:table.columns>
                <flux:table.column class="w-28">Level</flux:table.column>
                <flux:table.column class="w-48">When</flux:table.column>
                <flux:table.column>Message</flux:table.column>
            </flux:table.columns>

            <flux:table.rows>
                @forelse ($this->entries as $index => $entry)
                    <flux:table.row wire:key="log-{{ $entry['id'] }}">
                        <flux:table.cell class="align-top">
                            @if ($entry['level'])
                                <flux:badge size="sm" :color="$entry['level']->color()">{{ $entry['level']->label() }}</flux:badge>
                            @endif
                        </flux:table.cell>

                        <flux:table.cell class="align-top text-xs text-slate-500">
                            <div>{{ $entry['datetime'] ? kDatetimeConverter($entry['datetime'], dtFormat: true) : '-' }}</div>
                            @if ($entry['environment'])
                                <div class="font-mono">{{ $entry['environment'] }}</div>
                            @endif
                        </flux:table.cell>

                        <flux:table.cell class="max-w-0 align-top whitespace-normal">
                            <button
                                type="button"
                                class="block w-full cursor-pointer text-left hover:text-slate-950 dark:hover:text-white"
                                wire:click="show('{{ $entry['id'] }}')"
                                title="View the full entry"
                            >
                                @if ($entry['mail'])
                                    <span class="flex items-center gap-2 text-sm">
                                        <flux:icon.envelope variant="micro" class="shrink-0 text-slate-400" />
                                        <span class="truncate font-medium">{{ $entry['mail']['subject'] ?: '(no subject)' }}</span>
                                    </span>
                                    <span class="block truncate text-xs text-slate-500">To {{ $entry['mail']['to'] }}</span>
                                @else
                                    <span class="block truncate font-mono text-xs">{{ $entry['message'] }}</span>
                                @endif
                            </button>
                        </flux:table.cell>
                    </flux:table.row>
                @empty
                    <flux:table.row>
                        <flux:table.cell colspan="3">
                            <x-dashboard.workspace-no-record
                                label="No entries"
                                icon="document-text"
                                :text="$this->selectedFile ? 'Nothing in this file matches the current filters.' : 'This channel has not written a log file yet.'"
                            />
                        </flux:table.cell>
                    </flux:table.row>
                @endforelse
            </flux:table.rows>
        </flux:table>
    </flux:card>

    <flux:modal name="entryModal" class="modal-full">
        @if ($entry = $this->selectedEntry)
            @php($mail = $this->selectedMail)

            <div
                class="flex h-full flex-col gap-5"
                wire:key="entry-{{ $entry['id'] }}"
                x-data="{ view: @js($mail ? ($mail['html'] ? 'html' : 'text') : 'raw') }"
            >
                <div class="flex flex-wrap items-center gap-3 pr-10">
                    @if ($entry['level'])
                        <flux:badge size="sm" :color="$entry['level']->color()">{{ $entry['level']->label() }}</flux:badge>
                    @endif
                    <flux:heading size="lg">{{ $mail ? ($mail['headers']['subject'] ?? '(no subject)') : 'Log entry' }}</flux:heading>
                    <flux:text class="text-xs">
                        {{ $entry['datetime'] ? kDatetimeConverter($entry['datetime'], dtFormat: true) : '-' }}
                        @if ($entry['environment'])
                            · <span class="font-mono">{{ $entry['environment'] }}</span>
                        @endif
                    </flux:text>
                </div>

                @if ($mail)
                    <dl class="grid gap-x-6 gap-y-1 text-sm sm:grid-cols-[auto_1fr]">
                        @foreach (['from', 'to', 'cc', 'bcc', 'reply-to'] as $header)
                            @if (! empty($mail['headers'][$header]))
                                <dt class="text-slate-500">{{ kBreakText($header) }}</dt>
                                <dd class="break-all">{{ $mail['headers'][$header] }}</dd>
                            @endif
                        @endforeach
                    </dl>

                    <div class="flex gap-2">
                        @foreach (array_filter(['html' => 'HTML', 'text' => 'Text'], fn ($label, $key) => $mail[$key], ARRAY_FILTER_USE_BOTH) + ['raw' => 'Raw'] as $key => $label)
                            <flux:button
                                size="sm"
                                x-on:click="view = '{{ $key }}'"
                                x-bind:class="view === '{{ $key }}' && 'bg-slate-100! dark:bg-slate-700!'"
                            >
                                {{ $label }}
                            </flux:button>
                        @endforeach
                    </div>

                    @if ($mail['html'])
                        {{-- An empty sandbox allows nothing: a logged email carries content
                             from wherever the mailable got it, and it must not run script
                             inside the admin workspace. --}}
                        <iframe
                            x-show="view === 'html'"
                            sandbox
                            srcdoc="{{ $mail['html'] }}"
                            class="min-h-0 w-full flex-1 rounded-lg border border-slate-200 bg-white dark:border-slate-700"
                            title="Email preview"
                        ></iframe>
                    @endif

                    @if ($mail['text'])
                        <pre x-show="view === 'text'" x-cloak class="log-pre">{{ $mail['text'] }}</pre>
                    @endif
                @endif

                <pre x-show="view === 'raw'" x-cloak class="log-pre">{{ trim($entry['message'].PHP_EOL.$entry['context']) }}</pre>
            </div>
        @endif
    </flux:modal>

    <x-dashboard.confirm-modal
        name="clearModal"
        title="Clear this log file?"
        confirm="Clear file"
        confirm-icon="trash"
        wire:click="clear"
    >
        Every entry in {{ $this->selectedFile['name'] ?? 'the file' }} goes for good. Download it first if you may need it.
    </x-dashboard.confirm-modal>
</div>
