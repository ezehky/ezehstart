# livewire.md

## Rule

**Every Livewire component in this project is a single-file component (SFC).**
There is no `app/Livewire/` directory and no class-based component anywhere.

### File anatomy

```
resources/views/pages/{workspace}/{group}/⚡{name}.blade.php
```

```php
<?php

use App\Enums\...;          // imports, alphabetically sorted (Pint)
use App\Models\...;
use App\Services\...;
use App\Traits\...;
use Flux\Flux;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component
{
    use WithFormResponseMessage;     // traits first

    public ?Faq $faq = null;         // properties, blank line between each

    public string $question = '';

    public function mount(): void { … }        // lifecycle

    #[Computed]                                 // computed properties
    public function grouped(): Collection { … }

    public function create(): void { … }        // actions
    public function edit(Faq $faq): void { … }

    protected function rules(): array { … }     // validation

    public function save(): bool { … }          // the write

    private function resetForm(): void { … }    // private helpers last
};
?>

<div class="space-y-6">
    …the Blade…
</div>
```

The `?>` after `};` is mandatory. The Blade that follows must have **exactly one root
element** — a `<div class="space-y-6">` or a `<form wire:submit="save">`.

### Registration

Components are discovered by namespace (from the default Livewire 4 config):

| Namespace | Directory |
| --- | --- |
| `pages::` | `resources/views/pages` |
| `layouts::` | `resources/views/layouts` |
| *(components)* | `resources/views/components`, `resources/views/livewire` |

Default layout: **`layouts::app`**. Pages do not declare it. Only guest pages override:

```php
new #[Layout('layouts::auth')] class extends Component
```

### Member order inside the class

1. `use` trait statements
2. Public properties (model props first, then scalars, then arrays/UI state)
3. `mount()`
4. Other lifecycle hooks (`updatedFoo()`, `hydrate()`)
5. `#[Computed]` properties
6. Public actions (`create`, `edit`, `toggleStatus`, `delete`, `save`)
7. `protected function rules()`
8. `protected` hooks/overrides
9. `private` helpers (`resetForm()`)

`rules()` sits immediately before `save()` in most files — keep them adjacent.

### Properties

```php
public ?Faq $faq = null;                              // nullable model = create/edit toggle
public Cohort $cohort;                                // route-bound model, non-null
public FaqTypeEnum $faq_type = FaqTypeEnum::GENERAL;  // enum-typed prop
public string $question = '';
public ?string $description = null;
public int $flow_order = 1;
public bool $status = true;                           // switches are plain bool
public array $faqCases;                               // option lists
```

- Props that map to DB columns are **`snake_case`, named exactly as the column**.
- Props that are pure UI state are `camelCase` (`$previewing`, `$accountStatus`).
- A **`bool` prop** backs every `flux:switch`; convert at the boundary with
  `StatusDefault::tryFrom((int) $this->status)` on save and
  `$model->status->boolValue()` on load.
- Route-model-bound props are typed non-nullable and populated by Livewire from the
  route parameter name: `public Cohort $cohort;` for `{cohort:slug}`.

### `mount()`

Always `: void`. Always sets the page title. Then loads route-bound state and fills the
form.

```php
public function mount(): void
{
    kSetSiteTitle('config', 'faqs');
    $this->faqCases = FaqTypeEnum::cases();
}
```

```php
public function mount(): void
{
    kSetSiteTitle('training', 'cohorts', $this->cohort->name);

    $this->loadCohort();
    $this->training = $this->cohort->training;

    $this->fill($this->cohort->only([
        'name', 'fee', 'compare_fee', 'location',
        'meeting_platform', 'meeting_link', 'status', 'description',
    ]));

    $this->registration_starts_at = $this->cohort->registrationStartsAtDatetimeForUpdate();
    $this->is_online = $this->cohort->is_online->boolValue();
}
```

`kSetSiteTitle($parent, $child, $grandchild)` drives the `<title>`, the breadcrumb, and
**sidebar active-state detection** (`kCheckActiveTitle` matches the slugged title
segments against the nav keys). The `$parent`/`$child` values must match the nav keys
in `app/Helpers/navigations.php`.

### `#[Computed]` properties

All derived data is computed, never assigned in `mount()`.

```php
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
```

Access as `$this->grouped` in PHP **and** in Blade. Invalidate after a write:

```php
unset($this->grouped);
unset($this->students, $this->metrics);   // several at once
```

Return types: `Collection` for collections, `array` for option lists and metric arrays,
`bool`/`int`/`string` for scalars. Paginators are left untyped
(`#[Computed] public function students()`) because the paginator type varies.

### Lifecycle hooks

`updated{Prop}()` is used for filter resets only:

```php
public function updatedSearch(): void
{
    $this->resetPage();
}
```

### Actions

- Read/UI actions return `void`: `create()`, `edit()`, `openRoleManager()`.
- Write actions return `bool` and end with `return $this->respondSuccess('…')`.
- Model parameters use implicit binding by id: `public function edit(Faq $faq): void`
  called from Blade as `wire:click="edit({{ $item->id }})"`.
- Enum parameters bind from their value:
  `public function create(FaqTypeEnum $type): void` called as
  `wire:click="create('{{ $faqType->value }}')"`.

