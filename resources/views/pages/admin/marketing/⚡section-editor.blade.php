<?php

use App\Enums\ActivityActionEnum;
use App\Enums\EmailSectionTypeEnum;
use App\Enums\GateAccessEnum;
use App\Models\EmailSection;
use App\Services\ActivityLogService;
use App\Traits\WithBlockEditor;
use App\Traits\WithGateProps;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Validate;
use Livewire\Component;

new class extends Component
{
    use WithBlockEditor, WithGateProps;

    public ?EmailSection $section = null;

    #[Validate('required|string|max:255')]
    public string $name = '';

    public string $email_section_type = 'footer';

    public function mount(?EmailSection $section = null): void
    {
        $this->section = $section?->exists ? $section : null;

        kSetSiteTitle('marketing', 'sections', $this->section ? 'Edit section' : 'New section');
        $this->setPageGate('marketing.sections');

        if ($this->section) {
            $this->name = $this->section->name;
            $this->email_section_type = $this->section->email_section_type->value;
            $this->blocks = $this->section->content['blocks'] ?? $this->section->content ?? [];
        }
    }

    protected function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email_section_type' => ['required', Rule::enum(EmailSectionTypeEnum::class)],
        ];
    }

    public function save()
    {
        $this->checkGate($this->section ? GateAccessEnum::MODIFY : GateAccessEnum::CREATE);
        $this->validate();

        $activity = app(ActivityLogService::class);
        $this->section ??= new EmailSection;
        $isNew = ! $this->section->exists;

        $this->section->fill([
            'name' => $this->name,
            'email_section_type' => $this->email_section_type,
            'content' => $this->blockContent(),
        ]);

        $affected = $activity->affectedColumns($this->section);
        $this->section->save();

        $activity->logActivity(
            $isNew ? ActivityActionEnum::EMAIL_SECTION_CREATE : ActivityActionEnum::EMAIL_SECTION_UPDATE,
            " section: {$this->section->name}",
            $affected,
            model: $this->section,
        );

        $this->respondSuccess('The section has been saved.');

        return $this->redirectRoute('admin.marketing.sections.edit', $this->section, navigate: true);
    }
};
?>

<div class="space-y-6">
    <x-dashboard.page-header
        icon="squares-2x2"
        :title="$section ? 'Edit section' : 'New section'"
        subtitle="A reusable block, or small group of blocks, you can drop into any template or campaign."
        :back="['route' => route('admin.marketing.sections')]"
    >
        <x-slot:actions>
            <x-dashboard.gate.button :gate="$pageGate" :level="$section ? $gateModify : $gateCreate" wire:click="save" icon="check">
                Save Section
            </x-dashboard.gate.button>
        </x-slot:actions>
    </x-dashboard.page-header>

    <flux:card class="grid grid-cols-1 gap-4 sm:grid-cols-2">
        <flux:input wire:model="name" label="Section name" placeholder="e.g. Default Footer" />
        <flux:select wire:model="email_section_type" label="Section type">
            @foreach (EmailSectionTypeEnum::cases() as $case)
                <flux:select.option value="{{ $case->value }}">{{ $case->label() }}</flux:select.option>
            @endforeach
        </flux:select>
    </flux:card>

    @include('pages.admin.marketing.partials._builder', ['allowSectionBlocks' => false])

    <livewire:livewire.library.image-picker />
</div>
