# forms.md

## Rule

Every form in the project is a Livewire page (or modal inside one) that follows the
**same nine-step save**:

```php
public function save(): bool
{
    1. $this->ensureXIsEditable();               // optional lock guard, FIRST
    2. $this->validate();
    3. // decide create vs update, pick the ActivityActionEnum
    4. // fill the model
    5. $this->respondPrimary(if: $model->isClean());   // "no changes" early-return
    6. // derived columns (slug, compare price) — guarded by isDirty()
    7. $affectedColumns = $service->affectedColumns($model);   // BEFORE save
       $model->save();
       $service->logActivity($action, " thing: {$model->name}", $affectedColumns, model: $model);
    8. Flux::modal('xModal')->close();  $this->resetForm();  unset($this->list);
    9. return $this->respondSuccess('…');
}
```

Write methods always **return `bool`** and always **end with `return $this->respondSuccess(...)`**.

### `WithFormResponseMessage` — the feedback contract

`use App\Traits\WithFormResponseMessage;` on any component with a form.

```php
respondPrimary(string $message = 'You have made no changes to save!!', bool $if = false, ?callable $callback = null, bool $flash = false, string $heading = ''): bool
respondError(mixed $message, bool $if = false, ?callable $callback = null, string $field = '', bool $flash = false, string $heading = ''): bool
respondSuccess(string $message = 'Saved!!', bool $flash = false, string $heading = ''): bool
```

| Call | Effect |
| --- | --- |
| `$this->respondPrimary(if: $model->isClean())` | warning toast "You have made no changes to save!!" and **stops execution** |
| `$this->respondError('Reason', if: $condition)` | danger toast and **stops execution** |
| `$this->respondError('Reason', if: true, field: 'email')` | inline field error, no toast, stops |
| `$this->respondError('Reason', if: true, flash: true)` | session flash `error`, stops |
| `return $this->respondSuccess('Saved.')` | success toast, returns `true` |
| `return $this->respondSuccess('Saved.', flash: true)` | session flash `success` — use when redirecting |

`respondPrimary` / `respondError` halt by throwing
`ValidationException::withMessages(['errorExceptionCatchStopper' => 'Stop Code'])`.
That sentinel field is never rendered — it is the project's early-return mechanism
inside a Livewire action. **Do not add a `try`/`catch` around it.**

Always use named arguments: `if:`, `field:`, `flash:`, `heading:`.

### Validation

`protected function rules(): array` on the component. Array-of-rules syntax.
See [validation.md](validation.md).

### Derived columns — step 6

A column the form does not ask for and the user does not type. It is written **after**
the clean check and **before** `save()`, and it is written only when whatever it is
derived from actually changed:

```php
// If the name changed, the slug has to follow it.
if ($this->category->isDirty('name')) {
    $this->category->slug = kSlug($this->name);
}
```

The `isDirty()` guard is not a micro-optimisation. Recomputing a slug on every save
churns the URL of a record whose name nobody touched, and every link already pointing
at it breaks.

**A slug is never an input.** No `wire:model`, no rule in `rules()`, no field on the
screen — unless the screen is deliberately built to let somebody choose their own slug,
which is a decision to make on purpose rather than a default to fall into. Two fields
that have to agree with each other is a way to get them out of step.

### Model fill

Two accepted forms.

**`fill()` with an array** (newer, preferred for compact forms):

```php
$this->faq->fill([
    'faq_type' => $this->faq_type,
    'question' => $this->question,
    'answer' => $this->answer,
    'flow_order' => $this->flow_order,
    'status' => StatusDefault::tryFrom((int) $this->status),
]);
```

**Property-by-property** (used on wider forms where ordering matters):

```php
$this->cohort->name = $this->name;
$this->cohort->fee = $this->fee;
$this->cohort->compare_fee = kStoreComparePrice($this->fee, $this->compare_fee);
$this->cohort->is_online = StatusYes::from($this->is_online);
```

### Loading a model into the form

```php
$this->thing = $thing;
$this->fill($thing->only(['name', 'description', 'training_type']));
$this->status = $thing->status->boolValue();
```

Dates for `<input type="datetime-local">` / `time` come through the magic accessors:

```php
$this->registration_starts_at = $this->cohort->registrationStartsAtDatetimeForUpdate();
$this->start_time = $this->schedule->startTimeForUpdate();
```

### Boolean ↔ enum conversion

