<?php

use App\Enums\ActivityActionEnum;
use App\Enums\GateAccessEnum;
use App\Enums\PolicyTypeEnum;
use App\Enums\StatusPolicy;
use App\Enums\StatusYes;
use App\Models\Policy;
use App\Services\ActivityLogService;
use App\Services\PolicyContentService;
use App\Traits\WithDataTable;
use Flux\Flux;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component
{
    use WithDataTable;

    public ?Policy $policy = null;

    public string $policy_type = 'terms';

    public string $version = '1.0';

    public string $title = '';

    public ?string $intro = null;

    public string $content = '';

    public bool $requires_consent = true;

    /** The draft queued for publishing, held while the dialog states what it costs. */
    public ?int $publishId = null;

    /** Show the compiled markdown instead of the editor. */
    public bool $previewing = false;

    public function mount(): void
    {
        kSetSiteTitle('config', 'policies');
        $this->setPageGate('config.policies');
    }

    /**
     * @return array<int, PolicyTypeEnum>
     */
    #[Computed]
    public function policyCases(): array
    {
        return PolicyTypeEnum::cases();
    }

    /**
     * The columns, for WithDataTable.
     *
     * This screen draws one table per policy type rather than one table, but that is
     * how it is laid out rather than what it holds — the columns and the export are
     * about every version on the page.
     */
    protected function tableColumns(): array
    {
        return [
            'version' => ['label' => 'Version', 'locked' => true, 'sortable' => true],
            'title' => ['label' => 'Title'],
            'status' => ['label' => 'Status', 'sortable' => true],
            'requires_consent' => ['label' => 'Consent'],
            'consents_count' => ['label' => 'Accepted by', 'exportable' => false],
            'effective_at' => ['label' => 'In force from', 'sortable' => true],
            'updated_at' => ['label' => 'Updated', 'sortable' => true],
        ];
    }

    protected function tableQuery(): Builder
    {
        return Policy::query()->withCount('consents');
    }

    protected function tableSubject(): string
    {
        return 'policy versions';
    }

    /**
     * Every version is on the page at once, so the header checkbox takes all of them.
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
            'effective_at' => $item->effective_at?->format('Y-m-d') ?? '',
            'updated_at' => $item->updatedAtHuman(),
            default => $this->defaultExportValue($item, $column),
        };
    }

    /**
     * Every version of every policy, newest first, grouped by type.
     *
     * @return Collection<string, Collection<int, Policy>>
     */
    #[Computed]
    public function grouped(): Collection
    {
        return Policy::query()
            ->select('id', 'policy_type', 'version', 'title', 'status', 'requires_consent', 'effective_at', 'updated_at')
            ->withCount('consents')
            ->orderByDesc('id')
            ->get()
            ->groupBy(fn (Policy $policy) => $policy->policy_type->value);
    }

    /**
     * The compiled preview of whatever is currently in the editor.
     *
     * @return array<int, array<string, mixed>>
     */
    #[Computed]
    public function preview(): array
    {
        return app(PolicyContentService::class)->sections($this->content);
    }

    public function create(string $type): void
    {
        $this->checkGate(GateAccessEnum::CREATE);

        $policyType = PolicyTypeEnum::from($type);

        $serviceInstance = app(PolicyContentService::class);
        $current = $serviceInstance->getCurrent($policyType);

        $this->resetForm();

        $this->policy_type = $policyType->value;
        $this->version = $serviceInstance->getNextVersion($policyType);
        $this->title = $current->title ?? $policyType->defaultTitle();
        $this->intro = $current->intro ?? null;

        // Start from the text in force, so writing a new version is an edit rather
        // than a rewrite from a blank page.
        $this->content = $current->content ?? '';
        $this->requires_consent = $current?->requiresConsent() ?? true;

        Flux::modal('policyModal')->show();
    }

    public function edit(Policy $policy): void
    {
        $this->checkGate(GateAccessEnum::MODIFY);

        // Published text is what people consented to. It is superseded, never
        // edited — checked here as well as hidden in the markup, because a
        // disabled menu item is not a guard.
        $this->respondError(
            'Published policies cannot be edited. Draft a new version instead.',
            if: ! $policy->canEdit(),
        );

        $this->resetForm();

        $this->policy = $policy;
        $this->fill($policy->only(['version', 'title', 'intro', 'content']));
        $this->policy_type = $policy->policy_type->value;
        $this->requires_consent = $policy->requiresConsent();

        Flux::modal('policyModal')->show();
    }

    protected function rules(): array
    {
        return [
            'policy_type' => ['required', Rule::enum(PolicyTypeEnum::class)],
            'version' => [
                'required',
                'string',
                'max:20',
                Rule::unique(Policy::class, 'version')
                    ->where('policy_type', $this->policy_type)
                    ->ignore($this->policy?->id),
            ],
            'title' => ['required', 'string', 'max:255'],
            'intro' => ['nullable', 'string', 'max:500'],
            'content' => ['required', 'string'],
            'requires_consent' => ['boolean'],
        ];
    }

    public function save(): bool
    {
        $this->checkGate(GateAccessEnum::MODIFY);

        $this->validate();

        $action = ActivityActionEnum::POLICY_UPDATE;

        if (! $this->policy) {
            $this->policy = Policy::make(['status' => StatusPolicy::DRAFT]);
            $action = ActivityActionEnum::POLICY_CREATE;
        }

        $this->policy->policy_type = PolicyTypeEnum::from($this->policy_type);
        $this->policy->version = $this->version;
        $this->policy->title = $this->title;
        $this->policy->intro = $this->intro;
        $this->policy->content = $this->content;
        $this->policy->requires_consent = StatusYes::from((int) $this->requires_consent);

        $this->respondPrimary(if: $this->policy->isClean());

        // ||||||||
        // Log Service
        $serviceInstance = app(ActivityLogService::class);
        $affectedColumns = $serviceInstance->affectedColumns($this->policy);
        // ||||||||

        $this->policy->save();

        $serviceInstance->logActivity(
            $action,
            " policy: {$this->policy->label()}",
            $affectedColumns,
            model: $this->policy,
        );

        Flux::modal('policyModal')->close();
        $this->resetForm();
        unset($this->grouped);

        return $this->respondSuccess('Draft saved. Publish it when you are ready.');
    }

    /**
     * The draft the administrator has asked to publish, held while the dialog says
     * what publishing does. Nothing changes until they confirm it.
     */
    public function confirmPublish(int $policyId): void
    {
        $this->checkGate(GateAccessEnum::MODIFY);

        $this->publishId = $policyId;

        Flux::modal('publishModal')->show();
    }

    /**
     * Publish a draft and archive the version it replaces.
     */
    public function publish(): bool
    {
        $this->checkGate(GateAccessEnum::MODIFY);

        $policy = Policy::query()->whereKey($this->publishId)->first();

        abort_unless((bool) $policy, 404);

        $this->respondError('Only a draft can be published.', if: ! $policy->canPublish());

        $superseded = app(PolicyContentService::class)->getCurrent($policy->policy_type);

        $policy->status = StatusPolicy::PUBLISHED;
        $policy->effective_at = now();
        $policy->save();

        // The version it replaces stays readable rather than being deleted — every
        // consent record still points at it.
        $superseded?->update(['status' => StatusPolicy::ARCHIVED]);

        app(ActivityLogService::class)->logActivity(
            ActivityActionEnum::POLICY_PUBLISH,
            " policy: {$policy->label()}",
            model: $policy,
        );

        $this->reset('publishId');
        unset($this->grouped);

        return $this->respondSuccess("{$policy->label()} is now live.");
    }

    private function resetForm(): void
    {
        $this->resetValidation();
        $this->reset('policy', 'version', 'title', 'intro', 'content', 'requires_consent', 'previewing');
    }
};
?>

