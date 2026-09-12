# ui.md

## Rule

**Flux UI free v2 first, Tailwind v4 second, hand-rolled markup last.**
If Flux has a component for it, use Flux. Only reach for raw Tailwind when Flux does
not cover the shape (metric tiles, chart bars, marketing sections).

### The Flux components actually used

```
flux:card          flux:heading      flux:text       flux:subheading
flux:button        flux:input        flux:select     flux:select.option
flux:textarea      flux:switch       flux:checkbox   flux:field
flux:label         flux:error        flux:description
flux:table         flux:table.columns flux:table.column
flux:table.rows    flux:table.row    flux:table.cell
flux:modal         flux:modal.close  flux:modal.trigger
flux:dropdown      flux:menu         flux:menu.item  flux:menu.separator
flux:badge         flux:callout      flux:callout.text
flux:separator     flux:icon         flux:icon.<name>
flux:tooltip       flux:tooltip.content
flux:toast         flux:toast.group
flux:brand         flux:navbar       flux:navlist
```

Custom brand icons live in `resources/views/flux/icon/` (`google`, `facebook`, `x`,
`whatsapp`, `telegram`, `linkedin`, `instagram`, `tiktok`, `youtube`, `crown`) and are
used as `icon="google"`.

### Button conventions

| Purpose | Markup |
| --- | --- |
| Primary action | `<flux:button variant="primary" icon="plus" wire:click="create">Add thing</flux:button>` |
| Submit | `<flux:button type="submit" variant="primary">Save thing</flux:button>` |
| Cancel | `<flux:modal.close><flux:button variant="ghost" type="button">Cancel</flux:button></flux:modal.close>` |
| Row action | `<flux:button icon="eye" variant="primary" size="sm" :href="route(…)" wire:navigate title="View profile" />` |
| Secondary row action | `<flux:button icon="shield-check" variant="filled" size="sm" wire:click="…" title="Manage roles" />` |
| Kebab menu trigger | `<flux:button icon="ellipsis-vertical" variant="ghost" size="sm" />` |
| Destructive menu item | `<flux:menu.item icon="trash" variant="danger" wire:click="confirmDelete({{ $item->id }})">Delete</flux:menu.item>` |

Variants in use: `primary`, `filled`, `ghost`, `danger`, `subtle`.
Sizes: default and `sm`. Icon-only buttons carry a `title`.

### Confirmations

There is no `wire:confirm` anywhere in this project and no `confirm()` in any script.
Every destructive action opens `<x-dashboard.confirm-modal>` instead — a Flux dialog
that can carry the amount, the penalty, or the consequence the browser's own box
cannot, and that still appears in the in-app browsers that suppress `confirm()`:

```blade
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
```

Everything after `name` is optional. `variant` and `tone` default to the destructive
pair (`danger` / `rose`) — a confirmation that is not destructive passes `primary`
with `sky` or `emerald`. The component closes itself on click, so the action does not
call `Flux::modal()->close()`.

A single fixed target opens it from the trigger with
`x-on:click="$flux.modal('deleteModal').show()"`. A row inside a loop cannot: it calls
a `confirm{Action}(int $id)` that stores the id and opens the modal server-side, and
the write itself then reads that stored id. See [tables.md](tables.md).

### Card + heading block

Every page section opens with this exact block:

```blade
<flux:card class="space-y-6">
    <div>
        <flux:heading level="2" size="lg">Trainings</flux:heading>
        <flux:text class="mt-1">Create and manage the training programs offered by your organization.</flux:text>
    </div>
    …
</flux:card>
```

With an action on the right:

```blade
<div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
    <div>
        <flux:heading level="2" size="lg">Trainings</flux:heading>
        <flux:text class="mt-1">…</flux:text>
    </div>

    <flux:button variant="primary" icon="plus" wire:click="create">Add training</flux:button>
</div>
```

Heading levels: `level="1" size="xl"` for a page title (via `x-dashboard.tab-nav`),
`level="2" size="lg"` for a card, `size="md"` for a sub-section, `size="lg"` inside a
modal.

### Spacing scale

| Context | Class |
| --- | --- |
| Page root | `space-y-6` (`space-y-7` on dashboards) |
| Card interior | `space-y-6`, or `space-y-5` when it holds a filter bar |
| Sub-group inside a card | `space-y-3` |
| Metric grid | `grid gap-4 sm:grid-cols-3` |
| Paired form fields | `grid gap-4 sm:grid-cols-2` (`gap-5` where fields are tall) |
| Button rows | `flex justify-end gap-3` |
| Filter bar | `flex flex-col gap-3 sm:flex-row` |
| Header row | `flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between` |
| Text under a heading | `class="mt-1"` |

### Colour system

Defined in `resources/css/app.css` (Tailwind v4 CSS-first `@theme`):

