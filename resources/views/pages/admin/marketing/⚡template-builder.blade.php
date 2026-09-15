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

    /**
     * Whether the screen holds anything the template row does not — what the close
     * button asks before it lets the page go.
     */
    public function hasUnsavedChanges(): bool
    {
        if (! $this->template) {
            return $this->name !== '' || $this->blocks !== [];
        }

        return $this->name !== $this->template->name
            || (string) $this->description !== (string) $this->template->description
            || $this->footer_section_id !== $this->template->footer_section_id
            || $this->blocks !== ($this->template->content['blocks'] ?? [])
            || $this->design !== ($this->template->design ?? []);
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

        $this->dispatch('builder-saved');

        $this->respondSuccess('The template has been saved.');

        $this->redirectRoute('admin.marketing.templates.edit', $this->template, navigate: true);
    }
};
?>

{{--
    A builder fills the window, exactly as the campaign builder does: a 640px canvas
    with a palette either side has nothing left over for the dashboard chrome, and
    closing is the way out. It asks first, because everything here lives in
    component state until a save.
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
            {{-- A plain button rather than a link: leaving with unsaved work has to
                 be a question, and a link cannot ask one. --}}
            <flux:button
                variant="ghost"
                size="sm"
                icon="x-mark"
                square
                aria-label="Close builder"
                x-on:click="if (! dirty || confirm('You have changes that have not been saved. Leave anyway?')) { Livewire.navigate(@js(route('admin.marketing.templates'))) }"
            />

            <div class="min-w-0">
                <flux:heading level="1" size="lg" class="truncate font-heading">{{ $template ? 'Edit template' : 'New template' }}</flux:heading>
                <flux:text class="mt-0.5 text-xs">A reusable design structure — campaigns started from it keep their own copy.</flux:text>
            </div>

            <span x-cloak x-show="dirty" class="shrink-0 rounded-full bg-amber-100 px-2 py-0.5 text-[11px] font-semibold text-amber-800 dark:bg-amber-400/15 dark:text-amber-300">
                Unsaved
            </span>
        </div>

        <x-dashboard.gate.button :gate="$pageGate" :level="$template ? $gateModify : $gateCreate" wire:click="save" icon="check">
            Save Template
        </x-dashboard.gate.button>
    </div>

    <div class="flex-1 space-y-4 overflow-y-auto px-4 py-5 sm:px-6">
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

        @include('pages.admin.marketing.partials._builder', [
            'allowSectionBlocks' => true,
            'canvasHeight' => 'h-[calc(100vh-19rem)] min-h-96',
        ])
    </div>

    <livewire:livewire.library.image-picker />
</div>
