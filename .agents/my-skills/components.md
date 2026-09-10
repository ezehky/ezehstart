# components.md

## Rule

Blade components live in `resources/views/components/{group}/{kebab-case}.blade.php`
and are used as `<x-group.kebab-case />`. **There are no PHP component classes** —
`app/View/Components/` does not exist. Every component is a single Blade file opening
with `@props([...])`.

### The groups

| Group | Purpose | Examples |
| --- | --- | --- |
| `dashboard/` | Authenticated workspace chrome and tiles | `avatar`, `stat-card`, `stat-pill`, `mini-stat`, `icon-box`, `progress-ring`, `item`, `sidebar`, `top-navigation`, `tab-nav`, `workspace-no-record`, `user-roles`, `user-roles-modal`, `confirm-modal` |
| `chart/` | The SVG chart set — see [dashboard.md](dashboard.md) | `chart`, `chart.svg`, `chart.line`, `chart.area`, `chart.bar`, `chart.point`, `chart.axis`, `chart.axis.grid`, `chart.axis.line`, `chart.axis.tick`, `chart.cursor`, `chart.tooltip`, `chart.tooltip.heading`, `chart.tooltip.value` |
| `form/` | Field wrappers Flux does not provide | `markdown-field`, `image-field`, `file-field`, `number-field`, `phone-field`, `password` |
| `site/` | Public marketing sections | `hero`, `features`, `trainings`, `cohorts`, `trainers`, `testimonials`, `faq`, `cta`, `contact`, `footer`, `navbar`, `brand`, `reveal`, `section-heading`, `empty-state`, `legal-page`, `training-card`, `cohort-card` |
| `training/` | Cohort/class domain widgets | `cohort-card`, `cohort-status`, `cohort-actions`, `cohort-completion`, `cohort-locked-callout`, `class-session-form`, `banner-badge`, `refund-destination`, `trainer-drop-links` |
| `finance/` | Money widgets | `withdraw-button-card`, `withdraw-modal` |
| `layouts/` | Page shells | `base`, `email`, `site-master`, `email/theme`, `email/label-value` |
| `lv/` | Embedded **Livewire** SFCs | `⚡notifications`, `⚡newsletter-form` |
| *(root)* | `status.blade.php` | `<x-status :status="…" />` |

### `@props`

Always first, always an associative array with defaults for everything optional:

```blade
@props(['label', 'value', 'icon', 'change' => null, 'tone' => 'slate'])
```

Required props have no default. Optional props default to `null`, `false`, or a
sensible literal.

### Attribute merging

Components that wrap a single element merge the caller's attributes:

```blade
<flux:badge :color="$status->color()" {{ $attributes->merge(['size' => 'sm', 'inset' => 'top bottom']) }}>
```

```blade
<div {{ $attributes->class([
        'py-12 text-center text-sm text-slate-500',
        'rounded-lg border border-zinc-300 dark:border-zinc-700' => $border,
    ])->merge() }}>
```

Thin Flux wrappers merge defaults so the caller can override any of them:

```blade
{{-- form/phone-field.blade.php --}}
<flux:input
    {{
        $attributes->merge([
            'label' => 'Phone Number',
            'placeholder' => 'e.g. 08012345678',
            'badge' => 'required',
        ])
    }}
    clearable
    inputmode="numeric"
/>
```

### Reading `wire:model` from attributes

Form components pull the bound property name out of `$attributes` to wire up the error
message:

```blade
@php
    $errorName = $attributes->get('wire:model') ?? $attributes->get('name');
    $wireModel = $attributes->get('wire:model');
@endphp

…

@if ($errorName)
    <flux:error name="{{ $errorName }}" />
@endif
```

Or inline:

```blade
<flux:error name="{{ $attributes->get('wire:model') }}" />
```

### Derived values

Computed with `@php` at the top or a `match` expression:

```blade
@php
$sizeClasses = match ($size) {
    'sm' => 'size-9',
    'md' => 'size-12',
    'lg' => 'size-16',
    default => throw new \InvalidArgumentException("Invalid size: {$size}"),
};
@endphp
```

Throwing on an invalid enum-like prop is the project's habit — it turns a typo into a
loud failure instead of a silently unstyled element.

Tone mapping uses a `match` with a `default`:

```blade
@php
    $toneClasses = match ($tone) {
        'sky' => 'bg-sky-50 text-sky-700 dark:bg-sky-400/10 dark:text-sky-300',
        'amber' => 'bg-amber-50 text-amber-600 dark:bg-amber-400/10 dark:text-amber-300',
        'rose' => 'bg-rose-50 text-rose-600 dark:bg-rose-400/10 dark:text-rose-300',
        'emerald' => 'bg-emerald-50 text-emerald-700 dark:bg-emerald-400/10 dark:text-emerald-300',
        'slate' => 'bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-300',
        default => 'bg-lime-100 text-lime-700 dark:bg-lime-400/10 dark:text-lime-300',
    };
@endphp
```

### Slots

Named slots are declared with `@isset` and rendered with `{!! !!}` when they carry
markup:

```blade
@isset($title)
    <h2 class="mt-2 font-heading text-3xl font-bold dark:text-white">{!! $title !!}</h2>
@endisset

@if ($button)
    <div class="mt-6">{!! $button !!}</div>
@endif
```

Callers pass them with `<x-slot:title>`.

### Escaping