- **Accent is lime.** `--color-accent: var(--color-lime-400)`,
  `--color-primary: var(--color-lime-500)`, `--color-secondary: var(--color-emerald-500)`.
- **Flux's `zinc` is remapped to `slate`** — so `zinc-*` and `slate-*` are the same
  palette. New code writes `slate-*`; Flux internals emit `zinc-*`. Both are correct.
- Surfaces: `--color-background-light: #f5f6f8`, `--color-background-dark: #101622`,
  `--color-surface-light: #ffffff`, `--color-surface-dark: #23252a`.
- Fonts: `--font-sans: Roboto`, `--font-heading` / `--font-outfit: Outfit`,
  `--font-libre: Libre Baskerville`. Page titles use `font-heading`.
- Easing: `--ease-out-strong`, `--ease-in-out-strong`, `--ease-drawer`.
- Animations: `animate-fade-in`, `animate-fade-in-up`, `animate-slide-up`,
  `animate-marquee`, `animate-float`.

### The tone palette

Six tones, used by `x-dashboard.stat-card`, `x-dashboard.icon-box`, and any coloured
chip. Each is a light/dark pair:

```
lime     bg-lime-100 text-lime-700        dark:bg-lime-400/10 dark:text-lime-300
emerald  bg-emerald-50 text-emerald-700   dark:bg-emerald-400/10 dark:text-emerald-300
sky      bg-sky-50 text-sky-700           dark:bg-sky-400/10 dark:text-sky-300
amber    bg-amber-50 text-amber-700       dark:bg-amber-400/10 dark:text-amber-300
rose     bg-rose-50 text-rose-600         dark:bg-rose-400/10 dark:text-rose-300
slate    bg-slate-100 text-slate-700      dark:bg-slate-800 dark:text-slate-200
```

Pass one as `tone="sky"`. Do not invent a seventh.

### Status colour

Never hand-pick a badge colour. `<x-util.e-badge :enum="$model->status" />` reads
`$status->color()`, which maps through `config('_setups.status-color-map')`:

```
success → green    danger → red    warning → amber    primary → blue    default → zinc
```

### Dark mode

**Every colour utility needs a `dark:` counterpart.** Flux components handle their own.
Hand-written markup does not:

```blade
class="text-sm text-slate-500 dark:text-slate-400"
class="rounded-xl border border-slate-200 p-5 dark:border-white/10"
class="font-heading text-2xl font-bold text-slate-950 dark:text-white"
class="bg-slate-50 dark:bg-slate-950"
```

Dark borders and fills use white alpha rather than a slate step:
`dark:border-white/10`, `dark:bg-white/5`, `dark:hover:bg-white/10`.

The theme toggle is Flux's: `<flux:switch x-data x-model="$flux.dark" />`.

### Responsive

Mobile-first. Breakpoints in use: `sm:`, `md:`, `lg:`, occasionally `xl:`.

```blade
<div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
<section class="grid gap-4 sm:grid-cols-3">
<flux:button class="press w-full sm:w-auto">Add User</flux:button>
<flux:table class="min-w-200" :paginate="$this->students">
```

Wide tables get `class="min-w-200"` and scroll inside their card.

### Alpine

Alpine ships with Livewire; there is no separate import. Custom `Alpine.data()`
components are registered in `resources/js/app.js`: `animationOnScroll`,
`countdownTimer`.

Patterns in use:

```blade
x-data="{ mobileSidebarOpen: false }"
x-cloak x-show="mobileSidebarOpen"
x-transition.opacity
x-transition:enter="transition duration-200 ease-drawer"
x-transition:enter-start="-translate-x-full"
@click="mobileSidebarOpen = false"
@scroll.window.passive="scrolled = window.scrollY > 8"
x-on:attr.window="title = $event.detail.title"
x-init="$nextTick(() => $refs.activeTab?.scrollIntoView({ block: 'nearest', inline: 'nearest' }))"
x-ref="input"
x-model="$flux.dark"
```

Keep Alpine to presentation: open/close, scroll position, upload progress, optimistic
toggles. State that matters goes to Livewire.

### Conditional classes

```blade
@class([
    'grid size-10 place-items-center rounded-lg',
    'bg-emerald-50 text-emerald-700 dark:bg-emerald-400/10 dark:text-emerald-300' => $tone === 'emerald',
    'bg-sky-50 text-sky-700 dark:bg-sky-400/10 dark:text-sky-300' => $tone === 'sky',
])
```

In components, merge into the caller's classes:

```blade
{{ $attributes->class([
    'py-12 text-center text-sm text-slate-500',
    'rounded-lg border border-zinc-300 dark:border-zinc-700' => $border,
])->merge() }}
```

### Toasts

Registered once in `components/layouts/base.blade.php`:

```blade
@persist('toast')
    <flux:toast.group expanded>
        <flux:toast />
    </flux:toast.group>
@endpersist
```

