<?php

use App\Enums\GateAccessEnum;
use App\Models\EmailTemplate;
use App\Services\EmailTemplateService;
use App\Traits\WithFormResponseMessage;
use App\Traits\WithGateProps;
use Flux\Flux;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;

new class extends Component
{
    use WithFormResponseMessage, WithGateProps;

    public ?EmailTemplate $template = null;

    #[Url]
    public string $search = '';

    public function mount(): void
    {
        kSetSiteTitle('marketing', 'templates');
        $this->setPageGate('marketing.templates');
    }

    #[Computed]
    public function templates()
    {
        return app(EmailTemplateService::class)->libraryQuery($this->search)->get();
    }

    public function duplicate(EmailTemplate $template): void
    {
        $this->checkGate(GateAccessEnum::CREATE);

        $copy = app(EmailTemplateService::class)->duplicate($template);

        $this->redirectRoute('admin.marketing.templates.edit', $copy, navigate: true);
    }

    public function confirmDelete(EmailTemplate $template): void
    {
        $this->checkGate(GateAccessEnum::FULL, 'You do not have delete access to templates.');

        $this->template = $template;

        Flux::modal('deleteTemplateModal')->show();
    }

    public function delete(): bool
    {
        $this->checkGate(GateAccessEnum::FULL, 'You do not have delete access to templates.');

        $this->respondError('Select a template to delete first.', ! $this->template);

        app(EmailTemplateService::class)->delete($this->template);

        Flux::modal('deleteTemplateModal')->close();
        $this->reset('template');
        unset($this->templates);

        return $this->respondSuccess('The template has been deleted.');
    }
};
?>

<div class="space-y-6">
    <x-dashboard.page-header icon="megaphone" subtitle="Design templates, build campaigns, and send to your audience.">
        <x-slot:actions>
            <x-dashboard.gate.button gate="marketing.campaigns" level="create" href="{{ route('admin.marketing.campaigns.create') }}" wire:navigate variant="primary" icon="plus">
                Create Email
            </x-dashboard.gate.button>
        </x-slot:actions>
    </x-dashboard.page-header>

    <x-marketing.tabs active="templates" />

    <flux:card class="space-y-5">
        <div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
            <div>
                <flux:heading level="2" size="lg">Reusable templates</flux:heading>
                <flux:text class="mt-1">Start a new campaign from one of these instead of a blank canvas.</flux:text>
            </div>

            <div class="flex flex-col gap-3 sm:flex-row">
                <flux:input class="sm:min-w-60" wire:model.live.debounce.350ms="search" placeholder="Search templates" icon="magnifying-glass" />
                <x-dashboard.gate.button gate="marketing.templates" level="create" href="{{ route('admin.marketing.templates.create') }}" wire:navigate icon="plus">
                    Create Template
                </x-dashboard.gate.button>
            </div>
        </div>

        @if ($this->templates->isEmpty())
            <x-dashboard.workspace-no-record
                icon="document-duplicate"
                label="No templates yet"
                text="Save any campaign as a template, or start a reusable design from scratch."
            />
        @else
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($this->templates as $item)
                    <flux:card class="space-y-0! overflow-hidden p-0!" wire:key="template-{{ $item->id }}">
                        <div class="flex h-32 items-center justify-center bg-slate-100 dark:bg-slate-800">
                            @if ($item->thumbnailImage)
                                <img src="{{ $item->thumbnailImage->url() }}" alt="" class="h-full w-full object-cover" />
                            @else
                                <flux:icon name="document-duplicate" class="size-8 text-slate-300 dark:text-slate-600" />
                            @endif
                        </div>

                        <div class="space-y-2 p-4">
                            <div class="flex items-start justify-between gap-2">
                                <p class="font-medium text-slate-950 dark:text-white">{{ $item->name }}</p>
                                <flux:dropdown position="bottom" align="end">
                                    <flux:button size="sm" variant="ghost" icon="ellipsis-horizontal" />
                                    <flux:menu>
                                        <x-dashboard.gate.menu-item gate="marketing.templates" level="modify" icon="pencil-square" href="{{ route('admin.marketing.templates.edit', $item) }}" wire:navigate>
                                            Edit
                                        </x-dashboard.gate.menu-item>
                                        <x-dashboard.gate.menu-item gate="marketing.templates" level="create" icon="document-duplicate" wire:click="duplicate({{ $item->id }})">
                                            Duplicate
                                        </x-dashboard.gate.menu-item>
                                        <flux:menu.separator />
                                        <x-dashboard.gate.menu-item gate="marketing.templates" level="full" icon="trash" variant="danger" wire:click="confirmDelete({{ $item->id }})">
                                            Delete
                                        </x-dashboard.gate.menu-item>
                                    </flux:menu>
                                </flux:dropdown>
                            </div>

                            @if ($item->description)
                                <p class="text-xs text-slate-500 dark:text-slate-400">{{ $item->description }}</p>
                            @endif

                            <div class="flex items-center justify-between border-t border-slate-100 pt-2 text-xs text-slate-500 dark:border-white/10 dark:text-slate-400">
                                <span>Updated {{ $item->updated_at->diffForHumans() }}</span>
                                <span class="flex items-center gap-1 font-medium text-slate-700 dark:text-slate-200">
                                    <flux:icon name="paper-airplane" class="size-3" />
                                    {{ $item->used_count }} {{ $item->used_count === 1 ? 'use' : 'uses' }}
                                </span>
                            </div>
                        </div>
                    </flux:card>
                @endforeach
            </div>
        @endif
    </flux:card>

    <x-dashboard.confirm-modal name="deleteTemplateModal" title="Delete this template?" icon="trash" confirm="Delete it" cancel="Keep it" wire:click="delete">
        Campaigns already built from "{{ $this->template?->name }}" keep their own copy and are unaffected.
    </x-dashboard.confirm-modal>
</div>
