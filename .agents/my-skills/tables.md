# tables.md

## Rule

Every listing uses the Flux table primitives in this exact nesting:

```blade
<flux:table :paginate="$this->things">
    <flux:table.columns>
        <flux:table.column>Header</flux:table.column>
        …
    </flux:table.columns>
    <flux:table.rows>
        @forelse ($this->things as $item)
            <flux:table.row wire:key="thing-{{ $item->id }}">
                <flux:table.cell>…</flux:table.cell>
                …
            </flux:table.row>
        @empty
            <flux:table.row>
                <flux:table.cell colspan="N">
                    <x-dashboard.workspace-no-record label="Things" icon="…" text="…" />
                </flux:table.cell>
            </flux:table.row>
        @endforelse
    </flux:table.rows>
</flux:table>
```

Non-negotiables:

- The loop variable is **`$item`**.
- `wire:key="{singular}-{{ $item->id }}"` on every row.
- `@forelse` / `@empty` — never a bare `@foreach`.
- `colspan` on the empty row **must equal the column count**.
- **Actions is always the last column.**

### Column order

`Identity → attributes → money → status → date → Actions`

```blade
<flux:table.column>Student</flux:table.column>
<flux:table.column>Phone</flux:table.column>
<flux:table.column>Roles</flux:table.column>
<flux:table.column>Cohorts</flux:table.column>
<flux:table.column>Paid</flux:table.column>
<flux:table.column>Status</flux:table.column>
<flux:table.column>Joined</flux:table.column>
<flux:table.column>Actions</flux:table.column>
```

### Cell content patterns

**Identity with avatar** — the standard user cell:

```blade
<flux:table.cell>
    <div class="flex items-center gap-3">
        <x-dashboard.avatar :user="$item" />
        <div class="min-w-0">
            <div class="font-medium">{{ $item->name }}</div>
            <div class="text-xs text-slate-500">{{ $item->email }}</div>
        </div>
    </div>
</flux:table.cell>
```

**Primary text** — `class="font-medium"` on the identifying column:

```blade
<flux:table.cell class="font-medium">{{ $item->question }}</flux:table.cell>
```

**Empty value** — em dash, never blank:

```blade
<flux:table.cell>{{ $item->phone_number ?: '—' }}</flux:table.cell>
```

**Money** — `{!! !!}` because `kMoneyFormat()` returns an HTML entity:

```blade
<flux:table.cell>{!! kMoneyFormat(($item->amount_paid_sum ?? 0) / 100) !!}</flux:table.cell>
<flux:table.cell>{!! $item->amountMoney() !!}</flux:table.cell>
```

**Counts**:

```blade
<flux:table.cell>{{ number_format($item->enrolled_count) }}</flux:table.cell>
```

**Status**:

```blade
<flux:table.cell><x-status :status="$item->status" /></flux:table.cell>
```

**Enum label**:

```blade
<flux:table.cell>{{ $item->training_type->label() }}</flux:table.cell>
```

**Date** — magic accessor, never Carbon in Blade:

```blade
<flux:table.cell>{{ $item->createdAtHuman() }}</flux:table.cell>
<flux:table.cell>{{ $item->updatedAtHuman() }}</flux:table.cell>
<flux:table.cell>{{ $item->startsAtDatetimeHuman() }}</flux:table.cell>
```

**Roles / chips**:

```blade
<flux:table.cell><x-dashboard.user-role :user="$item" /></flux:table.cell>
```

### The Actions cell — two forms

**Form 1 — inline buttons** (2–3 actions, one is a navigation):

```blade
<flux:table.cell>
    <div class="flex gap-2">
        <flux:button
            icon="eye"
            variant="primary"
            size="sm"
            :href="route('admin.user', $item)"
            wire:navigate
            title="View profile"
        />
        <flux:button
            icon="shield-check"
            variant="filled"
            size="sm"
            wire:click="openRoleManager({{ $item->id }})"
            title="Manage roles"
        />
    </div>
</flux:table.cell>
```

**Form 2 — kebab dropdown** (3+ actions, or any destructive action):

```blade
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
            <flux:menu.separator />
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
```

`position="right" align="start"` on every dropdown. Destructive items are
`variant="danger"` and **always** confirm first — the row action opens
`<x-dashboard.confirm-modal>` rather than deleting outright:

```php
public ?int $deletingId = null;

public function confirmDelete(int $id): void
{
    $this->deletingId = $id;

    Flux::modal('deleteModal')->show();
}

public function delete(): bool
{
    // …reads $this->deletingId, guards it, then deletes.
}
```

```blade
<x-dashboard.confirm-modal
    name="deleteModal"
    title="Delete this question?"
    icon="trash"
    confirm="Delete question"
    confirm-icon="trash"
    wire:click="delete"
>
    It stops appearing on the site straight away, and it cannot be undone.
</x-dashboard.confirm-modal>
```

### Empty states — two forms

**Rich** (index pages with filters):

```blade
@empty
    <flux:table.row>
        <flux:table.cell colspan="8">
            <x-dashboard.workspace-no-record
                label="Students"
                icon="academic-cap"
                text="No students match the current filters."
            />
        </flux:table.cell>
    </flux:table.row>
@endforelse
```

**Plain** (short config tables):

```blade
@empty
    <flux:table.row>
        <flux:table.cell colspan="4" class="py-10 text-center text-sm text-slate-500 dark:text-slate-400">
            No trainings have been added yet.
        </flux:table.cell>
    </flux:table.row>
@endforelse
```