Switches bind to a `bool` prop. Convert at the boundary:

```php
// load
$this->status = $model->status->boolValue();

// save
'status' => StatusDefault::tryFrom((int) $this->status),   // FAQ style
$model->status = StatusDefault::from($this->status);       // Training style
$model->is_online = StatusYes::from($this->is_online);
```

`tryFrom((int) $bool)` is the safer form — prefer it in new code.

### Reset

```php
private function resetForm(): void
{
    $this->resetValidation();
    $this->reset('thing', 'name', 'description', 'status');
}
```

Called at the **start** of `create()` and `edit()` and at the **end** of `save()`.
Name the properties explicitly — never bare `$this->reset()`.

### Form markup

```blade
<form wire:submit="save" class="space-y-6">
    <div>
        <flux:heading size="lg">{{ $thing === null ? 'Add thing' : 'Edit thing' }}</flux:heading>
        <flux:text class="mt-1">What the person filling this in needs to know.</flux:text>
    </div>

    <flux:input label="Name" wire:model="name" placeholder="e.g. Advanced Web Design" autofocus badge="required" />
    <flux:error name="name" />

    <div class="grid gap-4 sm:grid-cols-2">
        <flux:select label="Type" wire:model="training_type">
            @foreach ($trainingTypes as $value => $label)
                <flux:select.option value="{{ $value }}">{{ $label }}</flux:select.option>
            @endforeach
        </flux:select>

        <div class="flex items-end">
            <flux:switch wire:model="status" label="Active training" description="Available for new cohorts." />
        </div>
    </div>

    <flux:separator variant="subtle" />

    <div class="flex justify-end gap-3">
        <flux:modal.close>
            <flux:button variant="ghost" type="button">Cancel</flux:button>
        </flux:modal.close>
        <flux:button type="submit" variant="primary">Save thing</flux:button>
    </div>
</form>
```

Conventions in that markup:

- `wire:submit="save"` — no `.prevent`, Livewire 4 handles it.
- `space-y-6` between form sections, `grid gap-4 sm:grid-cols-2` for paired fields.
- `badge="required"` marks required inputs (not an asterisk).
- `autofocus` on the first field.
- Cancel is `variant="ghost"` + `type="button"` inside `<flux:modal.close>`;
  submit is `variant="primary"` + `type="submit"`.
- `<flux:error name="field" />` under fields whose Flux component does not render its
  own error (custom components, grids).
- `<flux:separator variant="subtle" />` between logical groups.

### Placeholders

**Every input carries a `placeholder`.** Text, email, number, URL, search, date — if a
person types into it, it says what a good answer looks like before they start:

```blade
<flux:input label="Name" wire:model="name" placeholder="e.g. Advanced Web Design" />
<flux:input type="email" label="Contact email" wire:model="email" placeholder="support@example.com" />
<x-form.number-field label="Passwords remembered" wire:model="depth" placeholder="e.g. 5" />
```

Rules for writing one:

- **Show an example, do not repeat the label.** `placeholder="Name"` next to
  `label="Name"` is noise. `placeholder="e.g. Advanced Web Design"` is an answer.
- Prefix a sample value with `e.g.` — a bare example reads as a value already filled in.
- On a search box the placeholder **lists the fields being searched**, so the person
  knows what will match. See [search.md](search.md).
- A placeholder is not a label, a help text or an error. It disappears the moment
  somebody types, so nothing that has to stay readable belongs in it — that is what
  `label`, `description` and `<flux:error>` are for.

Controls nobody types into — `<flux:switch>`, `<flux:checkbox>`, `<flux:radio>` — have
no placeholder. A `<flux:select>` takes one only when it has no meaningful default, in
which case it is the "choose one" row.

### Field components

| Need | Use |
| --- | --- |
| Text / email / url | `<flux:input label="…" wire:model="…" />` |
| Number | `<x-form.number-field label="…" wire:model="…" />` |
| Phone | `<x-form.phone-field wire:model="phone_number" />` |
| Password | `<x-form.password … />` |
| Long text / markdown | `<x-form.markdown-field label="…" wire:model="…" rows="8" markdown />` |
| Image upload | `<x-form.image-field label="…" wire:model="logoUpload" :temporary="…" :default="…" />` |
| File upload | `<x-form.file-field … />` |
| Select | `<flux:select>` + `<flux:select.option>` (or bare `<option>` in filter bars) |
| Boolean | `<flux:switch wire:model="status" label="…" description="…" />` |
| Date/time | `<flux:input type="datetime-local" wire:model="starts_at" />` |

