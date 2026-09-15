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

    /**
     * Whether the screen holds anything the section row does not — what the close
     * button asks before it lets the page go.
     */
    public function hasUnsavedChanges(): bool
    {
        if (! $this->section) {
            return $this->name !== '' || $this->blocks !== [];
        }

        return $this->name !== $this->section->name
            || $this->email_section_type !== $this->section->email_section_type->value
            || $this->blocks !== ($this->section->content['blocks'] ?? []);
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

        $this->dispatch('builder-saved');

        $this->respondSuccess('The section has been saved.');

        return $this->redirectRoute('admin.marketing.sections.edit', $this->section, navigate: true);
    }
};
?>

{{--
    The same full-screen shell the template and campaign builders use — see
    ⚡template-builder.blade.php for why a builder is the window rather than a page
    inside it.
--}}
<div
    x-data="{ dirty: @js($this->hasUnsavedChanges()) }"
    x-on:input.capture="dirty = true"
    x-on:builder-saved.window="dirty = false"
    x-on:beforeunload.window="if (dirty) { $event.preventDefault(); $event.returnValue = '' }"
    class="fixed inset-0 z-50 flex flex-col overflow-hidden bg-slate-50 dark:bg-slate-950"
>
    <div class="flex shrink-0 flex-wrap items-center justify-between gap-3 border-b border-slate-200 bg-white px-4 py-3 sm:px-6 dark:border-slate-800 dark:bg-slate-950">
        <div class="flex min-w-0 items-center gap-3">
            <flux:button
                variant="ghost"
                size="sm"
                icon="x-mark"
                square
                aria-label="Close editor"
                x-on:click="if (! dirty || confirm('You have changes that have not been saved. Leave anyway?')) { Livewire.navigate(@js(route('admin.marketing.sections'))) }"
            />

            <div class="min-w-0">
                <flux:heading level="1" size="lg" class="truncate font-heading">{{ $section ? 'Edit section' : 'New section' }}</flux:heading>
                <flux:text class="mt-0.5 text-xs">A reusable block you can drop into any template or campaign.</flux:text>
            </div>

            <span x-cloak x-show="dirty" class="shrink-0 rounded-full bg-amber-100 px-2 py-0.5 text-[11px] font-semibold text-amber-800 dark:bg-amber-400/15 dark:text-amber-300">
                Unsaved
            </span>
        </div>

        <x-dashboard.gate.button :gate="$pageGate" :level="$section ? $gateModify : $gateCreate" wire:click="save" icon="check">
            Save Section
        </x-dashboard.gate.button>
    </div>

    <div class="flex-1 space-y-4 overflow-y-auto px-4 py-5 sm:px-6">
        <flux:card class="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <flux:input wire:model="name" label="Section name" placeholder="e.g. Default Footer" />
            <flux:select wire:model="email_section_type" label="Section type">
                @foreach (EmailSectionTypeEnum::cases() as $case)
                    <flux:select.option value="{{ $case->value }}">{{ $case->label() }}</flux:select.option>
                @endforeach
            </flux:select>
        </flux:card>

        @include('pages.admin.marketing.partials._builder', [
            'allowSectionBlocks' => false,
            'canvasHeight' => 'h-[calc(100vh-19rem)] min-h-96',
        ])
    </div>

    <livewire:livewire.library.image-picker />
</div>
