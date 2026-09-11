# pages.md

## Rule

There are **six** recurring page shapes in this project. Every new screen is one of
them. Identify the shape first, then copy the template.

| Shape | Canonical file | Signature |
| --- | --- | --- |
| **A. Modal CRUD** | `pages/admin/configs/⚡faqs.blade.php`, `pages/admin/training/⚡trainings.blade.php` | list + `flux:modal` form on the same page |
| **B. Filtered index** | `pages/admin/users/⚡students.blade.php`, `pages/admin/finance/⚡transactions.blade.php` | metrics row + filter bar + paginated table |
| **C. Settings form** | `pages/admin/configs/⚡site-config.blade.php`, `pages/user/account/⚡account-settings.blade.php` | one `<form wire:submit="save">` as the root element |
| **D. Tabbed record** | `pages/admin/training/⚡cohort-*.blade.php` (10 files) | shared trait + `x-dashboard.tab-nav` + one tab's content |
| **E. Dashboard** | `pages/admin/⚡dashboard.blade.php`, `pages/user/⚡dashboard.blade.php` | greeting + stat cards + chart + feeds |
| **F. Guest / auth** | `pages/auth/⚡login.blade.php` | `#[Layout('layouts::auth')]` + named slots |

All pages start with `kSetSiteTitle(...)` in `mount()` and end with a single root
`<div class="space-y-6">` (or `<form>` for shape C).

---

## A. Modal CRUD page

### Rule

One page owns the list *and* the create/edit form. `?Model $model = null` decides
create vs edit. `create()` and `edit()` both call `resetForm()` first, then
`Flux::modal('xModal')->show()`.

### Why

Config-style resources (trainings, FAQs, roles, trainer roles, policies) have few rows
and simple forms. A second route and page for the form would be more navigation than
the task deserves, and the modal keeps the list visible behind it.

### Example — `pages/admin/configs/⚡faqs.blade.php`

```php
public ?Faq $faq = null;

public function create(FaqTypeEnum $type): void
{
    $this->resetForm();

    $this->faq_type = $type;
    // New questions go to the bottom of their group.
    $this->flow_order = (int) Faq::query()->where('faq_type', $type)->max('flow_order') + 1;

    Flux::modal('faqModal')->show();
}

public function edit(Faq $faq): void
{
    $this->resetForm();

    $this->faq = $faq;
    $this->fill($faq->only(['faq_type', 'question', 'answer', 'flow_order']));
    $this->status = $faq->status->boolValue();

    Flux::modal('faqModal')->show();
}

private function resetForm(): void
{
    $this->resetValidation();
    $this->reset('faq', 'question', 'answer', 'flow_order', 'status', 'previewing');
}
```

The modal heading switches on the model prop:

```blade
<flux:heading size="lg">
    {{ $faq === null ? 'Add question' : 'Edit question' }}
</flux:heading>
```

### Template

