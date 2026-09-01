# events.md

## Rule

**This project uses no Laravel events.** There is no `app/Events/`, no
`app/Listeners/`, no `EventServiceProvider`, no `event()` dispatch, no model observers,
and no broadcasting.

"Events" in this codebase means two things, both Livewire/Alpine:

| Kind | Direction | Mechanism |
| --- | --- | --- |
| **Livewire browser event** | PHP → JS | `$this->dispatch('name', key: $value)` → `x-on:name.window` |
| **Alpine DOM event** | JS → JS | `x-on:click`, `@scroll.window`, `x-on:livewire-upload-progress` |

When code needs to react to a domain change, it is called **directly** — in the same
method, or through the service that owns the change.

## Why

- An event bus decouples the emitter from the handler, but this codebase has exactly
  one handler for each domain change, and the ordering matters (log **after** save,
  mail **after** commit). Explicit calls make that ordering visible at the call site.
- Model observers were avoided deliberately: the FAQ markdown cache invalidates through
  a cache key that embeds `updated_at`, which is self-maintaining, whereas an observer
  is a second place to keep in sync (the code says so in as many words).
- A reader tracing "what happens when a cohort is cancelled" follows method calls, not
  a listener registry.

## Livewire browser events

Dispatched with **named arguments**; the payload arrives as `$event.detail`.

```php
// pages/auth/⚡forgot-password.blade.php
$this->dispatch('attr', tag: $tag, title: $title, description: $description);

// pages/auth/⚡passwordless.blade.php
$this->dispatch('attr', tag: $this->tag, title: $this->title, description: $this->description);

// pages/trainer/⚡attendance.blade.php
$this->dispatch('attendance-synced', statuses: (object) $this->statuses);
```

Listened for on `window` in the same file's Blade:

```blade
<span x-data="{ tag: @js($tag) }" x-on:attr.window="tag = $event.detail.tag" x-html="tag"></span>
<span x-data="{ title: @js($title) }" x-on:attr.window="title = $event.detail.title" x-html="title"></span>
<span x-data="{ description: @js($description) }" x-on:attr.window="description = $event.detail.description" x-html="description"></span>
```

```blade
<div
    x-data="{ values: @js($statuses) }"
    x-on:attendance-synced.window="values = Object.assign({}, $event.detail.statuses)"
>
```

Conventions:

- Event names are **kebab-case** (`attendance-synced`). The short `attr` is the one
  legacy exception.
- Payload keys are named arguments, not a positional array.
- `@js(...)` seeds the initial Alpine state so the markup is correct before any event
  fires.
- Cast an associative array to `(object)` when JS will treat it as a map.
- Use this **only** to update presentation that Livewire would otherwise re-render —
  it is not a state channel.

## Alpine DOM events

```blade
@click="mobileSidebarOpen = false"
@scroll.window.passive="scrolled = window.scrollY > 8"
x-on:change="fileName = $event.target.files[0]?.name ?? null"
x-on:click="$refs.input.click()"

{{-- Livewire's own upload events --}}
x-on:livewire-upload-start="uploading = true"
x-on:livewire-upload-finish="uploading = false"
x-on:livewire-upload-cancel="uploading = false"
x-on:livewire-upload-error="uploading = false"
x-on:livewire-upload-progress="progress = $event.detail.progress"
```

## Domain reactions — direct calls

**Audit logging** is a call, not a listener:

```php
$serviceInstance = app(ActivityLogService::class);
$affectedColumns = $serviceInstance->affectedColumns($this->faq);

$this->faq->save();

$serviceInstance->logActivity($action, " FAQ: {$this->faq->label()}", $affectedColumns, model: $this->faq);
```

**Cache invalidation** is a key, not an observer:

```php
/**
 * Cached against the row's updated_at, so editing an answer changes the key and
 * the cache falls away on its own, with no observer to keep in sync.
 */
public function answerHtml(): string
{
    return Cache::rememberForever(
        "faq:{$this->id}:answer:{$this->updated_at?->getTimestamp()}",
        fn () => app(MarkdownService::class)->toHtml($this->answer)
    );
}
```

**Post-write side effects** are ordered explicitly:

```php
$user = DB::transaction(function () use (…) { … });

// Send welcome email after the transaction is committed
app(EmailVerificationOtpService::class)->sendWelcomeEmail($user, $sendOtp);

// Log Activity: Log the registration activity
$this->logActivity(ActivityActionEnum::REGISTER);

Auth::login($user, true);
session()->regenerate();
```

**Status cascades** call the owning service:

```php
$this->cohort->status = $status;
$this->cohort->save();

// Update the cohort status based on the current dates and other conditions
app(TrainingService::class)->updateCohortStatus($this->cohort);
```

## Cross-component communication in Livewire

Where two Livewire components must coordinate, the project keeps it server-side:

- A shared **trait** owns the state and exposes an overridable hook:

```php
// in the trait
protected function afterRoleChange(): void {}

// in the host page
protected function afterRoleChange(): void
{
    unset($this->students, $this->metrics);
}
```

- Or the pages simply re-read from the database on their next render.

## Template

```
Something happened. What reacts?

Presentation in the same page that Livewire will not re-render
└── $this->dispatch('thing-changed', key: $value)  +  x-on:thing-changed.window

Purely visual, no server state
└── Alpine x-data / x-on

A domain side effect (log, mail, notify, cascade)
└── call it directly, in the correct order:
    1. write            $model->save()
    2. cascade          app(TheService::class)->syncSomething($model)
    3. audit            app(ActivityLogService::class)->logActivity(...)
    4. notify           app(NotificationService::class)->notifyUser(...)
    5. mail (after commit)  Mail::to(...)->queue(...)
    6. invalidate       unset($this->computed)
    7. respond          return $this->respondSuccess('...')

A sibling Livewire component needs to refresh
└── a shared trait with an overridable after*() hook
```

## Avoid

- Creating `app/Events/`, `app/Listeners/`, `app/Observers/`, or an
  `EventServiceProvider`.
- `event(new SomethingHappened(...))`, `Event::dispatch()`, `Event::listen()`.
- Model lifecycle hooks (`static::created(...)`, `booted()`) or observer classes.
- Broadcasting, Echo, Reverb, or WebSockets.
- `$this->dispatch()` as a way to move **state** between components — move it to a
  trait or the database.
- `dispatchTo()` / `dispatchSelf()` where a direct method call works.
- Positional payloads instead of named arguments.
- camelCase or dot-separated event names.
- Alpine listeners for data that the server needs to know about.
- Suggesting an event/listener layer as an "improvement" while working on an unrelated
  task.
