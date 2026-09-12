{{--
    The dialog behind the Export button: which columns the file carries, and what
    each one is called in it.

        <x-table.export-modal :columns="$this->tableExportOptions" subject="members" :count="$this->selectedCount" />

    It travels with <x-table.bulk-bar> rather than being left for each page to
    remember, the same way the bulk delete confirmation does.

    Every exportable column is offered, including the ones put away on screen — a
    column hidden because it made the table too wide is still one somebody wants in
    the spreadsheet. The headings are seeded from the table each time the dialog
    opens, so a rename belongs to the file being taken rather than to the screen.
--}}

@props([
    'columns' => [],
    'subject' => 'records',
    'count' => 0,
])

@php
    $formats = app(\App\Services\ExportService::class)->formatOptions();
@endphp

<flux:modal name="exportModal" class="md:w-135">
    <form wire:submit="export" class="space-y-6">
        <div>
            <flux:heading size="lg">Export {{ $subject }}</flux:heading>
            <flux:text class="mt-1">
                {{ number_format($count) }} {{ $subject }} are going into this file. Tick what it
                should carry, and rename any heading that reads better another way in a spreadsheet.
            </flux:text>
        </div>

        <flux:select wire:model="exportFormat" label="File format">
            @foreach ($formats as $value => $label)
                <option value="{{ $value }}">{{ $label }}</option>
            @endforeach
        </flux:select>

        <div class="space-y-3">
            <flux:label>Columns and headings</flux:label>

            <div class="max-h-80 space-y-2 overflow-y-auto pe-1">
                @foreach ($columns as $key => $column)
                    <div class="flex items-center gap-3" wire:key="export-column-{{ $key }}">
                        <flux:checkbox
                            wire:model="exportColumns"
                            value="{{ $key }}"
                            aria-label="Include {{ $column['label'] }}"
                        />

                        <flux:input
                            class="flex-1"
                            size="sm"
                            wire:model="exportLabels.{{ $key }}"
                            :placeholder="$column['label']"
                            aria-label="Heading for {{ $column['label'] }}"
                        />

                        {{-- Said out loud, because the dialog offers columns the
                             table is not showing and ticking one is otherwise a
                             guess at what will turn up in the file. --}}
                        @if (! $column['visible'])
                            <flux:badge size="sm" color="zinc">Off screen</flux:badge>
                        @endif
                    </div>
                @endforeach
            </div>
        </div>

        <div class="flex flex-wrap justify-end gap-3">
            <flux:button type="button" variant="subtle" icon="arrow-path" wire:click="resetExportColumns">
                Back to the table
            </flux:button>

            <flux:modal.close>
                <flux:button type="button" variant="ghost">Cancel</flux:button>
            </flux:modal.close>

            <flux:button type="submit" variant="primary" icon="arrow-down-tray">Export</flux:button>
        </div>
    </form>
</flux:modal>
