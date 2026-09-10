# layouts.md

## Rule

Five layout surfaces.

| Layout | File | Used by |
| --- | --- | --- |
| `layouts::app` | `resources/views/layouts/app.blade.php` | **default** for every authenticated page |
| `layouts::auth` | `resources/views/layouts/auth.blade.php` | guest pages, via `#[Layout('layouts::auth')]` |
| `layouts::site` | `resources/views/layouts/site.blade.php` | public Livewire pages (the blog), via `#[Layout('layouts::site')]` |
| `x-layouts.base` | `resources/views/components/layouts/base.blade.php` | the HTML document, wrapped by both above |
| `x-layouts.site-master` | `resources/views/components/layouts/site-master.blade.php` | public marketing pages (controller-rendered) |
| `x-layouts.email` | `resources/views/components/layouts/email.blade.php` | every Mailable view |

`layouts::app` is the **Livewire default** (`livewire.component_layout`). Authenticated
pages declare nothing.

### Guest pages

```php
use Livewire\Attributes\Layout;

new #[Layout('layouts::auth')] class extends Component
```

### Public pages

A public Livewire page has no sidebar and no navigation links, so `layouts::app` cannot
render it — it would fail on the `$navigationLinks` the sidebar expects. `layouts::site`
is the header/footer shell those pages use instead:

```php
new #[Layout('layouts::site')] class extends Component
```

### `x-layouts.base` — the document

Owns everything global:

- `<title>` built from `config('_setups.title')` + the configured site name
- SEO / Open Graph / Twitter meta from `config('_setups.metadata')`, populated by
  `kSetMetaData()`
- favicon from `$_configs['favicon']`
- `@livewireStyles`, `@fluxAppearance`, `@vite([...])`, `@fluxScripts`,
  `@livewireScriptConfig`
- the Bunny-hosted Inter webfont
- `@stack('styles')` / `@stack('scripts')`
- `x-data="animationOnScroll"` on `<body>`
- the **single** toast group, inside `@persist('toast')`

```blade
{{-- TOAST --}}
@persist('toast')
    <flux:toast.group expanded>
        <flux:toast />
    </flux:toast.group>
@endpersist
{{-- TOAST --}}
```

Never add a second toast group, and never re-declare `@vite` on a page.

Body classes are merged so a wrapping layout can extend them:

```blade
<body
    x-data="animationOnScroll"
    {{ $attributes->class([
        'antialiased font-sans',
        'custom-scrollbar',
        'bg-background-light text-[#0d121c] transition-colors duration-200 ease-out-strong dark:bg-background-dark dark:text-slate-100'
    ])->merge() }}
>
```

### `layouts::app` — the workspace shell

```blade
<x-layouts.base class="min-h-screen">
    <div x-data="{ mobileSidebarOpen: false }" class="min-h-screen bg-slate-50 dark:bg-slate-950">
        <div class="fixed inset-y-0 left-0 z-30 hidden lg:block">
            <x-dashboard.sidebar :$navigationLinks :$dashboardLinks :$currentRole :$dashboardRoute />
        </div>

        {{-- mobile drawer, x-cloak + x-transition --}}

        <div class="min-h-screen lg:pl-64">
            <x-dashboard.top-navigation :current-role="$currentRole" />
            <main class="mx-auto w-full max-w-[1600px] px-4 py-7 sm:px-6 lg:px-8">
                @session('status')
                    <flux:callout color="lime" class="mt-6 text-sm">{!! session('status') !!}</flux:callout>
                @endsession
                @session('error')
                    <flux:callout variant="danger" icon="x-circle" class="mt-6 text-sm">{!! session('error') !!}</flux:callout>
                @endsession

                {{ $slot }}
            </main>
        </div>
    </div>
</x-layouts.base>
```

Fixed facts:

- Sidebar width `lg:pl-64`, content `max-w-[1600px]`, padding `px-4 py-7 sm:px-6 lg:px-8`
- `status` and `error` flashes render **above** the slot — nothing else does
- The mobile drawer uses `ease-drawer` easing and `x-cloak`

### Where the layout data comes from

`$navigationLinks`, `$dashboardLinks`, `$currentRole`, `$dashboardRoute` are **shared
from the middleware**, not passed by pages:

```php
// UserService::middlewareGeneralCheck()
View::share([
    'dashboardRoute' => match ($role) { … },
    'currentRole' => $role,
    'navigationLinks' => kPageNavigationLinks($role->value),
    'dashboardLinks' => $dashboardLinks,
]);
```

`$_configs` (logo, logo-dark, name, favicon, email, phone, address, social-handles) is
shared from `AppServiceProvider::boot()`:

```php
$configs = kSiteConfig(keys: ['logo', 'logo-dark', 'name', 'favicon', 'email', 'phone', 'address', 'social-handles']);
View::share(['_configs' => $configs]);
```

A page never has to provide any of these.

