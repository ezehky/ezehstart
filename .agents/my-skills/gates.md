# gates.md

## Rule

**Which admin screens an account may open, and how far it may go inside one, is a
gate.** Gates are Layer 2 of the authorization stack in [policies.md](policies.md).
They are not Laravel Gates — there is no `Gate::define()`, no `@can`, no
`AuthServiceProvider`. The name is the only thing they share.

A gate is a **navigation key** mapped to a `GateAccessEnum`:

```php
'content'        => 'view'     // a parent branch
'content.tags'   => 'full'     // one screen under it
```

The keys come straight out of `kPageNavigationLinks('admin')`, so a screen becomes
gateable by getting a sidebar entry and by nothing else. There is no second list to
keep in step.

### The ladder

`GateAccessEnum` is ordered, not a set:

| Case | Permits |
| --- | --- |
| `NONE` | Nothing. The screen is not in the sidebar and returns a 404. |
| `VIEW` | Open it, read it, search it. Every write refused. |
| `MODIFY` | Edit records that already exist. |
| `CREATE` | Add new records as well. |
| `FULL` | Everything, deleting included. |

`covers()` reads the ladder, so **ask for the lowest level the caller needs** and an
account with more passes on its own:

```php
kGate('content.blogs')                            // VIEW is the default
kGate('content.blogs', GateAccessEnum::CREATE)    // the "New post" button
kGate('content.blogs', GateAccessEnum::FULL)      // the delete button
```

`NONE` satisfies nothing, including a request for `NONE`.

### Where a gate is stored

| Column | Means |
| --- | --- |
| `roles.gates` | What everybody holding this role starts from |
| `user_roles.gates` | One administrator's override, laid over their role key by key |

`user_roles.gates` being **null** means "inherit the role" and is the normal state. An
**empty array is not the same thing** — it means somebody deliberately overrode
everything. Keep the distinction; `updateAdminGates()` takes `null` on purpose.

That difference is also why `normalize()` has `keepDenials`. On a role an absent key
already means none, so `NONE` is dropped. On an override `NONE` means "deny this even
though the role allows it", which absence does not say, so it is kept.

### Parents and children

- A child with no gate of its own **inherits its parent's**. Grant `content` and every
  screen under it opens.
- A child with its own gate **beats the parent**, in both directions.
- A parent set explicitly to `NONE` **shuts the whole branch**, whatever the children
  say.
- A parent that is merely *absent* does not. A branch is otherwise decided by its
  children — grant one child and the branch opens for that child alone.

The sidebar renders exactly that rule: `kNavigationStrictAction()` gates a leaf on its
own key, and drops a branch only when no child survived.

### Guarding a screen

One line in `mount()`, next to `kSetSiteTitle()`:

```php
public function mount(): void
{
    kSetSiteTitle('users', 'roles');
    kPageGate('users.roles');
}
```

`kPageGate()` aborts with a **404**, not a 403 — the shape of the admin surface is not
something a refused account gets to map, which is the same reason the workspace
middleware returns one. It passes non-admin routes straight through, so the shared
image and video library screens stay open in the member workspace.

**Hiding a control is a courtesy. The method check is the boundary.** Both are
required, exactly as in [policies.md](policies.md):

```blade
@if (kGate('content.blogs', App\Enums\GateAccessEnum::FULL))
    <flux:button variant="danger" wire:click="confirmDelete({{ $post->id }})">Delete</flux:button>
@endif
```

```php
public function delete(): bool
{
    // The button is hidden, which stops nobody who can open a console.
    $this->respondError(
        'You do not have delete access to posts.',
        if: ! kGate('content.blogs', GateAccessEnum::FULL),
    );
    …
}
```

### Never gated

`dashboard` and `profile`. The dashboard is the workspace's front door and the profile
is where a refused administrator is told to go — gate either and there is nowhere to
land. `GateService::EXEMPT` holds the list and `accessFor()` returns `FULL` for them.

### The lockout guard

`GateService::ADMINISTRATION` is `'users'` — the gate that hands out gates. Every write
runs `roleGatesBlockedReason()` / `adminGatesBlockedReason()` first, which walk every
live assignment and refuse a change that would leave nobody with `FULL` over it.
Without it one careless save produces an installation nobody can administer and no
screen left to fix it from.