Padding is `py-8` on tighter tables, `py-10` on standard ones.

### Pagination

`:paginate="$this->collection"` where the computed returns a paginator. Omit the
attribute entirely for a plain `Collection`. See [pagination.md](pagination.md).

> Two older files (`⚡cohorts.blade.php`, `⚡trainings.blade.php`) pass `:paginated`
> with a plain `Collection`. That attribute is not a Flux prop and does nothing —
> **do not copy it.** Use `:paginate` with a real paginator, or nothing.

### Wide tables

```blade
<flux:table class="min-w-200" :paginate="$this->students">
```

### Grouped tables

A page can render one table per group, each inside its own labelled block:

```blade
@foreach ($this->faqCases as $faqType)
    @php($questions = $this->grouped[$faqType->value] ?? collect())

    <div class="space-y-3">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <flux:heading size="md">{{ $faqType->defaultTitle() }}</flux:heading>
                <flux:text class="mt-0.5 text-sm">{{ $faqType->description() }}</flux:text>
            </div>

            <flux:button size="sm" icon="plus" wire:click="create('{{ $faqType->value }}')">
                Add question
            </flux:button>
        </div>

        <flux:table>
            …
        </flux:table>
    </div>
@endforeach
```

### Passing ids to actions

```blade
wire:click="edit({{ $item->id }})"                 {{-- model bound by id --}}
wire:click="create('{{ $faqType->value }}')"       {{-- enum bound by value, quoted --}}
```

## Why

- `wire:key` is what lets Livewire patch a single row after a toggle instead of
  re-rendering the whole table — without it, DOM state (open dropdowns, focus) is lost.
- `$item` everywhere means the body of any two tables in the project diff cleanly.
- Actions-last plus the two fixed action shapes means an admin's muscle memory works on
  every screen.
- The confirm modal on destructive items is the only guard between a mis-click and a
  deleted record — there is no undo. It is a Flux dialog rather than `wire:confirm`
  because the browser's own `confirm()` cannot be styled, cannot carry an amount or a
  consequence, and is suppressed outright in some in-app browsers.
- Em dash for empty values keeps column widths stable and makes "no value" visibly
  intentional rather than a rendering bug.

## Example

The full body of `⚡students.blade.php`'s table — the reference implementation:

```blade
<flux:table :paginate="$this->students">
    <flux:table.columns>
        <flux:table.column>Student</flux:table.column>
        <flux:table.column>Phone</flux:table.column>
        <flux:table.column>Roles</flux:table.column>
        <flux:table.column>Cohorts</flux:table.column>
        <flux:table.column>Paid</flux:table.column>
        <flux:table.column>Status</flux:table.column>
        <flux:table.column>Joined</flux:table.column>
        <flux:table.column>Actions</flux:table.column>
    </flux:table.columns>
    <flux:table.rows>
        @forelse ($this->students as $item)
            <flux:table.row wire:key="student-{{ $item->id }}">
                <flux:table.cell>
                    <div class="flex items-center gap-3">
                        <x-dashboard.avatar :user="$item" />
                        <div class="min-w-0">
                            <div class="font-medium">{{ $item->name }}</div>
                            <div class="text-xs text-slate-500">{{ $item->email }}</div>
                        </div>
                    </div>
                </flux:table.cell>
                <flux:table.cell>{{ $item->phone_number ?: '—' }}</flux:table.cell>
                <flux:table.cell><x-dashboard.user-role :user="$item" /></flux:table.cell>
                <flux:table.cell>{{ number_format($item->enrolled_count) }}</flux:table.cell>
                <flux:table.cell>{!! kMoneyFormat(($item->amount_paid_sum ?? 0) / 100) !!}</flux:table.cell>
                <flux:table.cell><x-status :status="$item->status" /></flux:table.cell>
                <flux:table.cell>{{ $item->createdAtHuman() }}</flux:table.cell>
                <flux:table.cell>
                    <div class="flex gap-2">
                        <flux:button icon="eye" variant="primary" size="sm" :href="route('admin.user', $item)" wire:navigate title="View profile" />
                        <flux:button icon="shield-check" variant="filled" size="sm" wire:click="openRoleManager({{ $item->id }})" title="Manage roles" />
                    </div>
                </flux:table.cell>
            </flux:table.row>
        @empty
            <flux:table.row>
                <flux:table.cell colspan="8">
                    <x-dashboard.workspace-no-record label="Students" icon="academic-cap" text="No students match the current filters." />
                </flux:table.cell>
            </flux:table.row>
        @endforelse
    </flux:table.rows>
</flux:table>
```

## Template

See the block at the top of this file, and the full page template in
[pages.md](pages.md) shape A / B.

## Avoid

- Raw `<table>` / `<thead>` / `<tbody>` / `<tr>` / `<td>`.
- Missing `wire:key`.
- `@foreach` without `@empty`.
- A `colspan` that does not match the column count.
- Actions in a column other than the last.
- A destructive action without a confirm modal, or one still using `wire:confirm`.
- `{{ $item->amountMoney() }}` — money needs `{!! !!}` (it returns `&#8358;`).
- `{{ $item->created_at->format('d/m/Y') }}` — use `createdAtHuman()`.
- `{{ $item->status->value }}` or a hard-coded badge colour — use `<x-status>`.
- Blank cells for missing values — use `?: '—'`.
- `:paginated` (not a real Flux prop; two legacy files use it inertly).
- Querying inside the loop (`$item->admissions()->count()`) — use `withCount()`.