### Live vs deferred binding

- Form fields: plain `wire:model` (deferred). The form submits on `wire:submit`.
- Filters and search: `wire:model.live` / `wire:model.live.debounce.350ms`.
- Toggling UI state: `wire:click="$toggle('previewing')"`.

## Why

- The nine-step save means every form in the app produces the same audit trail, the
  same "no changes" behaviour, and the same toast — a reviewer can diff two pages and
  see only the domain difference.
- `respondPrimary(if: $model->isClean())` sits **after** fill and **before** save, so it
  catches a user who opened the modal and clicked Save without editing — the log stays
  free of no-op entries.
- Capturing `affectedColumns()` **before** `save()` is mandatory: after saving, the
  model is no longer dirty and the diff is gone.
- Halting via `ValidationException` rather than `return` keeps the calling method's
  happy path flat and works identically from a trait.

## Example

`⚡trainings.blade.php` `save()` — the reference implementation, comments and all:

```php
public function save(): bool
{
    $this->validate();

    $action = ActivityActionEnum::TRAINING_UPDATE;

    if (! $this->training) {
        $this->training = Training::make();
        $action = ActivityActionEnum::TRAINING_CREATE;
    }

    $this->training->name = $this->name;
    $this->training->description = $this->description;
    $this->training->training_type = $this->training_type;
    $this->training->status = StatusDefault::from($this->status);

    // Check if is clean (no changes) and return early
    $this->respondPrimary(if: $this->training->isClean());

    // Slug
    $this->training->slug = kSlug($this->training->name);

    // ||||||||
    // Log Service
    $serviceInstance = app(ActivityLogService::class);
    $affectedColumns = $serviceInstance->affectedColumns($this->training);
    // ||||||||

    // Save the training
    $this->training->save();

    // Log the activity
    $serviceInstance->logActivity(
        $action,
        " training: {$this->training->name}",
        $affectedColumns,
        model: $this->training,
    );

    // Reset the form and close the modal
    Flux::modal('trainingModal')->close();
    $this->resetTrainingForm();

    // Return a success response
    return $this->respondSuccess('Training has been successfully saved.');
}
```

## Template

Delete-with-confirm:

```php
public ?int $deletingId = null;

/**
 * The row the dialog is about. Nothing is deleted until it is confirmed.
 */
public function confirmDelete(int $id): void
{
    $this->deletingId = $id;

    Flux::modal('deleteModal')->show();
}

public function delete(): bool
{
    $thing = Thing::query()->find($this->deletingId);

    abort_unless((bool) $thing, 404);

    $description = " thing: {$thing->name}";

    $thing->delete();

    app(ActivityLogService::class)->logActivity(ActivityActionEnum::THING_DELETE, $description);

    $this->reset('deletingId');

    unset($this->things);

    return $this->respondSuccess('The thing has been deleted.');
}
```

```blade
<flux:menu.item icon="trash" variant="danger" wire:click="confirmDelete({{ $item->id }})">
    Delete
</flux:menu.item>

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

Toggle-status:

```php
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

    return $this->respondSuccess(
        $faq->status->isActive()
            ? 'The question is now shown on the site.'
            : 'The question is now hidden from the site.'
    );
}
```

## Avoid

- `session()->flash('success', …)` for in-page feedback — use `respondSuccess()`.
  Flash is only correct when the same action redirects.
- Saving before capturing `affectedColumns()`.
- Skipping `respondPrimary(if: $model->isClean())` — no-op saves pollute the audit log.
- `$this->reset()` with no arguments.
- Forgetting `resetValidation()` in `resetForm()` — stale errors reappear in the modal.
- `wire:submit.prevent`.
- Manual `<button type="submit">` — use `<flux:button type="submit" variant="primary">`.
- An asterisk for required fields — use `badge="required"`.
- Returning `void` from a write method.
- Wrapping `respondError()` in `try`/`catch`.
- `wire:model.live` on a form field that is only read on submit.
- An input with no `placeholder`, or a placeholder that just restates the label.
- A `slug` input, or a `slug` key in `rules()`, on a screen that did not set out to
  let somebody choose their own.
- Recomputing a derived column unconditionally where `isDirty()` should guard it.