This is the `?string` blocked-reason contract from [policies.md](policies.md): null
means allowed, a string is the sentence shown to the person who tried.

### Creating a role

Go through `UserRoleService::role()`, never `Role::firstOrCreate()`. A newly created
**admin** role is seeded with `fullAccessMap()`; every other role starts closed. Build
one with the model directly and it comes up with an empty sidebar and a 404 on every
screen — which reads like a broken page rather than like a missing fixture. That
applies to test fixtures too: `userWithRole()` in `tests/Pest.php` goes through the
service for this reason.

## Why

- Storing the map as JSON on the role keeps a permission model in **two columns and no
  new tables**. A `role_gates` table would be one row per role per screen, joined on
  every page load, to hold what one small blob holds.
- Keying gates by navigation key rather than by route name means the sidebar and the
  guard read the same value. A page cannot end up visible-but-forbidden or
  reachable-but-hidden, because there is only one fact.
- A ladder rather than a set of flags means a screen asks one question. Four booleans
  per screen is four times the storage and the first place a "can create but not view"
  contradiction gets in.
- Per-administrator overrides live on the **assignment**, not the user, so revoking the
  role takes the override with it rather than leaving it behind for whenever the role
  is granted back.
- Resolution is memoised per request on the singleton because the sidebar asks about a
  dozen gates per render. `flush()` after every write, or the screen that just changed
  a gate renders against the map it replaced.

## Example

Resolving an account that holds a narrowed role plus an override:

```php
// roles.gates
['content' => 'view', 'users' => 'full']

// user_roles.gates
['content' => 'full', 'transactions' => 'none']

// resolved
kGateAccess('content')        // FULL      — override widened the role
kGateAccess('content.tags')   // FULL      — child inherits the parent
kGateAccess('users')          // FULL      — untouched by the override
kGateAccess('transactions')   // NONE      — override denied what the role allowed
kGateAccess('dashboard')      // FULL      — never gated
```

Tested in `tests/Feature/GateTest.php`.

## Template

A screen that renders differently at each level:

```php
#[Computed]
public function access(): GateAccessEnum
{
    return kGateAccess('content.blogs');
}

public function delete(): bool
{
    $this->respondError(
        'You do not have delete access to posts.',
        if: ! $this->access->covers(GateAccessEnum::FULL),
    );
    …
}
```

```blade
@if ($this->access->covers(App\Enums\GateAccessEnum::CREATE))
    <flux:button variant="primary" icon="plus" :href="route('admin.blog.create')" wire:navigate>New post</flux:button>
@endif
```

Adding a gateable screen is two lines and no migration:

```php
// 1. app/Helpers/navigations.php — the sidebar entry makes it gateable
'invoices' => ['label' => 'Invoices', 'link' => route('admin.invoices')],
```

```php
// 2. the page's mount()
kPageGate('invoices');
```

Existing roles do not carry the new key, so it starts closed for everybody — grant it
on the roles screen.

## Avoid

- `Gate::define()`, `@can`, `$this->authorize()`, `can:` middleware, `app/Policies/`.
- A second list of gateable screens. The navigation tree is the list.
- `data_get($map, 'users.roles')` on a gate map. The map is **flat with dotted keys**,
  so `data_get` reads that as a walk into a nested array and silently finds nothing.
  Index it directly, or go through `GateService`.
- Treating `$role->gates` as an array. The column casts to `AsArrayObject`, so spread,
  `count()` and `empty()` all misread it — use `gatesArray()`.
- Reading `roles.gates` to decide what an administrator may do. That is the role's
  answer, not the account's — go through `GateService`.
- Treating a null override as an empty one, or an empty one as null.
- `abort(403)` — this project returns 404.
- A hidden button as the only guard.
- Adding a column to `roles` or `user_roles` and forgetting that `User::userRoles()`
  and `UserRole::role()` both carry an explicit `select()`. A column left out of those
  lists reads back as null everywhere, which looks like data rather than like a bug.