### Feedback — never raw session flashes

Use `WithFormResponseMessage` (see [forms.md](forms.md)):

```php
$this->respondPrimary(if: $this->faq->isClean());        // "no changes" → warning toast + stop
$this->respondError($reason, if: $reason !== null);      // danger toast + stop
return $this->respondSuccess('The question has been saved.');
```

`respondPrimary` and `respondError` throw a `ValidationException` on the sentinel field
`errorExceptionCatchStopper` to halt execution — that is intentional, it is the
project's early-return mechanism inside Livewire actions.

### Modals

Modals are Flux modals driven from PHP:

```php
Flux::modal('faqModal')->show();
Flux::modal('faqModal')->close();
```

```blade
<flux:modal name="faqModal" class="md:w-3xl">
    <form wire:submit="save" class="space-y-6">
        …
        <div class="flex justify-end gap-3">
            <flux:modal.close>
                <flux:button variant="ghost" type="button">Cancel</flux:button>
            </flux:modal.close>
            <flux:button type="submit" variant="primary">Save question</flux:button>
        </div>
    </form>
</flux:modal>
```

### Redirects

```php
$this->redirectRoute('admin.site-config', navigate: true);   // inside a Livewire action
return to_route('user.enroll', $cohort)->with('error', $message);  // in a controller
return redirect()->intended(route('admin.dashboard'))->with($with); // after login
```

Always pass `navigate: true` from Livewire so the SPA navigation is preserved.

### Browser events

Dispatch with named arguments; listen with `x-on:{event}.window`.

```php
$this->dispatch('attr', tag: $tag, title: $title, description: $description);
$this->dispatch('attendance-synced', statuses: (object) $this->statuses);
```

```blade
<span x-data="{ tag: @js($tag) }" x-on:attr.window="tag = $event.detail.tag" x-html="tag"></span>
<div x-on:attendance-synced.window="values = Object.assign({}, $event.detail.statuses)">
```

### Traits in pages

```php
use WithPagination, WithUserRoleManager;          // listing with role management
use WithFileUploads, WithFormResponseMessage;     // settings page with uploads
use WithCohortAdmin;                              // any admin cohort tab
use WithCurriculumEditor, WithFileUploads;        // curriculum editor
```

Some traits declare an overridable hook the page implements:

```php
protected function afterRoleChange(): void
{
    unset($this->students, $this->metrics);
}
```

### Embedded Livewire components

Non-page SFCs live in `resources/views/components/lv/⚡name.blade.php` and are used as
`<livewire:lv.notifications />`. Only two exist (`⚡notifications`, `⚡newsletter-form`)
— reserve this for genuinely reusable stateful widgets.

## Why

- SFCs keep the class and its markup in one file, so a page is one thing to read and
  one thing to move. With ~45 pages, a parallel `app/Livewire` tree would double the
  navigation cost.
- Computed properties (rather than `mount()` assignment) mean a write only has to
  `unset()` the affected caches; nothing has to be manually recomputed, and nothing is
  serialised into the Livewire payload.
- `respond*()` centralises every user-facing outcome into three shapes, so toasts,
  inline errors and flash messages never drift between pages.
- Driving modals from PHP (`Flux::modal(...)->show()`) keeps open/close state on the
  server alongside the form data it belongs to.

## Example

The complete minimum viable page (`resources/views/pages/user/membership/⚡communities.blade.php`
is the shortest in the project at 27 lines):

```php
<?php

use Livewire\Component;

new class extends Component
{
    public function mount(): void
    {
        kSetSiteTitle('communities');
    }
};
?>

<div class="space-y-6">
    <flux:card>
        <flux:heading level="2" size="lg">Communities</flux:heading>
        <flux:text class="mt-1">…</flux:text>
    </flux:card>
</div>
```

## Template

```php
<?php

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
        return Thing::query()->latest()->get();
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
        $this->fill($thing->only(['name']));
        $this->status = $thing->status->boolValue();

        Flux::modal('thingModal')->show();
    }

    protected function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255', Rule::unique(Thing::class, 'name')->ignore($this->thing?->id)],
            'status' => ['boolean'],
        ];
    }

    public function save(): bool
    {
        // … see forms.md for the full save template …
    }

    private function resetForm(): void
    {
        $this->resetValidation();
        $this->reset('thing', 'name', 'status');
    }
};
?>

<div class="space-y-6">
    …
</div>
```

## Avoid

- Class-based Livewire components (`app/Livewire/Foo.php`). There are none.
- A `render()` method. The Blade below `?>` is the render.
- `public array $rules = [...]` or `#[Validate]` attributes — use `rules()`.
- Assigning derived collections in `mount()` — use `#[Computed]`.
- Forgetting `unset($this->computed)` after a write; the list will look stale.
- `session()->flash('success', …)` for in-page feedback — use `respondSuccess()`.
  (Flash **is** correct when you immediately redirect: `respondSuccess($msg, flash: true)`.)
- Missing `wire:key` on looped rows.
- Multiple root elements in the Blade half.
- Missing the `⚡` in the filename.
- `#[Layout]` on authenticated pages — `layouts::app` is already the default.
- Public properties holding Eloquent **collections** — they get serialised into every
  request payload. Use `#[Computed]`.