```php
<?php

use App\Enums\ActivityActionEnum;
use App\Enums\StatusDefault;
use App\Models\Thing;
use App\Services\ActivityLogService;
use App\Traits\WithFormResponseMessage;
use Flux\Flux;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component
{
    use WithFormResponseMessage;

    public ?Thing $thing = null;

    public string $name = '';

    public ?string $description = null;

    public bool $status = true;

    public function mount(): void
    {
        kSetSiteTitle('parent', 'things');
    }

    /**
     * @return Collection<int, Thing>
     */
    #[Computed]
    public function things(): Collection
    {
        return Thing::query()->select('id', 'name', 'status')->latest()->get();
    }

    public function create(): void
    {
        $this->resetForm();

        Flux::modal('thingModal')->show();
    }

    public function edit(Thing $thing): void
    {
        $this->resetForm();

        $this->thing = $thing;
        $this->fill($thing->only(['name', 'description']));
        $this->status = $thing->status->boolValue();

        Flux::modal('thingModal')->show();
    }

    protected function rules(): array
    {
        return [
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique(Thing::class, 'name')->ignore($this->thing?->id),
            ],
            'description' => ['nullable', 'string'],
            'status' => ['boolean'],
        ];
    }

    public function save(): bool
    {
        $this->validate();

        $action = ActivityActionEnum::THING_UPDATE;

        if (! $this->thing) {
            $this->thing = Thing::make();
            $action = ActivityActionEnum::THING_CREATE;
        }

        $this->thing->fill([
            'name' => $this->name,
            'description' => $this->description,
            'status' => StatusDefault::tryFrom((int) $this->status),
        ]);

        // Check if is clean (no changes) and return early
        $this->respondPrimary(if: $this->thing->isClean());

        // ||||||||
        // Log Service
        $serviceInstance = app(ActivityLogService::class);
        $affectedColumns = $serviceInstance->affectedColumns($this->thing);
        // ||||||||

        $this->thing->save();

        $serviceInstance->logActivity(
            $action,
            " thing: {$this->thing->name}",
            $affectedColumns,
            model: $this->thing,
        );

        Flux::modal('thingModal')->close();
        $this->resetForm();

        unset($this->things);

        return $this->respondSuccess('Thing has been successfully saved.');
    }

    public function delete(Thing $thing): bool
    {
        $description = " thing: {$thing->name}";

        $thing->delete();

        app(ActivityLogService::class)->logActivity(ActivityActionEnum::THING_DELETE, $description);

        unset($this->things);

        return $this->respondSuccess('The thing has been deleted.');
    }

    private function resetForm(): void
    {
        $this->resetValidation();
        $this->reset('thing', 'name', 'description', 'status');
    }
};
?>

<div class="space-y-6">
    <flux:card class="space-y-6">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
            <div>
                <flux:heading level="2" size="lg">Things</flux:heading>
                <flux:text class="mt-1">One sentence saying what this page manages.</flux:text>
            </div>

            <flux:button variant="primary" icon="plus" wire:click="create">
                Add thing
            </flux:button>
        </div>

        <flux:table>
            <flux:table.columns>
                <flux:table.column>Thing</flux:table.column>
                <flux:table.column>Status</flux:table.column>
                <flux:table.column>Actions</flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
                @forelse ($this->things as $item)
                    <flux:table.row wire:key="thing-{{ $item->id }}">
                        <flux:table.cell class="font-medium">{{ $item->name }}</flux:table.cell>
                        <flux:table.cell><x-util.status :status="$item->status" /></flux:table.cell>
                        <flux:table.cell>
                            <flux:dropdown position="right" align="start">
                                <flux:button icon="ellipsis-vertical" variant="ghost" size="sm" />
                                <flux:menu>
                                    <flux:menu.item icon="pencil-square" wire:click="edit({{ $item->id }})">
                                        Edit
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
                        <flux:table.cell colspan="3" class="py-10 text-center text-sm text-slate-500 dark:text-slate-400">
                            No things have been added yet.
                        </flux:table.cell>
                    </flux:table.row>
                @endforelse
            </flux:table.rows>
        </flux:table>
    </flux:card>

    <flux:modal name="thingModal" class="md:w-150">
        <form wire:submit="save" class="space-y-6">
            <div>
                <flux:heading size="lg">
                    {{ $thing === null ? 'Add thing' : 'Edit thing' }}
                </flux:heading>
                <flux:text class="mt-1">What the person filling this in needs to know.</flux:text>
            </div>

            <flux:input label="Name" wire:model="name" placeholder="e.g. Something" autofocus badge="required" />

            <x-form.markdown-field label="Description" wire:model="description" placeholder="Describe it." markdown />

            <flux:switch wire:model="status" label="Active" description="Available for use." />

            <div class="flex justify-end gap-3">
                <flux:modal.close>
                    <flux:button variant="ghost" type="button">Cancel</flux:button>
                </flux:modal.close>
                <flux:button type="submit" variant="primary">Save thing</flux:button>
            </div>
        </form>
    </flux:modal>

    <x-dashboard.confirm-modal
        name="deleteModal"
        title="Delete this thing?"
        icon="trash"
        confirm="Delete thing"
        confirm-icon="trash"
        wire:click="delete"
    >
        It is removed for good, and nothing linked to it keeps a copy.
    </x-dashboard.confirm-modal>
</div>
```