<div class="space-y-6">
    <flux:card class="space-y-8">
        <div>
            <flux:heading level="2" size="lg" class="font-heading font-bold">Policies</flux:heading>
            <flux:text class="mt-1">
                The terms, privacy and cookie pages. A published version is never edited — draft
                a new one instead, so the exact text each person consented to stays on record.
            </flux:text>
        </div>

        @foreach ($this->policyCases as $policyType)
            @php($versions = $this->grouped[$policyType->value] ?? collect())

            <div class="space-y-3" wire:key="policy-type-{{ $policyType->value }}">
                <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <flux:heading size="md" class="font-heading font-bold">{{ $policyType->defaultTitle() }}</flux:heading>
                        <flux:text class="mt-0.5 text-sm">
                            <flux:link href="{{ $policyType->url() }}" target="_blank" rel="noopener noreferrer">
                                {{ $policyType->url() }}
                            </flux:link>
                        </flux:text>
                    </div>

                    <x-dashboard.gate.button
                        :gate="$pageGate"
                        :level="$gateCreate"
                        class="press"
                        size="sm"
                        icon="plus"
                        wire:click="create('{{ $policyType->value }}')"
                    >
                        New version
                    </x-dashboard.gate.button>
                </div>

                <flux:table>
                    <x-table.columns
                        :columns="$this->tableColumnList"
                        :sort="$sortColumn"
                        :direction="$sortDirection"
                        actions
                    />

                    <x-table.rows :columns="$this->tableColumnList">
                        @forelse ($versions as $item)
                            <flux:table.row wire:key="policy-{{ $item->id }}">
                                <x-table.cell column="version" class="font-medium">v{{ $item->version }}</x-table.cell>
                                <x-table.cell column="title">{{ $item->title }}</x-table.cell>
                                <x-table.cell column="status"><x-util.status :status="$item->status" /></x-table.cell>
                                <x-table.cell column="requires_consent"><x-util.status :status="$item->requires_consent" /></x-table.cell>
                                <x-table.cell column="consents_count"><span class="tabular-nums">{{ number_format($item->consents_count) }}</span></x-table.cell>
                                <x-table.cell column="effective_at">{{ $item->effective_at ? $item->effectiveAtHuman() : '—' }}</x-table.cell>
                                <x-table.cell column="updated_at">{{ $item->updatedAtHuman() }}</x-table.cell>

                                <x-table.cell>
                                    <flux:dropdown position="right" align="start">
                                        <flux:button icon="ellipsis-vertical" variant="ghost" size="sm" />
                                        <flux:menu>
                                            @if ($item->canEdit())
                                                <x-dashboard.gate.menu-item
                                                    :gate="$pageGate"
                                                    :level="$gateModify"
                                                    icon="pencil-square"
                                                    wire:click="edit({{ $item->id }})"
                                                >
                                                    Edit draft
                                                </x-dashboard.gate.menu-item>
                                            @endif
                                            @if ($item->canPublish())
                                                <x-dashboard.gate.menu-item
                                                    :gate="$pageGate"
                                                    :level="$gateModify"
                                                    icon="rocket-launch"
                                                    wire:click="confirmPublish({{ $item->id }})"
                                                >
                                                    Publish
                                                </x-dashboard.gate.menu-item>
                                            @endif
                                            <flux:menu.item
                                                icon="eye"
                                                href="{{ $policyType->url() }}?version={{ $item->version }}"
                                                target="_blank"
                                            >
                                                View page
                                            </flux:menu.item>
                                        </flux:menu>
                                    </flux:dropdown>
                                </x-table.cell>
                            </flux:table.row>
                        @empty
                            <x-table.empty
                                :columns="$this->tableColumnList"
                                actions
                                label="Versions"
                                icon="document-text"
                                text="No version of this policy has been written yet."
                            />
                        @endforelse
                    </x-table.rows>
                </flux:table>
            </div>
        @endforeach
    </flux:card>

    <flux:modal name="policyModal" class="md:w-3xl">
        <form wire:submit="save" class="space-y-6">
            <div>
                <flux:heading size="lg" class="font-heading font-bold">
                    {{ $policy === null ? 'New policy version' : 'Edit draft' }}
                </flux:heading>
                <flux:text class="mt-1">
                    Saved as a draft. Nothing on the public page changes until you publish it.
                </flux:text>
            </div>

            <div class="grid gap-5 sm:grid-cols-2">
                <flux:input wire:model="title" label="Title" placeholder="Privacy Policy" badge="required" />
                <flux:input wire:model="version" label="Version" placeholder="2.0" badge="required" />
            </div>

            <flux:input
                wire:model="intro"
                label="Intro"
                placeholder="One line shown under the page heading"
                description="Also used as the page description for search engines."
            />

            <flux:switch
                wire:model="requires_consent"
                label="Require consent"
                description="New accounts must accept this policy before they are created, and everyone is asked again when a new version is published."
            />

            <flux:separator variant="subtle" />

            <div class="flex items-center justify-between">
                <flux:heading size="md" class="font-heading font-bold">Content</flux:heading>
                <flux:button size="sm" variant="ghost" type="button" wire:click="$toggle('previewing')">
                    {{ $previewing ? 'Back to editor' : 'Preview' }}
                </flux:button>
            </div>

            @if ($previewing)
                <div class="max-h-125 space-y-8 overflow-y-auto rounded-xl border border-slate-200 p-5 dark:border-white/10">
                    @forelse ($this->preview as $section)
                        <section>
                            @if (filled($section['title']))
                                <h2 class="font-heading text-lg font-semibold text-slate-900 dark:text-white">
                                    {{ $section['title'] }}
                                </h2>
                                <p class="mt-1 text-xs text-slate-400 dark:text-slate-500">Anchor: #{{ $section['id'] }}</p>
                            @endif
                            <div class="markdown-prose mt-3">{!! $section['html'] !!}</div>
                        </section>
                    @empty
                        <flux:text>Nothing to preview yet.</flux:text>
                    @endforelse
                </div>
            @else
                <x-form.markdown-field label="Policy content" wire:model="content" rows="18" markdown />

                {{-- Worth saying outright: the anchors are what outside links point
                     at, and rewording a heading silently moves one unless it is
                     pinned. --}}
                <flux:callout icon="information-circle" class="text-sm">
                    Every <strong>##</strong> heading starts a new section and gets its own anchor.
                    To keep an existing link working when you reword a heading, pin the anchor:
                    <code>## 5. Refunds &lbrace;#refunds&rbrace;</code>. Lists and pipe tables are supported.
                </flux:callout>
            @endif

            <div class="flex justify-end gap-3">
                <flux:modal.close>
                    <flux:button type="button" variant="ghost">Cancel</flux:button>
                </flux:modal.close>
                <flux:button type="submit" variant="primary">Save draft</flux:button>
            </div>
        </form>
    </flux:modal>

    <x-dashboard.confirm-modal
        name="publishModal"
        title="Publish this version?"
        icon="rocket-launch"
        variant="primary"
        tone="emerald"
        confirm="Publish it"
        confirm-icon="rocket-launch"
        cancel="Keep it as a draft"
        wire:click="publish"
    >
        It replaces the version currently in force, which is archived rather than deleted so
        the people who accepted it can still read it. Published text can no longer be edited.
    </x-dashboard.confirm-modal>
</div>
