<?php

use App\Enums\ActivityActionEnum;
use App\Enums\EmailSectionTypeEnum;
use App\Enums\GateAccessEnum;
use App\Models\EmailTemplate;
use App\Services\ActivityLogService;
use App\Services\EmailSectionService;
use App\Traits\WithBlockEditor;
use App\Traits\WithGateProps;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component
{
    use WithBlockEditor, WithGateProps;

    public ?EmailTemplate $template = null;

    public string $name = '';

    public ?string $description = null;

    public ?int $footer_section_id = null;

    public array $design = [];

    public function mount(?EmailTemplate $template = null): void
    {
        $this->template = $template?->exists ? $template : null;

        kSetSiteTitle('marketing', 'templates', $this->template ? 'Edit template' : 'New template');
        $this->setPageGate('marketing.templates');

        if ($this->template) {
            $this->name = $this->template->name;
            $this->description = $this->template->description;
            $this->footer_section_id = $this->template->footer_section_id;
            $this->design = $this->template->design ?? [];
            $this->blocks = $this->template->content['blocks'] ?? [];
        }
    }

    #[Computed]
    public function footers()
    {
        return app(EmailSectionService::class)->libraryQuery(EmailSectionTypeEnum::FOOTER)->get();
    }

    protected function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function save(): void
    {
        $this->checkGate($this->template ? GateAccessEnum::MODIFY : GateAccessEnum::CREATE);
        $this->validate();

        $activity = app(ActivityLogService::class);
        $this->template ??= new EmailTemplate;
        $isNew = ! $this->template->exists;

        $this->template->fill([
            'name' => $this->name,
            'description' => $this->description,
            'footer_section_id' => $this->footer_section_id,
            'content' => $this->blockContent(),
            'design' => $this->design,
        ]);

        $affected = $activity->affectedColumns($this->template);
        $this->template->save();

        $activity->logActivity(
            $isNew ? ActivityActionEnum::EMAIL_TEMPLATE_CREATE : ActivityActionEnum::EMAIL_TEMPLATE_UPDATE,
            " template: {$this->template->name}",
            $affected,
            model: $this->template,
        );

        $this->respondSuccess('The template has been saved.');

        $this->redirectRoute('admin.marketing.templates.edit', $this->template, navigate: true);
    }
};
?>

<div class="space-y-6">
    <x-dashboard.page-header
        icon="document-duplicate"
        :title="$template ? 'Edit template' : 'New template'"
        subtitle="A reusable design structure — campaigns started from it keep their own copy once created."
        :back="['route' => route('admin.marketing.templates')]"
    >
        <x-slot:actions>
            <x-dashboard.gate.button :gate="$pageGate" :level="$template ? $gateModify : $gateCreate" wire:click="save" icon="check">
                Save Template
            </x-dashboard.gate.button>
        </x-slot:actions>
    </x-dashboard.page-header>

    <flux:card class="grid grid-cols-1 gap-4 sm:grid-cols-2">
        <flux:input wire:model="name" label="Template name" placeholder="e.g. Newsletter Template" />
        <flux:select wire:model="footer_section_id" label="Footer">
            <flux:select.option value="">No footer</flux:select.option>
            @foreach ($this->footers as $footer)
                <flux:select.option value="{{ $footer->id }}">{{ $footer->name }}</flux:select.option>
            @endforeach
        </flux:select>
        <flux:input wire:model="description" label="Description" class="sm:col-span-2" />
    </flux:card>

    @include('pages.admin.marketing.partials._builder', ['allowSectionBlocks' => true])

    <livewire:livewire.library.image-picker />
</div>