### `layouts::auth` — the split guest screen

Left column: brand, named slots, the page's `{{ $slot }}`, optional social buttons,
optional extra. Right column: a lime marketing panel (hidden below `lg`).

Named slots:

| Slot | Renders as |
| --- | --- |
| `$tag` | small lime eyebrow text |
| `$title` | `h2.font-heading.text-3xl` |
| `$description` | muted paragraph |
| `$socialConnect` | when set **and** `SocialProviderEnum::activeCases()` is non-empty, renders the "or" divider and provider buttons |
| `$extra` | anything below the form |

```blade
<x-slot:tag>Welcome back</x-slot:tag>
<x-slot:title>Sign in to your account</x-slot:title>
<x-slot:description>Enter your details to continue.</x-slot:description>
<x-slot:socialConnect />
```

Multi-step guest flows update the slots without a re-render by dispatching `attr`:

```php
$this->dispatch('attr', tag: $tag, title: $title, description: $description);
```

```blade
<x-slot:title>
    <span x-data="{ title: @js($title) }" x-on:attr.window="title = $event.detail.title" x-html="title"></span>
</x-slot:title>
```

### `x-layouts.email`

Wraps every mail view. `WithEmailResolver` injects `$emailConfig` (name, logo,
contact-email, email) into the view data of every Mailable, so email templates never
call `kSiteConfig()` themselves.

`components/layouts/email/theme.blade.php` and `email/label-value.blade.php` supply the
inline-styled palette and the label/value row used inside mail bodies.

### Page titles

`kSetSiteTitle($parent, $child, $grandchild)` in `mount()` drives:

1. `<title>` — `"Config / Faqs | Site Name"`
2. the sidebar active state, via `kCheckActiveTitle()` slug comparison
3. the default `og:title` (through `kSetMetaData()`)

For hand-written titles that must not be title-cased, pass `format: false`:

```php
kSetSiteTitle('Learn Web Development, UI/UX Design & Data Science', format: false);
```

(`format: true` would turn "UI/UX" into "Ui/Ux".)

Related helpers: `kSetMetaData()`, `kUpdateSiteTitle()`, `kPrintSiteTitle()`,
`kDestructSiteTitle()`, `kPauseSiteTitle()`, `kBackForwardLink()`.

## Why

- Sharing nav data from middleware means every authenticated page gets the correct
  sidebar with zero page-level code, and the role check that builds it is the same one
  that authorised the request.
- One toast group in `@persist` survives `wire:navigate` transitions, so a toast fired
  before a redirect still appears afterwards.
- Deriving the sidebar highlight from the page title (rather than the route name) lets
  one nav key cover several routes — the whole `cohort.*` family lights up "Cohorts".
- The auth layout's named slots let five different auth pages share one screen design
  while each supplies its own copy.

## Example

`⚡login.blade.php`:

```php
use Livewire\Attributes\Layout;

new #[Layout('layouts::auth')] class extends Component
{
    …
};
?>

<div>
    <x-slot:title>Welcome back</x-slot:title>
    <x-slot:description>Sign in to continue your training.</x-slot:description>
    <x-slot:socialConnect />

    <form wire:submit="login" class="mt-8 space-y-5">
        …
    </form>
</div>
```

An authenticated page:

```php
new class extends Component
{
    public function mount(): void
    {
        kSetSiteTitle('config', 'faqs');
    }
};
?>

<div class="space-y-6">
    …
</div>
```

## Template

```php
// Authenticated page — no layout attribute
new class extends Component
{
    public function mount(): void
    {
        kSetSiteTitle('finance', 'invoices');   // must match navigations.php keys
    }
};
?>

<div class="space-y-6">
    …
</div>
```

```php
// Guest page
use Livewire\Attributes\Layout;

new #[Layout('layouts::auth')] class extends Component { … };
?>

<div>
    <x-slot:tag>Invoices</x-slot:tag>
    <x-slot:title>Pay your invoice</x-slot:title>
    <x-slot:description>Enter the reference from your email.</x-slot:description>

    <form wire:submit="lookup" class="mt-8 space-y-5">…</form>
</div>
```

## Avoid

- `#[Layout('layouts::app')]` on an authenticated page — it is already the default.
- `@extends` / `@section` / `@yield` — this project is component-layout only.
- A new top-level layout file. Extend `x-layouts.base` if you genuinely need a new
  shell.
- Re-declaring `@vite`, `@livewireStyles`, `@fluxScripts`, or a toast group on a page.
- Passing `$navigationLinks` / `$_configs` from a page — they are `View::share`d.
- Skipping `kSetSiteTitle()`: no `<title>`, and the sidebar never highlights.
- `kSetSiteTitle()` segments that do not match the nav keys in
  `app/Helpers/navigations.php`.
- Title-casing a hand-written title — pass `format: false`.
- Rendering flash messages inside a page; `layouts::app` already renders `status` and
  `error`.
