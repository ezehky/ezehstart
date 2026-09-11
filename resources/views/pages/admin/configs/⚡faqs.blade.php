<?php

use App\Enums\ActivityActionEnum;
use App\Enums\FaqTypeEnum;
use App\Enums\GateAccessEnum;
use App\Enums\StatusDefault;
use App\Models\Faq;
use App\Services\ActivityLogService;
use App\Services\MarkdownService;
use App\Traits\WithDataTable;
use App\Traits\WithFileImport;
use Flux\Flux;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component
{
    use WithDataTable, WithFileImport;

    public ?Faq $faq = null;

    public string $faq_type = 'general';

    public string $question = '';

    public string $answer = '';

    public int $flow_order = 1;

    public bool $status = true;

    /** The question queued for deletion, held while the dialog asks. */
    public ?int $deleteId = null;

    /** Show the compiled markdown instead of the editor. */
    public bool $previewing = false;

    public function mount(): void
    {
        kSetSiteTitle('config', 'faqs');
        $this->setPageGate('config.faqs');
    }

    /**
     * @return array<int, FaqTypeEnum>
     */
    #[Computed]
    public function faqCases(): array
    {
        return FaqTypeEnum::cases();
    }

    /**
     * Every question, in the order it is shown, grouped by type.
     *
     * @return Collection<string, Collection<int, Faq>>
     */
    #[Computed]
    public function grouped(): Collection
    {
        return $this->applySort($this->tableQuery(), 'flow_order', 'asc')
            ->get()
            ->groupBy(fn (Faq $faq) => $faq->faq_type->value);
    }

    /**
     * The columns, for WithDataTable.
     *
     * This screen draws one table per type rather than one table, but that is only
     * how it is laid out — the columns, the selection and the export are all about
     * the same set of questions, so they are declared once here.
     */
    protected function tableColumns(): array
    {
        return [
            'flow_order' => ['label' => 'Order', 'sortable' => true],
            'question' => ['label' => 'Question', 'locked' => true, 'sortable' => true],
            'answer' => ['label' => 'Answer', 'exportable' => true],
            'faq_type' => ['label' => 'Section'],
            'status' => ['label' => 'Status', 'sortable' => true],
            'updated_at' => ['label' => 'Updated', 'sortable' => true],
        ];
    }

    protected function tableQuery(): Builder
    {
        return Faq::query();
    }

    protected function tableSubject(): string
    {
        return 'questions';
    }

    protected function tableDeletable(): bool
    {
        return true;
    }

    protected function tableDeleteAction(): ActivityActionEnum
    {
        return ActivityActionEnum::FAQ_DELETE;
    }

    /**
     * Every question is on the page at once, so the header checkbox takes all of them.
     *
     * @return iterable<int, \Illuminate\Database\Eloquent\Model>
     */
    protected function tableRows(): iterable
    {
        return $this->grouped->flatten();
    }

    protected function tableExportValue(Model $item, string $column): mixed
    {
        return match ($column) {
            'updated_at' => $item->updatedAtHuman(),
            default => $this->defaultExportValue($item, $column),
        };
    }

    protected function afterBulkAction(): void
    {
        unset($this->grouped);
    }

    /**
     * What an imported file has to carry. A question with no answer is not a FAQ.
     */
    protected function importColumns(): array
    {
        return ['question', 'answer'];
    }

    protected function importSubject(): string
    {
        return 'questions';
    }

    /**
     * One row of an imported file.
     *
     * A section the file names is matched against the enum and falls back to General,
     * because a typo in a column nobody looks at should not lose the question.
     */
    protected function importRow(array $row, int $line): bool
    {
        $question = trim((string) $row['question']);
        $answer = trim((string) $row['answer']);

        if ($question === '') {
            throw new \RuntimeException('The question is blank.');
        }

        if ($answer === '') {
            throw new \RuntimeException('The answer is blank.');
        }

        $type = FaqTypeEnum::tryFrom(kSlug((string) ($row['section'] ?? $row['faq_type'] ?? '')))
            ?? FaqTypeEnum::GENERAL;

        $faq = Faq::query()->firstOrNew([
            'faq_type' => $type,
            'question' => $question,
        ]);

        if ($faq->exists) {
            return false;
        }

        $faq->answer = $answer;
        $faq->flow_order = (int) ($row['order'] ?? $row['flow_order'] ?? 0)
            ?: (int) Faq::query()->where('faq_type', $type)->max('flow_order') + 1;
        $faq->status = StatusDefault::ACTIVE;
        $faq->save();

        return true;
    }

    protected function afterImport(): void
    {
        unset($this->grouped);

        if ($this->importSkipped === []) {
            Flux::modal('faqImportModal')->close();
        }
    }

    public function startImport(): void
    {
        $this->checkGate(GateAccessEnum::CREATE, 'You do not have access to import questions.');

        $this->reset('importFile', 'importSkipped', 'importedCount');
        $this->resetValidation();

        Flux::modal('faqImportModal')->show();
    }

    /**
     * The compiled preview of whatever is currently in the editor.
     */
    #[Computed]
    public function preview(): string
    {
        return app(MarkdownService::class)->toHtml($this->answer);
    }

    public function create(string $type): void
    {
        $this->checkGate(GateAccessEnum::CREATE);

        $faqType = FaqTypeEnum::from($type);

        $this->resetForm();

        $this->faq_type = $faqType->value;

        // A new question goes to the bottom of its group rather than colliding with
        // whatever is already at position one.
        $this->flow_order = (int) Faq::query()->where('faq_type', $faqType)->max('flow_order') + 1;

        Flux::modal('faqModal')->show();
    }

    public function edit(Faq $faq): void
    {
        $this->checkGate(GateAccessEnum::MODIFY);

        $this->resetForm();

        $this->faq = $faq;
        $this->fill($faq->only(['question', 'answer', 'flow_order']));
        $this->faq_type = $faq->faq_type->value;
        $this->status = $faq->status->isActive();

        Flux::modal('faqModal')->show();
    }

    protected function rules(): array
    {
        return [
            'faq_type' => ['required', Rule::enum(FaqTypeEnum::class)],
            'question' => [
                'required',
                'string',
                'max:255',
                Rule::unique(Faq::class, 'question')->ignore($this->faq?->id),
            ],
            'answer' => ['required', 'string'],
            'flow_order' => ['required', 'integer', 'min:1'],
            'status' => ['boolean'],
        ];
    }

    public function save(): bool
    {
        $this->checkGate(GateAccessEnum::MODIFY);

        $this->validate();

        $action = ActivityActionEnum::FAQ_UPDATE;

        if (! $this->faq) {
            $this->faq = Faq::make();
            $action = ActivityActionEnum::FAQ_CREATE;
        }

        $this->faq->faq_type = FaqTypeEnum::from($this->faq_type);
        $this->faq->question = $this->question;
        $this->faq->answer = $this->answer;
        $this->faq->flow_order = $this->flow_order;
        $this->faq->status = StatusDefault::from((int) $this->status);

        $this->respondPrimary(if: $this->faq->isClean());

        // ||||||||
        // Log Service
        $serviceInstance = app(ActivityLogService::class);
        $affectedColumns = $serviceInstance->affectedColumns($this->faq);
        // ||||||||

        $this->faq->save();

        $serviceInstance->logActivity(
            $action,
            " FAQ: {$this->faq->label()}",
            $affectedColumns,
            model: $this->faq,
        );

        Flux::modal('faqModal')->close();
        $this->resetForm();
        unset($this->grouped);

        return $this->respondSuccess('The question has been saved.');
    }

    /**
     * Show or hide a question without deleting it.
     */
    public function toggleStatus(Faq $faq): bool
    {
        $this->checkGate(GateAccessEnum::MODIFY);

        $faq->status = $faq->status->isActive() ? StatusDefault::INACTIVE : StatusDefault::ACTIVE;

        $serviceInstance = app(ActivityLogService::class);
        $affectedColumns = $serviceInstance->affectedColumns($faq);

        $faq->save();

        $serviceInstance->logActivity(
            ActivityActionEnum::FAQ_UPDATE,
            " FAQ: {$faq->label()}",
            $affectedColumns,
            model: $faq,
        );

        unset($this->grouped);

        return $this->respondSuccess($faq->status->isActive()
            ? 'The question is now shown on the site.'
            : 'The question is now hidden from the site.');
    }

    public function confirmDelete(int $faqId): void
    {
        $this->checkGate(GateAccessEnum::FULL);

        $this->deleteId = $faqId;

        Flux::modal('deleteModal')->show();
    }

    public function delete(): bool
    {
        $this->checkGate(GateAccessEnum::FULL);

        $faq = Faq::query()->whereKey($this->deleteId)->first();

        abort_unless((bool) $faq, 404);

        // Captured before the row goes, so the log line still says what was deleted.
        $description = " FAQ: {$faq->label()}";

        $faq->delete();

        app(ActivityLogService::class)->logActivity(ActivityActionEnum::FAQ_DELETE, $description);

        $this->reset('deleteId');
        unset($this->grouped);

        return $this->respondSuccess('The question has been deleted.');
    }

    private function resetForm(): void
    {
        $this->resetValidation();
        $this->reset('faq', 'question', 'answer', 'flow_order', 'status', 'previewing');
    }
};
?>