Fired only through `respondSuccess()` / `respondError()` / `respondPrimary()`.
Never call `Flux::toast()` directly from a page.

### Flash messages

`layouts/app.blade.php` renders two flash keys above the page slot:

```blade
@session('status')
    <flux:callout color="lime" class="mt-6 text-sm">{!! session('status') !!}</flux:callout>
@endsession
@session('error')
    <flux:callout variant="danger" icon="x-circle" class="mt-6 text-sm">{!! session('error') !!}</flux:callout>
@endsession
```

Keys in use across the app: `status`, `error`, `success`, `primary`, `message`,
`accessDenied`.

### Callouts

```blade
<flux:callout icon="information-circle" variant="secondary">
    <flux:callout.text>
        Answers use the same markdown as the policy pages. Keep them short.
    </flux:callout.text>
</flux:callout>
```

### Icons

Heroicons names, kebab-case: `plus`, `pencil-square`, `trash`, `eye`, `eye-slash`,
`ellipsis-vertical`, `magnifying-glass`, `academic-cap`, `check-badge`, `banknotes`,
`users`, `cog-6-tooth`, `book-open`, `calendar-days`, `video-camera`, `credit-card`,
`shield-check`, `information-circle`, `cloud-arrow-up`.

`<flux:icon :name="$icon" class="size-5" />` when dynamic;
`<flux:icon.check-badge class="size-6" />` when static.

### Markdown output

Compiled markdown is rendered inside `.markdown-prose`, and wide tables inside
`.table-scroll` (both added by `MarkdownService`):

```blade
<div class="markdown-prose">{!! $this->preview !!}</div>
```

## Why

- Flux-first keeps a single visual language across 45 pages with no bespoke CSS.
- Remapping `zinc` → `slate` in `@theme` lets Flux's internals and the project's own
  markup share one grey without touching Flux source.
- The six-tone palette caps the colour vocabulary, so a new dashboard cannot introduce
  a seventh accent.
- Routing every status colour through `config/_setups.php` means nine different status
  enums render consistently and a colour change is one file.
- White-alpha borders in dark mode (`dark:border-white/10`) read correctly on any dark
  surface, where a fixed `dark:border-slate-700` would not.

## Example

`resources/views/components/dashboard/stat-card.blade.php`:

```blade
@props(['label', 'value', 'icon', 'change' => null, 'tone' => 'slate'])

<flux:card>
    <div class="flex items-start justify-between gap-4">
        <div>
            <p class="text-sm font-medium text-slate-500 dark:text-slate-400">{!! $label !!}</p>
            <p class="mt-2 font-heading text-2xl font-bold tracking-tight text-slate-950 dark:text-white">
                {!! $value !!}
            </p>
        </div>
        <span @class([
            'grid size-10 place-items-center rounded-lg',
            'bg-emerald-50 text-emerald-700 dark:bg-emerald-400/10 dark:text-emerald-300' => $tone === 'emerald',
            'bg-amber-50 text-amber-700 dark:bg-amber-400/10 dark:text-amber-300' => $tone === 'amber',
            'bg-sky-50 text-sky-700 dark:bg-sky-400/10 dark:text-sky-300' => $tone === 'sky',
            'bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-200' => $tone === 'slate',
        ])>
            <flux:icon :name="$icon" class="size-5" />
        </span>
    </div>
    @if ($change)
        <p class="mt-5 text-xs font-medium text-slate-500 dark:text-slate-400">{!! $change !!}</p>
    @endif
</flux:card>
```

## Template

A page section, complete:

```blade
<div class="space-y-6">
    <flux:card class="space-y-6">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
            <div>
                <flux:heading level="2" size="lg">Section title</flux:heading>
                <flux:text class="mt-1">One sentence saying what this is for.</flux:text>
            </div>

            <flux:button variant="primary" icon="plus" wire:click="create">Add item</flux:button>
        </div>

        <flux:separator variant="subtle" />

        <div class="grid gap-4 sm:grid-cols-2">
            …
        </div>
    </flux:card>
</div>
```

## Avoid

- Hand-rolled `<button>`, `<input>`, `<table>`, `<dialog>` where Flux has a component.
- Any colour utility without a `dark:` counterpart.
- Hard-coded status colours — `<x-util.e-badge>` only.
- A tone outside the six-tone palette.
- Inline `style="…"` (the one exception is the dotted radial background in
  `layouts/auth.blade.php`).
- Arbitrary values (`w-[437px]`) where a scale step exists.
- `@apply` in new CSS — the CSS file uses it only in `@layer base`.
- Calling `Flux::toast()` directly from a page — go through `respond*()`.
- A second toast group, or a toast rendered per page.
- Alpine holding data that the server needs.
- `wire:navigate` on an external link, or an internal link without it.
