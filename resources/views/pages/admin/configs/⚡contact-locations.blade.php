<?php

use App\Enums\GateAccessEnum;
use App\Rules\MapEmbedRule;
use App\Rules\PhoneRule;
use App\Services\SiteConfigurationService;
use App\Traits\WithGateProps;
use App\Traits\WithSiteConfigProcessor;
use Flux\Flux;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * The places a visitor can reach the business — offices, branches, shops.
 *
 * A screen of its own rather than more fields on Site info: a location carries
 * lists of its own, and one site can have a dozen of them. The single phone,
 * email and address on Site info stay the main contact that emails read.
 */
new class extends Component
{
    use WithGateProps, WithSiteConfigProcessor;

    public array $locations = [];

    public array $location = [];

    public ?int $editingIndex = null;

    public ?int $deletingIndex = null;

    protected function configSubject(): string
    {
        return 'contact locations';
    }

    public function mount(): void
    {
        kSetSiteTitle('config', 'contact-locations');
        $this->setPageGate('config.contact-locations');
        $this->setConfigInitial();

        $this->locations = data_get($this->config, 'contact-locations', []);
        $this->location = $this->blankLocation();
    }

    protected function rules(): array
    {
        return [
            'location.label' => ['required', 'string', 'max:100'],
            'location.address' => ['nullable', 'string', 'max:500'],
            'location.phones' => ['array', 'max:10'],
            'location.phones.*' => [new PhoneRule(required: true)],
            'location.emails' => ['array', 'max:10'],
            'location.emails.*' => ['required', 'email', 'max:190'],
            'location.map-embed' => [new MapEmbedRule],
        ];
    }

    protected function validationAttributes(): array
    {
        return [
            'location.label' => 'name',
            'location.phones.*' => 'phone number',
            'location.emails.*' => 'email',
            'location.map-embed' => 'map',
        ];
    }

    /**
     * The map as it would be framed, for the preview in the modal. Null while the
     * field holds nothing that would show, so the preview disappears rather than
     * framing an error page.
     */
    #[Computed]
    public function mapPreview(): ?string
    {
        return app(SiteConfigurationService::class)->mapEmbedUrl(data_get($this->location, 'map-embed'));
    }

    public function create(): void
    {
        $this->checkGate(GateAccessEnum::CREATE);

        $this->resetValidation();
        $this->reset('editingIndex');
        $this->location = $this->blankLocation();

        Flux::modal('locationModal')->show();
    }

    public function edit(int $index): void
    {
        $this->checkGate();

        $this->resetValidation();
        $this->editingIndex = $index;
        $this->location = array_replace($this->blankLocation(), $this->locations[$index]);

        Flux::modal('locationModal')->show();
    }

    public function addPhone(): void
    {
        $this->location['phones'][] = '';
    }

    public function removePhone(int $index): void
    {
        unset($this->location['phones'][$index]);
        $this->location['phones'] = array_values($this->location['phones']);
    }

    public function addEmail(): void
    {
        $this->location['emails'][] = '';
    }

    public function removeEmail(int $index): void
    {
        unset($this->location['emails'][$index]);
        $this->location['emails'] = array_values($this->location['emails']);
    }

    public function save(): bool
    {
        $this->checkGate($this->editingIndex === null ? GateAccessEnum::CREATE : GateAccessEnum::MODIFY);

        // An empty row is somebody who pressed "add" and changed their mind, not a
        // phone number that failed. Dropped before validation so it cannot fail.
        $this->location['phones'] = array_values(array_filter($this->location['phones'], 'filled'));
        $this->location['emails'] = array_values(array_filter($this->location['emails'], 'filled'));

        // Google hands out a whole <iframe>, and that is what people paste. Keep
        // just the link when there is one to keep; otherwise leave the input as it
        // was, so the rule can say what is wrong with it.
        $map = trim((string) $this->location['map-embed']);
        $this->location['map-embed'] = app(SiteConfigurationService::class)->mapEmbedUrl($map) ?? $map;

        $this->validate();

        $this->respondError(
            'Add an address, a phone, an email or a map — a location with none of them gives a visitor nothing to use.',
            if: blank($this->location['address']) &&
                ! $this->location['phones'] &&
                ! $this->location['emails'] &&
                blank($this->location['map-embed']),
        );

        $isNew = $this->editingIndex === null;

        if ($isNew) {
            $this->locations[] = $this->location;
        } else {
            $this->locations[$this->editingIndex] = $this->location;
        }

        $this->persistLocations();

        $this->resetValidation();
        $this->reset('editingIndex');

        Flux::modal('locationModal')->close();

        return $this->respondSuccess($isNew ? 'Location added.' : 'Location updated.');
    }

    public function confirmDelete(int $index): void
    {
        $this->checkGate(GateAccessEnum::FULL, 'You do not have delete access to contact locations.');

        $this->deletingIndex = $index;

        Flux::modal('deleteModal')->show();
    }

    public function delete(): bool
    {
        $this->checkGate(GateAccessEnum::FULL);

        $this->respondError(
            'That location is no longer there.',
            if: ! isset($this->locations[$this->deletingIndex]),
        );

        unset($this->locations[$this->deletingIndex]);
        $this->locations = array_values($this->locations);
        $this->persistLocations();

        $this->reset('deletingIndex');

        return $this->respondSuccess('Location deleted.');
    }

    private function blankLocation(): array
    {
        return [
            'label' => '',
            'address' => '',
            'phones' => [''],
            'emails' => [''],
            'map-embed' => '',
        ];
    }

    private function persistLocations(): void
    {
        $this->config['contact-locations'] = $this->locations;
        $this->saveConfig(true);
    }
};
?>

