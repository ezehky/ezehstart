<?php

use App\Enums\ActivityActionEnum;
use App\Enums\FaqTypeEnum;
use App\Enums\StatusDefault;
use App\Models\Faq;
use App\Services\ActivityLogService;
use App\Services\MarkdownService;
use App\Traits\WithFormResponseMessage;
use Flux\Flux;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component
{
    use WithFormResponseMessage;

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
        return Faq::query()
            ->inFlowOrder()
            ->get()
            ->groupBy(fn (Faq $faq) => $faq->faq_type->value);
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
        $this->deleteId = $faqId;

        Flux::modal('deleteModal')->show();
    }

    public function delete(): bool
    {
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

        @foreach ($this->faqCases as $faqType)
            @php($questions = $this->grouped[$faqType->value] ?? collect())

            <div class="space-y-3" wire:key="faq-type-{{ $faqType->value }}">
                <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <flux:heading size="md" class="font-heading font-bold">{{ $faqType->defaultTitle() }}</flux:heading>
                        <flux:text class="mt-0.5 text-sm">{{ $faqType->description() }}</flux:text>
                    </div>

                    <flux:button class="press" size="sm" icon="plus" wire:click="create('{{ $faqType->value }}')">
                        Add question
                    </flux:button>
                </div>

                <flux:table>
                    <flux:table.columns>
                        <flux:table.column>Order</flux:table.column>
                        <flux:table.column>Question</flux:table.column>
                        <flux:table.column>Status</flux:table.column>
                        <flux:table.column>Updated</flux:table.column>
                        <flux:table.column>Actions</flux:table.column>
                    </flux:table.columns>
                    <flux:table.rows>
                        @forelse ($questions as $item)
                            <flux:table.row wire:key="faq-{{ $item->id }}">
                                <flux:table.cell><span class="tabular-nums">{{ $item->flow_order }}</span></flux:table.cell>
                                <flux:table.cell class="font-medium">{{ $item->question }}</flux:table.cell>
                                <flux:table.cell><x-status :status="$item->status" /></flux:table.cell>
                                <flux:table.cell>{{ $item->updatedAtHuman() }}</flux:table.cell>
                                <flux:table.cell>
                                    <flux:dropdown position="right" align="start">
                                        <flux:button icon="ellipsis-vertical" variant="ghost" size="sm" />
                                        <flux:menu>
                                            <flux:menu.item icon="pencil-square" wire:click="edit({{ $item->id }})">
                                                Edit
                                            </flux:menu.item>
                                            <flux:menu.item
                                                :icon="$item->status->isActive() ? 'eye-slash' : 'eye'"
                                                wire:click="toggleStatus({{ $item->id }})"
                                            >
                                                {{ $item->status->isActive() ? 'Hide from site' : 'Show on site' }}
                                            </flux:menu.item>
                                            <flux:menu.item
                                                icon="trash"
                                                variant="danger"
                                                wire:click="confirmDelete({{ $item->id }})"
                                            >
                                                Delete
                                            </flux:menu.item>
                                        </flux:menu>
                                    </flux:dropdown>
                                </flux:table.cell>
                            </flux:table.row>
                        @empty
                            <flux:table.row>
                                <flux:table.cell colspan="5">
                                    <x-dashboard.workspace-no-record
                                        label="Questions"
                                        icon="question-mark-circle"
                                        text="No question has been added yet."
                                    />
                                </flux:table.cell>
                            </flux:table.row>
                        @endforelse
                    </flux:table.rows>
                </flux:table>
            </div>
        @endforeach
    </flux:card>

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
