@props([
    'rows' => [],
    'admin' => false,
    'inheritance' => [],
    'subject' => '',
])

{{--
    The gate editor, shared by the roles listing and the single-account view.

    One select per gateable screen. Children sit under their parent and are indented
    rather than nested, because a child left blank inherits its parent's level and the
    grid has to make that readable at a glance.

    On an administrator the blank option means "whatever the role says" and there is a
    separate No access to deny something the role allows. On a role there is no such
    distinction — blank is no access.
--}}
<flux:modal name="gatesModal" class="md:w-175">
    <form wire:submit="saveGates" class="space-y-6">
        <div>
            <flux:heading size="lg">{{ $admin ? 'Account access' : 'Role access' }}</flux:heading>
            <flux:text class="mt-1">
                @if ($admin)
                    What <span class="font-medium">{{ $subject }}</span> may reach, on top of what their
                    role already grants. Leave a row on <span class="font-medium">Inherit</span> to follow the role.
                @else
                    What everybody holding the <span class="font-medium">{{ $subject }}</span> role may
                    reach. A screen left blank does not appear in their sidebar at all.
                @endif
            </flux:text>
        </div>

        <div class="flex flex-wrap gap-2">
            <flux:button size="sm" variant="filled" icon="check-badge" wire:click="grantEveryGate" type="button">
                Grant everything
            </flux:button>
            <flux:button size="sm" variant="ghost" icon="arrow-uturn-left" wire:click="clearEveryGate" type="button">
                {{ $admin ? 'Inherit everything' : 'Clear everything' }}
            </flux:button>
        </div>

        <div class="max-h-100 space-y-1 overflow-y-auto pr-1">
            @foreach ($rows as $index => $row)
                <div
                    wire:key="gate-row-{{ $row['key'] }}"
                    @class([
                        'flex items-center justify-between gap-4 rounded-lg px-3 py-2',
                        'bg-slate-50 dark:bg-slate-800/50' => ! $row['child'],
                        'ps-8' => $row['child'],
                    ])
                >
                    <div class="min-w-0">
                        <p @class([
                            'truncate text-sm',
                            'font-semibold text-slate-900 dark:text-white' => ! $row['child'],
                            'text-slate-600 dark:text-slate-300' => $row['child'],
                        ])>
                            {{ $row['label'] }}
                        </p>

                        @if ($admin && $row['level'] === '')
                            @php($inherited = data_get($inheritance, $row['key']))
                            <p class="mt-0.5 text-xs text-slate-400">
                                Following the role: {{ $inherited?->isNone() ? 'no access' : $inherited?->label(lowercase: true) }}
                            </p>
                        @endif
                    </div>

                    <flux:select
                        wire:model.live="gateRows.{{ $index }}.level"
                        size="sm"
                        class="w-44 shrink-0"
                        placeholder="Choose access"
                    >
                        <flux:select.option value="">{{ $admin ? 'Inherit from role' : 'No access' }}</flux:select.option>

                        @if ($admin)
                            <flux:select.option value="{{ App\Enums\GateAccessEnum::NONE->value }}">No access</flux:select.option>
                        @endif

                        @foreach (App\Enums\GateAccessEnum::forSelect() as $value => $label)
                            <flux:select.option value="{{ $value }}">{{ $label }}</flux:select.option>
                        @endforeach
                    </flux:select>
                </div>
            @endforeach
        </div>

        <flux:callout icon="information-circle" variant="secondary">
            <flux:callout.text>
                <span class="font-medium">View</span> reads only ·
                <span class="font-medium">Modify</span> edits what exists ·
                <span class="font-medium">Create</span> adds new records too ·
                <span class="font-medium">Full</span> includes deleting.
            </flux:callout.text>
        </flux:callout>

        <div class="flex justify-end gap-3">
            <flux:modal.close>
                <flux:button variant="ghost">Cancel</flux:button>
            </flux:modal.close>

            <flux:button type="submit" variant="primary" icon="check">Save access</flux:button>
        </div>
    </form>
</flux:modal>
