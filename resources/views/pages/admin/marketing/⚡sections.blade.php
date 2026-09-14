<?php

use App\Enums\EmailSectionTypeEnum;
use App\Enums\GateAccessEnum;
use App\Models\EmailSection;
use App\Services\EmailSectionService;
use App\Traits\WithFormResponseMessage;
use App\Traits\WithGateProps;
use Flux\Flux;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component
{
    use WithFormResponseMessage, WithGateProps;

    public ?EmailSection $section = null;

    public function mount(): void
    {
        kSetSiteTitle('marketing', 'sections');
        $this->setPageGate('marketing.sections');
    }

    /**
     * Every section, grouped by type in the order the palette shows them.
     *
     * @return array<string, \Illuminate\Support\Collection<int, EmailSection>>
     */
    #[Computed]
    public function grouped()
    {
        $sections = app(EmailSectionService::class)->libraryQuery()->get()->groupBy('email_section_type');

        return collect(EmailSectionTypeEnum::cases())
            ->mapWithKeys(fn (EmailSectionTypeEnum $type) => [$type->value => $sections->get($type->value, collect())])
            ->filter(fn ($group) => $group->isNotEmpty());
    }

    public function setDefault(EmailSection $section): void
    {
        $this->checkGate(GateAccessEnum::MODIFY);

        app(EmailSectionService::class)->setDefault($section);

        unset($this->grouped);

        $this->respondSuccess("\"{$section->name}\" is now the default {$section->email_section_type->label(true)}.");
    }

    public function confirmDelete(EmailSection $section): void
    {
        $this->checkGate(GateAccessEnum::FULL, 'You do not have delete access to saved sections.');

        if ($reason = app(EmailSectionService::class)->deleteBlockedReason($section)) {
            $this->respondError($reason, if: true);
        }

        $this->section = $section;

        Flux::modal('deleteSectionModal')->show();
    }

    public function delete(): bool
    {
        $this->checkGate(GateAccessEnum::FULL, 'You do not have delete access to saved sections.');

        $this->respondError('Select a section to delete first.', ! $this->section);

        if ($reason = app(EmailSectionService::class)->delete($this->section)) {
            $this->respondError($reason, if: true);
        }

        Flux::modal('deleteSectionModal')->close();
        $this->reset('section');
        unset($this->grouped);

        return $this->respondSuccess('The section has been deleted.');
    }
};
?>

<div class="space-y-6">
    <x-dashboard.page-header icon="megaphone" subtitle="Design templates, build campaigns, and send to your audience.">
        <x-slot:actions>
            <x-dashboard.gate.button gate="marketing.sections" level="create" href="{{ route('admin.marketing.sections.create') }}" wire:navigate variant="primary" icon="plus">
                New Section
            </x-dashboard.gate.button>
        </x-slot:actions>
    </x-dashboard.page-header>

    <x-marketing.tabs active="sections" />

    <flux:card class="space-y-6">
        <div>
            <flux:heading level="2" size="lg">Saved Sections</flux:heading>
            <flux:text class="mt-1">Reusable headers, footers, CTAs, and promotional bands — design once, drop into any email.</flux:text>
        </div>

        @if ($this->grouped->isEmpty())
            <x-dashboard.workspace-no-record
                icon="squares-2x2"
                label="Nothing saved yet"
                text="Select any block in the builder and choose 'Save as reusable section' to build your library."
            />
        @else
            @foreach ($this->grouped as $type => $sections)
                @php($typeCase = EmailSectionTypeEnum::from($type))

                <div class="space-y-3">
                    <p class="text-xs font-semibold uppercase tracking-[0.08em] text-slate-400">{{ $typeCase->label() }}s</p>

                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
                        @foreach ($sections as $item)
                            <flux:card class="space-y-3" wire:key="section-{{ $item->id }}">
                                <div class="flex items-start justify-between gap-2">
                                    <div>
                                        <p class="font-medium text-slate-950 dark:text-white">{{ $item->name }}</p>
                                        @if ($item->is_default->boolValue())
                                            <flux:badge size="sm" color="lime" class="mt-1">Default</flux:badge>
                                        @endif
                                    </div>
                                    <flux:dropdown position="bottom" align="end">
                                        <flux:button size="sm" variant="ghost" icon="ellipsis-horizontal" />
                                        <flux:menu>
                                            <x-dashboard.gate.menu-item gate="marketing.sections" level="modify" icon="pencil-square" href="{{ route('admin.marketing.sections.edit', $item) }}" wire:navigate>
                                                Edit
                                            </x-dashboard.gate.menu-item>
                                            @unless ($item->is_default->boolValue())
                                                <x-dashboard.gate.menu-item gate="marketing.sections" level="modify" icon="star" wire:click="setDefault({{ $item->id }})">
                                                    Make default
                                                </x-dashboard.gate.menu-item>
                                            @endunless
                                            <flux:menu.separator />
                                            <x-dashboard.gate.menu-item gate="marketing.sections" level="full" icon="trash" variant="danger" wire:click="confirmDelete({{ $item->id }})">
                                                Delete
                                            </x-dashboard.gate.menu-item>
                                        </flux:menu>
                                    </flux:dropdown>
                                </div>

                                <p class="text-xs text-slate-500 dark:text-slate-400">Updated {{ $item->updated_at->diffForHumans() }}</p>
                            </flux:card>
                        @endforeach
                    </div>
                </div>
            @endforeach
        @endif
    </flux:card>

    <x-dashboard.confirm-modal name="deleteSectionModal" title="Delete this section?" icon="trash" confirm="Delete it" cancel="Keep it" wire:click="delete">
        "{{ $this->section?->name }}" will be permanently removed. This can't be undone.
    </x-dashboard.confirm-modal>
</div>