Modal widths in use: `md:w-150` (standard form), `md:w-3xl` (form with an editor or
preview), `md:w-110` (the confirm modal's own width — callers do not set it).

---

## B. Filtered index page

### Rule

Metrics strip → filter bar → paginated table. Filters are `#[Url]` props with an
`updated{Prop}()` that calls `resetPage()`. The query is a single `#[Computed]`
built with `->when()` chains.

### Why

Filters in the URL make an admin's view shareable and survive a refresh — important
when support is walking someone through a screen.

### Example — `pages/admin/users/⚡students.blade.php`

```php
use WithPagination, WithUserRoleManager;

#[Url(as: 'q')]
public string $search = '';

#[Url]
public string $accountStatus = '';

#[Url]
public string $cohortId = '';

public function updatedSearch(): void
{
    $this->resetPage();
}

#[Computed]
public function students()
{
    return User::query()
        ->users()
        ->with('userRoles.role')
        ->withCount([
            'admissions as enrolled_count' => fn ($query) => $query->where('status', StatusAdmission::ENROLLED),
        ])
        ->withSum(
            ['admissions as amount_paid_sum' => fn ($query) => $query->where('status', StatusAdmission::ENROLLED)],
            'amount_paid'
        )
        ->when($this->search !== '', fn ($query) => $query->searchMacro(['name', 'email', 'phone_number'], $this->search))
        ->when($this->accountStatus !== '', fn ($query) => $query->where('status', $this->accountStatus))
        ->when($this->cohortId !== '', fn ($query) => $query
            ->whereHas('admissions', fn ($admission) => $admission->where('cohort_id', $this->cohortId)))
        ->latest()
        ->paginate(12);
}

#[Computed]
public function metrics(): array
{
    return [
        ['label' => 'Total students', 'value' => number_format($total), 'icon' => 'academic-cap', 'tone' => 'sky'],
        ['label' => 'Active accounts', 'value' => number_format($active), 'icon' => 'check-badge', 'tone' => 'emerald'],
        ['label' => 'Enrolled admissions', 'value' => number_format($enrolled), 'icon' => 'ticket', 'tone' => 'slate'],
    ];
}
```

### Template (Blade half)

```blade
<div class="space-y-6">
    <section class="grid gap-4 sm:grid-cols-3" aria-label="Thing metrics">
        @foreach ($this->metrics as $metric)
            <x-dashboard.stat-card
                :label="$metric['label']"
                :value="$metric['value']"
                :icon="$metric['icon']"
                :tone="$metric['tone']"
            />
        @endforeach
    </section>

    <flux:card class="space-y-5">
        <div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
            <div>
                <flux:heading level="2" size="lg">Things</flux:heading>
                <flux:text class="mt-1">What this listing is.</flux:text>
            </div>

            <div class="flex flex-col gap-3 sm:flex-row">
                <flux:input
                    class="sm:min-w-60"
                    wire:model.live.debounce.350ms="search"
                    placeholder="Search name, email or phone"
                    icon="magnifying-glass"
                />
                <flux:select wire:model.live="statusFilter">
                    <option value="">All statuses</option>
                    @foreach ($this->statusOptions as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </flux:select>
            </div>
        </div>

        <flux:table :paginate="$this->things">
            …
            @empty
                <flux:table.row>
                    <flux:table.cell colspan="8">
                        <x-dashboard.workspace-no-record
                            label="Things"
                            icon="academic-cap"
                            text="No things match the current filters."
                        />
                    </flux:table.cell>
                </flux:table.row>
            @endforelse
        </flux:table>
    </flux:card>
</div>
```

See [filters.md](filters.md), [search.md](search.md), [pagination.md](pagination.md),
[tables.md](tables.md), [dashboard.md](dashboard.md).

---

## C. Settings form page

### Rule

The root element **is** the form. There is no modal. `save()` compares against a
snapshot taken in `mount()` to detect "no changes", then redirects with
`navigate: true` so shared view data (logo, site name) is re-read.

### Why

Settings pages edit a nested config array rather than a model, so `isClean()` is not
available — the snapshot comparison replaces it.

### Example — `pages/admin/configs/⚡site-config.blade.php`

```php
use WithFileUploads, WithFormResponseMessage;

public array $config;

public array $currentConfig = [];

public mixed $logoUpload = null;

public function mount(): void
{
    kSetSiteTitle('config', 'site-config');

    $service = app(SiteConfigurationService::class);
    $this->config = $service->getConfigs(mergeInitial: true, raw: true);
    $this->currentConfig = $service->getConfigs(raw: true);
}

protected function rules(): array
{
    return [
        'config.name' => ['required', 'string', 'max:150'],
        'config.email' => ['nullable', 'email', 'max:190'],
        'logoUpload' => [new ImageRule(required: false, size: 1024)],
        'config.email-settings.verification' => ['required', 'boolean'],
    ];
}

public function save(): bool
{
    $this->validate();

    $this->respondPrimary(if: $this->config === $this->currentConfig && ! $this->logoUpload);

    if ($this->logoUpload) {
        $filename = kStoreFile($this->logoUpload, filename: 'site-logo', path: 'site-config');
        kDeleteFile(data_get($this->config, 'logo'));
        $this->config['logo'] = $filename;
    }

    app(SiteConfigurationService::class)->update($this->config);

    $this->reset('logoUpload');

    $this->redirectRoute('admin.site-config', navigate: true);

    return $this->respondSuccess();
}
```

Nested config keys are bound with dotted `wire:model="config.email-settings.verification"`.

---

## D. Tabbed record page

### Rule

A record with many facets gets **one route and one page per tab**, all sharing a trait
that owns the record and the lock rules. Every tab renders
`<x-dashboard.tab-nav :cohort="$cohort" is-admin active="students" … />`.

### Why

Ten tabs in one component would be a 3000-line file with ten sets of form state. One
page per tab keeps each file readable and lets `wire:navigate` move between them
without re-rendering the others.

### Example

`routes/admin.php`:

```php
Route::livewire('/cohort/{cohort:slug}', 'pages::admin.training.cohort-editor')->name('cohort');
Route::livewire('/cohort/{cohort:slug}/students', 'pages::admin.training.cohort-students')->name('cohort.students');
Route::livewire('/cohort/{cohort:slug}/schedule', 'pages::admin.training.cohort-schedule')->name('cohort.schedule');
```

`app/Traits/WithCohortAdmin.php` owns:

```php
public Cohort $cohort;

protected function loadCohort(): void
{
    $this->cohort->load('training');
    $this->cohort->loadCount(['admissions', 'trainers', 'schedules']);
}

#[Computed]
public function canUpdateCohort(): bool
{
    return $this->cohort->allowUpdate();
}

/**
 * Refuse any write against a concluded or cancelled cohort.
 */
protected function ensureCohortIsEditable(): void
{
    $this->respondError($this->cohort->lockedReason(), if: $this->cohort->isLocked());
}
```

Each tab page:

```php
new class extends Component
{
    use WithCohortAdmin;

    public function mount(): void
    {
        kSetSiteTitle('training', 'cohorts', $this->cohort->name);
        $this->loadCohort();
    }

    public function save(): bool
    {
        // Concluded and cancelled cohorts are a closed record.
        $this->ensureCohortIsEditable();

        $this->validate();
        …
    }
};
```

**The lock is enforced in the method, not only hidden in the UI.**

Child records of the parent must be re-verified:

```php
abort_unless($this->cohort->is($this->classSession->cohort), 404);
```

---

## E. Dashboard page

### Rule

`mount()` sets the title and any driver-specific SQL expression. Everything else is
`#[Computed]`: `metrics()` (keyed array of card definitions), a chart series, and
feeds pulled from services.

### Example — `pages/admin/⚡dashboard.blade.php`

```php
public User $user;

public string $monthExpression;

public function mount(): void
{
    $this->user = auth()->user();
    kSetSiteTitle('dashboard');

    $this->monthExpression = match (DB::connection()->getDriverName()) {
        'sqlite' => "strftime('%Y-%m', created_at)",
        'pgsql' => "to_char(created_at, 'YYYY-MM')",
        default => "date_format(created_at, '%Y-%m')",
    };
}

#[Computed]
public function metrics(): array
{
    return [
        'users' => [
            'label' => 'Total users',
            'value' => number_format($usersCount),
            'icon' => 'users',
            'change' => 'All registered accounts',
            'tone' => 'sky',
        ],
        …
    ];
}

#[Computed]
public function recentActivities(): Collection
{
    return app(ActivityLogService::class)->getActivityLogsForUser(auth()->user());
}
```

Greeting header:

```blade
<h1 class="mt-1 font-heading text-3xl font-bold tracking-tight text-slate-950 dark:text-white">
    {{ kGreeting(str($user->name)->before(' '), false) }}.
</h1>
```

See [dashboard.md](dashboard.md).

---

## F. Guest / auth page

### Rule

`new #[Layout('layouts::auth')] class extends Component`. The layout exposes named
slots — `$tag`, `$title`, `$description`, `$socialConnect`, `$extra`.
Multi-step flows (`⚡passwordless`, `⚡forgot-password`) dispatch `attr` to update the
slot text without a full re-render.

### Example — `pages/auth/⚡forgot-password.blade.php`

```php
$this->dispatch('attr', tag: $tag, title: $title, description: $description);
```

```blade
<x-slot:tag>
    <span x-data="{ tag: @js($tag) }" x-on:attr.window="tag = $event.detail.tag" x-html="tag"></span>
</x-slot:tag>
```

See [auth.md](auth.md), [layouts.md](layouts.md).

---

## Avoid

- Inventing a seventh shape. Pick the closest of the six.
- A separate create/edit **route** for a config-style resource — use shape A.
- One giant component for a multi-faceted record — use shape D.
- Skipping `kSetSiteTitle()`; the sidebar highlight and `<title>` both break.
- Loading the parent record separately in each tab instead of using the shared trait.
- Enforcing a record lock only by hiding the button.
- `@foreach` without `@empty` on a table body.
- Hard-coding the modal name string in more than the two places it belongs
  (`Flux::modal('x')` calls and `<flux:modal name="x">`).