<div class="space-y-6">
    <flux:card class="space-y-8">
        <div>
            <flux:heading level="2" size="lg" class="font-heading font-bold">FAQs</flux:heading>
            <flux:text class="mt-1">
                The questions answered on the public site. Answers are written in markdown, the
                same as the policy pages, so a link or a short list renders properly.
            </flux:text>
        </div>

        {{-- Both are about every question on the page rather than about one section
             of it, so they sit above the sections rather than inside each one. --}}
        <div class="flex justify-end">
            <x-table.column-manager :columns="$this->tableColumnList" />
        </div>

        <x-table.bulk-bar
            :count="$this->selectedCount"
            :matching="$selectMatching"
            subject="questions"
            :gate="$pageGate"
            deletable
        />

        @foreach ($this->faqCases as $faqType)
            @php($questions = $this->grouped[$faqType->value] ?? collect())

            <div class="space-y-3" wire:key="faq-type-{{ $faqType->value }}">
                <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <flux:heading size="md" class="font-heading font-bold">{{ $faqType->defaultTitle() }}</flux:heading>
                        <flux:text class="mt-0.5 text-sm">{{ $faqType->description() }}</flux:text>
                    </div>

                    <div class="flex gap-2">
                        <x-dashboard.gate.button
                            :gate="$pageGate"
                            :level="$gateCreate"
                            size="sm"
                            variant="filled"
                            icon="arrow-up-tray"
                            wire:click="startImport"
                        >
                            Import
                        </x-dashboard.gate.button>

                        <x-dashboard.gate.button
                            :gate="$pageGate"
                            :level="$gateCreate"
                            class="press"
                            size="sm"
                            icon="plus"
                            wire:click="create('{{ $faqType->value }}')"
                        >
                            Add question
                        </x-dashboard.gate.button>
                    </div>
                </div>

                <flux:table>
                    <x-table.columns
                        :columns="$this->tableColumnList"
                        :sort="$sortColumn"
                        :direction="$sortDirection"
                        selectable
                        actions
                    />

                    <x-table.rows :columns="$this->tableColumnList">
                        @forelse ($questions as $item)
                            <flux:table.row wire:key="faq-{{ $item->id }}">
                                <x-table.select :id="$item->id" />

                                <x-table.cell column="flow_order"><span class="tabular-nums">{{ $item->flow_order }}</span></x-table.cell>
                                <x-table.cell column="question" class="font-medium">{{ $item->question }}</x-table.cell>

                                <x-table.cell column="answer" class="max-w-sm truncate text-slate-500 dark:text-slate-400">
                                    {{ str($item->answer)->stripTags()->limit(80) }}
                                </x-table.cell>

                                <x-table.cell column="faq_type">
                                    <flux:badge size="sm" color="zinc">{{ $item->faq_type->label() }}</flux:badge>
                                </x-table.cell>

                                <x-table.cell column="status"><x-util.status :status="$item->status" /></x-table.cell>
                                <x-table.cell column="updated_at">{{ $item->updatedAtHuman() }}</x-table.cell>

                                <x-table.cell>
                                    <flux:dropdown position="right" align="start">
                                        <flux:button icon="ellipsis-vertical" variant="ghost" size="sm" />
                                        <flux:menu>
                                            <x-dashboard.gate.menu-item
                                                :gate="$pageGate"
                                                :level="$gateModify"
                                                icon="pencil-square"
                                                wire:click="edit({{ $item->id }})"
                                            >
                                                Edit
                                            </x-dashboard.gate.menu-item>
                                            <x-dashboard.gate.menu-item
                                                :gate="$pageGate"
                                                :level="$gateModify"
                                                :icon="$item->status->isActive() ? 'eye-slash' : 'eye'"
                                                wire:click="toggleStatus({{ $item->id }})"
                                            >
                                                {{ $item->status->isActive() ? 'Hide from site' : 'Show on site' }}
                                            </x-dashboard.gate.menu-item>
                                            <x-dashboard.gate.menu-item
                                                :gate="$pageGate"
                                                :level="$gateModify"
                                                icon="trash"
                                                variant="danger"
                                                wire:click="confirmDelete({{ $item->id }})"
                                            >
                                                Delete
                                            </x-dashboard.gate.menu-item>
                                        </flux:menu>
                                    </flux:dropdown>
                                </x-table.cell>
                            </flux:table.row>
                        @empty
                            <x-table.empty
                                :columns="$this->tableColumnList"
                                selectable
                                actions
                                label="Questions"
                                icon="question-mark-circle"
                                text="No question has been added yet."
                            />
                        @endforelse
                    </x-table.rows>
                </flux:table>
            </div>
        @endforeach
    </flux:card>

    <flux:modal name="faqImportModal" class="modal-sm">
        <form wire:submit="import" class="space-y-4">
            <flux:heading size="lg">Import questions</flux:heading>

            <flux:text>
                Columns headed <strong>question</strong> and <strong>answer</strong>, and
                optionally <strong>section</strong> and <strong>order</strong>. A question
                already in that section is left where it is, so the same file can be sent
                up twice.
            </flux:text>

            <x-form.file-field
                wire:model="importFile"
                label="Spreadsheet"
                formats="CSV or XLSX"
                maxSize="2 MB"
                accept=".csv,.txt,.xlsx"
            />

            @if ($importSkipped)
                <flux:callout icon="exclamation-triangle" variant="warning">
                    <flux:callout.text>
                        <span class="font-medium">{{ count($importSkipped) }} row{{ count($importSkipped) === 1 ? '' : 's' }} could not be used.</span>

                        <ul class="mt-2 list-inside list-disc space-y-1">
                            @foreach (array_slice($importSkipped, 0, 10) as $line)
                                <li>{{ $line }}</li>
                            @endforeach
                        </ul>

                        @if (count($importSkipped) > 10)
                            <p class="mt-2">…and {{ count($importSkipped) - 10 }} more.</p>
                        @endif
                    </flux:callout.text>
                </flux:callout>
            @endif

            <div class="flex justify-end gap-3">
                <flux:modal.close>
                    <flux:button variant="ghost" type="button">Cancel</flux:button>
                </flux:modal.close>
                <flux:button type="submit" variant="primary" wire:loading.attr="disabled" wire:target="import, importFile">Import</flux:button>
            </div>
        </form>
    </flux:modal>

    <flux:modal name="faqModal" class="md:w-3xl">
        <form wire:submit="save" class="space-y-6">
            <div>
                <flux:heading size="lg" class="font-heading font-bold">
                    {{ $faq === null ? 'Add question' : 'Edit question' }}
                </flux:heading>
                <flux:text class="mt-1">Saved changes show on the public page straight away.</flux:text>
            </div>

            <flux:input
                wire:model="question"
                label="Question"
                placeholder="Do I need an account to get started?"
                badge="required"
            />

            <div class="grid gap-5 sm:grid-cols-2">
                <x-form.number-field
                    label="Order"
                    wire:model.number="flow_order"
                    description="Lower numbers appear first."
                    badge="required"
                />

                <flux:switch
                    wire:model="status"
                    label="Show on site"
                    description="Turn this off to keep the answer without publishing it."
                />
            </div>

            <flux:separator variant="subtle" />

            <div class="flex items-center justify-between">
                <flux:heading size="md" class="font-heading font-bold">Answer</flux:heading>
                <flux:button size="sm" variant="ghost" type="button" wire:click="$toggle('previewing')">
                    {{ $previewing ? 'Back to editor' : 'Preview' }}
                </flux:button>
            </div>

            @if ($previewing)
                <div class="max-h-125 overflow-y-auto rounded-xl border border-slate-200 p-5 dark:border-white/10">
                    @if (filled($this->preview))
                        <div class="markdown-prose">{!! $this->preview !!}</div>
                    @else
                        <flux:text>Nothing to preview yet.</flux:text>
                    @endif
                </div>
            @else
                <x-form.markdown-field label="Answer" wire:model="answer" rows="8" markdown />

                <flux:callout icon="information-circle" class="text-sm">
                    Keep answers short. Link out to a policy or a page rather than repeating it
                    here — an answer that restates the terms is one more place to keep in step
                    with them.
                </flux:callout>
            @endif

            <div class="flex justify-end gap-3">
                <flux:modal.close>
                    <flux:button type="button" variant="ghost">Cancel</flux:button>
                </flux:modal.close>
                <flux:button type="submit" variant="primary">Save question</flux:button>
            </div>
        </form>
    </flux:modal>

    <x-dashboard.confirm-modal
        name="deleteModal"
        title="Delete this question?"
        icon="trash"
        confirm="Delete it"
        confirm-icon="trash"
        cancel="Keep it"
        wire:click="delete"
    >
        The question and its answer are removed for good. To take it off the site without
        losing the wording, hide it instead.
    </x-dashboard.confirm-modal>
</div>