<div class="space-y-6">
    <flux:card class="space-y-6">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
            <div>
                <flux:heading level="2" size="lg">Contact locations</flux:heading>
                <flux:text class="mt-1">
                    Offices or branches listed in the contact section of the home page. The section stays hidden until one is added.
                </flux:text>
            </div>

            <x-dashboard.gate.button
                :gate="$pageGate"
                :level="$gateCreate"
                variant="primary"
                icon="plus"
                wire:click="create"
            >
                Add location
            </x-dashboard.gate.button>
        </div>

        <flux:table class="w-full text-left text-sm">
            <flux:table.columns>
                <flux:table.column>Name</flux:table.column>
                <flux:table.column>Address</flux:table.column>
                <flux:table.column>Phones</flux:table.column>
                <flux:table.column>Emails</flux:table.column>
                <flux:table.column>Map</flux:table.column>
                <flux:table.column class="w-24 text-right">Actions</flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
                @forelse ($locations as $index => $item)
                    <flux:table.row wire:key="contact-location-{{ $index }}">
                        <flux:table.cell class="font-medium">{{ $item['label'] }}</flux:table.cell>
                        <flux:table.cell class="max-w-xs whitespace-normal">{{ $item['address'] ?: '—' }}</flux:table.cell>
                        {{-- The first entry and a count of the rest. The full lists live
                             in the edit modal; listing them here pushed the row actions
                             off the side of the table. --}}
                        @foreach (['phones', 'emails'] as $list)
                            @php($entries = $item[$list] ?? [])

                            <flux:table.cell>
                                @if ($entries)
                                    <div class="flex items-center gap-2">
                                        <span class="max-w-48 truncate">{{ $entries[0] }}</span>
                                        @if (count($entries) > 1)
                                            <flux:badge size="sm" :title="implode(', ', array_slice($entries, 1))">+{{ count($entries) - 1 }}</flux:badge>
                                        @endif
                                    </div>
                                @else
                                    —
                                @endif
                            </flux:table.cell>
                        @endforeach
                        <flux:table.cell>
                            @if (filled($item['map-embed'] ?? null))
                                <flux:badge size="sm" color="lime" icon="map-pin">Set</flux:badge>
                            @else
                                <flux:badge size="sm">None</flux:badge>
                            @endif
                        </flux:table.cell>
                        <flux:table.cell>
                            <div class="flex justify-end gap-1">
                                <x-dashboard.gate.button
                                    :gate="$pageGate"
                                    :level="$gateModify"
                                    variant="ghost"
                                    size="sm"
                                    icon="pencil-square"
                                    wire:click="edit({{ $index }})"
                                    tooltip="Edit location"
                                />
                                <x-dashboard.gate.button
                                    :gate="$pageGate"
                                    :level="$gateFull"
                                    variant="ghost"
                                    size="sm"
                                    icon="trash"
                                    wire:click="confirmDelete({{ $index }})"
                                    tooltip="Delete location"
                                />
                            </div>
                        </flux:table.cell>
                    </flux:table.row>
                @empty
                    <flux:table.row>
                        <flux:table.cell colspan="6" class="py-10 text-center text-sm text-slate-500 dark:text-slate-400">
                            No contact locations have been added yet.
                        </flux:table.cell>
                    </flux:table.row>
                @endforelse
            </flux:table.rows>
        </flux:table>
    </flux:card>

    <flux:modal name="locationModal" class="md:w-160">
        <form wire:submit="save" class="space-y-6">
            <div>
                <flux:heading size="lg">{{ $editingIndex === null ? 'Add location' : 'Edit location' }}</flux:heading>
                <flux:text class="mt-1">Fill in what applies. Anything left blank is simply not shown.</flux:text>
            </div>

            <flux:input wire:model="location.label" label="Name" placeholder="Head office" />

            <flux:textarea wire:model="location.address" label="Address" rows="2" placeholder="12 Allen Avenue, Ikeja, Lagos" />

            <div class="grid gap-6 md:grid-cols-2">
                <flux:field>
                    <flux:label>Phones</flux:label>
                    <div class="space-y-2">
                        @foreach ($location['phones'] as $i => $phone)
                            <div wire:key="location-phone-{{ $i }}" class="flex items-start gap-2">
                                <div class="grow">
                                    <flux:input type="tel" wire:model="location.phones.{{ $i }}" placeholder="+234 800 000 0000" />
                                    <flux:error name="location.phones.{{ $i }}" />
                                </div>
                                <flux:button variant="ghost" size="sm" icon="x-mark" wire:click="removePhone({{ $i }})" aria-label="Remove phone" />
                            </div>
                        @endforeach
                    </div>
                    <div>
                        <flux:button variant="subtle" size="sm" icon="plus" wire:click="addPhone">Add phone</flux:button>
                    </div>
                </flux:field>

                <flux:field>
                    <flux:label>Emails</flux:label>
                    <div class="space-y-2">
                        @foreach ($location['emails'] as $i => $email)
                            <div wire:key="location-email-{{ $i }}" class="flex items-start gap-2">
                                <div class="grow">
                                    <flux:input type="email" wire:model="location.emails.{{ $i }}" placeholder="hello@example.com" />
                                    <flux:error name="location.emails.{{ $i }}" />
                                </div>
                                <flux:button variant="ghost" size="sm" icon="x-mark" wire:click="removeEmail({{ $i }})" aria-label="Remove email" />
                            </div>
                        @endforeach
                    </div>
                    <div>
                        <flux:button variant="subtle" size="sm" icon="plus" wire:click="addEmail">Add email</flux:button>
                    </div>
                </flux:field>
            </div>

            <flux:field>
                <flux:label badge="Optional">Map</flux:label>
                <flux:description>
                    In Google Maps choose Share › Embed a map and paste the code here, or use OpenStreetMap's Share › HTML. Leave it empty for no map.
                </flux:description>
                <flux:textarea wire:model.live.blur="location.map-embed" rows="3" placeholder='<iframe src="https://www.google.com/maps/embed?pb=…"></iframe>' />
                <flux:error name="location.map-embed" />
            </flux:field>

            @if ($this->mapPreview)
                <iframe
                    src="{{ $this->mapPreview }}"
                    title="Map preview"
                    class="aspect-video w-full rounded-lg border border-slate-200 dark:border-white/10"
                    loading="lazy"
                    referrerpolicy="no-referrer-when-downgrade"
                ></iframe>
            @endif

            <div class="flex justify-end gap-3">
                <flux:modal.close>
                    <flux:button variant="ghost">Cancel</flux:button>
                </flux:modal.close>

                <flux:button type="submit" variant="primary" wire:loading.attr="disabled" wire:target="save">
                    Save location
                </flux:button>
            </div>
        </form>
    </flux:modal>

    <x-dashboard.confirm-modal
        name="deleteModal"
        title="Delete this location?"
        icon="trash"
        confirm="Delete location"
        confirm-icon="trash"
        wire:click="delete"
    >
        {{ data_get($locations, $deletingIndex.'.label', 'This location') }} comes off the contact section as soon as this is
        saved, map and all.
    </x-dashboard.confirm-modal>
</div>
