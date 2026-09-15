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
use Livewire\Attributes\Url;
use Livewire\Component;

new class extends Component
{
    use WithBlockEditor, WithGateProps;

    public ?EmailTemplate $template = null;

    #[Url]
    public string $step = '';

    // Step 1 — details
    public string $name = '';

    public ?string $description = null;

    // Step 2 — builder
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
            $this->step = $this->step ?: 'builder';
        } else {
            $this->step = 'details';
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

    // |||||||||||||||||||||||||||||||||||||||||||||||||||||||||||
    // STEP 1 — DETAILS

    /**
     * A brand new template is created here rather than waiting for the builder
     * step's own save — the same reason EmailCampaign's saveDetails() creates the
     * row early: everything past this point needs a real id to redirect back to
     * (and, for a campaign, to attach blocks to), and "Continue to Builder" is the
     * one moment that id doesn't exist yet.
     */
    public function saveDetails(): void
    {
        $this->checkGate($this->template ? GateAccessEnum::MODIFY : GateAccessEnum::CREATE);

        $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:500'],
        ]);

        $activity = app(ActivityLogService::class);
        $isNew = ! $this->template;
        $this->template ??= new EmailTemplate;

        $this->template->fill([
            'name' => $this->name,
            'description' => $this->description,
            // content/design are NOT NULL columns with no default — a brand-new
            // row needs something in them even before there is a single block or
            // a design choice to save.
            ...($isNew ? ['content' => $this->blockContent(), 'design' => $this->design] : []),
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

        if ($isNew) {
            $this->redirectRoute('admin.marketing.templates.edit', [$this->template, 'step' => 'builder'], navigate: true);

            return;
        }

        $this->step = 'builder';
    }

    // |||||||||||||||||||||||||||||||||||||||||||||||||||||||||||
    // STEP 2 — BUILDER

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

        $this->redirectRoute('admin.marketing.templates.edit', [$this->template, 'step' => 'builder'], navigate: true);
    }
};
?>

{{--
    A builder fills the window, exactly as the campaign builder does: a 640px canvas
    with a palette either side has nothing left over for the dashboard chrome, and
    closing is the way out. It asks first, because everything here lives in
    component state until a save. Two steps, the same shape as the campaign
    builder's own — details first (name doesn't exist without an id to save
    anything else against), the canvas second.
--}}
<div
    x-data="{ dirty: @js($this->hasUnsavedChanges()) }"
    x-on:input.capture="dirty = true"
    x-on:builder-saved.window="dirty = false"
    x-on:beforeunload.window="if (dirty) { $event.preventDefault(); $event.returnValue = '' }"
    class="fixed inset-0 z-50 flex flex-col overflow-hidden bg-slate-50 dark:bg-slate-950"
>
    <div class="flex shrink-0 flex-col gap-4 border-b border-slate-200 bg-white px-4 py-3 sm:px-6 lg:flex-row lg:items-center lg:justify-between dark:border-slate-800 dark:bg-slate-950">
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

        <nav aria-label="Template steps" class="flex items-center gap-1 self-start rounded-full border border-slate-200 bg-white p-1 lg:self-auto dark:border-slate-800 dark:bg-slate-950">
            @php($reachable = $template !== null)
            <button type="button" wire:click="$set('step', 'details')" @class([
                'press flex items-center gap-1.5 rounded-full px-3 py-1.5 text-xs font-semibold',
                'bg-slate-900 text-white dark:bg-lime-400 dark:text-slate-950' => $step === 'details',
                'text-slate-500 hover:bg-slate-100 dark:text-slate-400 dark:hover:bg-slate-900' => $step !== 'details',
            ])>
                @if ($step === 'builder')
                    <flux:icon name="check-circle" class="size-3.5" />
                @endif
                Details
            </button>
            <button type="button" @if ($reachable) wire:click="$set('step', 'builder')" @else disabled @endif @class([
                'press flex items-center gap-1.5 rounded-full px-3 py-1.5 text-xs font-semibold',
                'bg-slate-900 text-white dark:bg-lime-400 dark:text-slate-950' => $step === 'builder',
                'text-slate-500 hover:bg-slate-100 dark:text-slate-400 dark:hover:bg-slate-900' => $step !== 'builder' && $reachable,
                'text-slate-300 dark:text-slate-700' => ! $reachable,
            ])>
                Builder
            </button>
        </nav>
    </div>

    <div
        @class([
            'flex-1 overflow-y-auto px-4 sm:px-6',
            'py-6' => $step === 'details',
        ])
    >
        @if ($step === 'details')
            @include('pages.admin.marketing.partials._template-details')
        @else
            @include('pages.admin.marketing.partials._template-builder-step')
        @endif
    </div>

    <livewire:livewire.library.image-picker />
</div>