- `{{ }}` for plain text from user input.
- `{!! !!}` for component props that are **intended** to carry markup (`$label`,
  `$value`, `$text`, `$change`, slot content) and for `kMoneyFormat()` output, which
  returns the `&#8358;` entity.

### Passing props

```blade
<x-dashboard.stat-card
    :label="$metric['label']"
    :value="$metric['value']"
    :icon="$metric['icon']"
    :tone="$metric['tone']"
/>

<x-status :status="$item->status" />
<x-dashboard.avatar :user="$item" size="md" />
<x-dashboard.user-role :user="$item" />
<x-dashboard.workspace-no-record label="Students" icon="academic-cap" text="No students match the current filters." />
```

Shorthand `:$variable` when the prop name equals the variable name:

```blade
<x-dashboard.sidebar :$navigationLinks :$dashboardLinks :$currentRole :$dashboardRoute />
```

### Livewire components as components

`resources/views/components/lv/⚡notifications.blade.php` is a full Livewire SFC used as:

```blade
<livewire:lv.notifications />
```

Reserve this for stateful widgets appearing on many pages (the notification bell, the
newsletter form). Everything else is a plain Blade component.

### When to create a component

Create one when the same markup appears in **three or more** places, or when the markup
needs a `match` over a variant. Otherwise inline it.

## Why

- Class-less Blade components keep the whole component in one file with no
  `app/View/Components/` parallel tree to navigate.
- `$attributes->merge()` on thin wrappers means `<x-form.phone-field>` is a *default
  set*, not a wall — a caller can still pass `label="Mobile"` or `badge="optional"`.
- Reading `wire:model` from `$attributes` lets a form component render its own error
  without the caller repeating the field name.
- Throwing on an unknown `size` catches typos at first render rather than shipping an
  unstyled avatar.
- `{!! !!}` on `$label` / `$value` is deliberate: dashboard tiles pass money entities
  and small inline markup, and those props are always project-authored, never user
  input.

## Example

`resources/views/components/status.blade.php` — the smallest and most-used component:

```blade
@props(['status'])
<flux:badge :color="$status->color()" {{ $attributes->merge(['size' => 'sm', 'inset' => 'top bottom']) }}>
    {{ kBreakText($status->label()) }}
</flux:badge>
```

`resources/views/components/dashboard/workspace-no-record.blade.php`:

```blade
@props([
    'label' => null,
    'icon' => 'folder-open',
    'text' => 'This area is ready for the related cohort records. …',
    'button' => null,
    'border' => false,
])
<div {{ $attributes->class([
        'py-12 text-center text-sm text-slate-500',
        'rounded-lg border border-zinc-300 dark:border-zinc-700' => $border,
    ])->merge() }}>
    @if ($icon)
        <flux:icon name="{{ $icon }}" class="mx-auto size-8 text-slate-400" />
    @endif
    @if ($label)
        <flux:heading level="2" size="lg" class="mt-4">{!! kBreakText($label) !!}</flux:heading>
    @endif
    <flux:text class="mx-auto mt-2 max-w-md">{!! $text !!}</flux:text>

    @if ($button)
        <div class="mt-6">{!! $button !!}</div>
    @endif
</div>
```

## Template

```blade
{{-- resources/views/components/dashboard/metric-row.blade.php --}}
@props([
    'label',
    'value',
    'icon' => 'chart-bar',
    'tone' => 'slate',
    'hint' => null,
])

@php
    $toneClasses = match ($tone) {
        'sky' => 'bg-sky-50 text-sky-700 dark:bg-sky-400/10 dark:text-sky-300',
        'amber' => 'bg-amber-50 text-amber-700 dark:bg-amber-400/10 dark:text-amber-300',
        'emerald' => 'bg-emerald-50 text-emerald-700 dark:bg-emerald-400/10 dark:text-emerald-300',
        'rose' => 'bg-rose-50 text-rose-600 dark:bg-rose-400/10 dark:text-rose-300',
        'lime' => 'bg-lime-100 text-lime-700 dark:bg-lime-400/10 dark:text-lime-300',
        default => 'bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-200',
    };
@endphp

<div {{ $attributes->class('flex items-center justify-between gap-4')->merge() }}>
    <div class="min-w-0">
        <p class="text-sm font-medium text-slate-500 dark:text-slate-400">{!! $label !!}</p>
        <p class="mt-1 font-heading text-xl font-bold text-slate-950 dark:text-white">{!! $value !!}</p>
        @if ($hint)
            <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">{!! $hint !!}</p>
        @endif
    </div>

    <span class="grid size-10 shrink-0 place-items-center rounded-lg {{ $toneClasses }}">
        <flux:icon :name="$icon" class="size-5" />
    </span>
</div>
```

Used as:

```blade
<x-dashboard.metric-row :label="$metric['label']" :value="$metric['value']" tone="sky" />
```

## Avoid

- `app/View/Components/` class components — none exist.
- A component without `@props`.
- Overwriting the caller's classes instead of `$attributes->class(...)->merge()`.
- Querying the database inside a component (`Cohort::all()` in a `@php` block).
- Calling a Service inside a component — pass the data in.
- Business logic in a component; only presentation branching belongs there.
- Creating a component for markup used once or twice.
- A tone outside the six-tone palette (see [ui.md](ui.md)).
- Missing `dark:` variants on hand-written colour classes.
- `{!! !!}` on genuinely user-supplied strings (names, questions, descriptions) — only
  on project-authored markup props and compiled markdown from `MarkdownService`.
