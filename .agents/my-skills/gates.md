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
| `users.gates` | One administrator's override, laid over their **merged** roles key by key |

An admin carries **any number of roles** (`role_user`). Their maps are folded into one
with the **highest access winning each key**, and the override goes on top of the
result. A role is a grant, so holding a second one can only widen what an account
reaches — were the merge to take the lowest, adding a narrow role to a broad one would
quietly revoke access nobody asked to revoke.

`NONE` on one role does not veto a grant on another; it is the floor, not a veto.
Denying one specific administrator is what the personal override is for, and that still
wins because it is applied after the merge.

```php
$user->roles;                                  // every role, live or switched off
$user->liveRoles();                            // the ones granting something
app(GateService::class)->inheritedAccessFor($user, 'content');  // the merge, before the override
```

`users.gates` being **null** means "inherit the roles" and is the normal state. An
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

One line in `mount()`, next to `kSetSiteTitle()`. Use **`setPageGate()`**, from
`WithGateProps` — `WithDataTable` and `WithStatusToggle` already pull that trait in,
so most screens have it and the rest add `use WithGateProps`:

```php
public function mount(): void
{
    kSetSiteTitle('users', 'roles');
    $this->setPageGate('users.roles');
}
```

It calls `kPageGate()` for you **and** remembers the key, which is what lets every
later check on the screen ask without naming it again. Call `kPageGate()` directly only
on a screen that has no write to guard at all.

A screen whose level depends on what it is doing passes it:

```php
$this->setPageGate('content.blogs', $this->post?->exists ? GateAccessEnum::MODIFY : GateAccessEnum::CREATE);
```

`kPageGate()` aborts with a **404**, not a 403 — the shape of the admin surface is not
something a refused account gets to map, which is the same reason the workspace
middleware returns one. It passes non-admin routes straight through, so the shared
image and video library screens stay open in the member workspace.

**Hiding a control is a courtesy. The method check is the boundary.** Both are
required, exactly as in [policies.md](policies.md):

```blade
<x-dashboard.gate.button gate="content.blogs" level="full" variant="danger" wire:click="confirmDelete({{ $post->id }})">
    Delete
</x-dashboard.gate.button>
```

`<x-dashboard.gate.button>` and its dropdown twin `<x-dashboard.gate.menu-item>` are
the hiding half, written once rather than as an `@if` around every button on fifteen
screens. They ask **`kGateAction()`**: `kGate()`, except that an account in an ungated
workspace passes straight through, so the image and video libraries keep their controls
in the member workspace, which has no gate keys at all.

That test is on the **account**, not on the route — the one difference from
`kPageGate()`. `kPageGate()` runs in `mount()`, on the request that opened the page,
where `admin.*` is a fair test. A control re-renders on every Livewire update too, and
those arrive on `livewire.update`: a route test would answer "not an admin route" and
hand every hidden button back the first time somebody typed in a search box.

The method check is **`checkGate()`**, also from `WithGateProps`. It asks about the key
`setPageGate()` already recorded, so the gate key is written once per screen rather than
once per method — and a screen whose key changes cannot be left half-renamed:

```php
public function delete(): bool
{
    // The button is hidden, which stops nobody who can open a console.
    $this->checkGate(GateAccessEnum::FULL, 'You do not have delete access to posts.');
    …
}
```

The message is optional; leave it off and the level supplies a sensible one:

```php
$this->checkGate(GateAccessEnum::CREATE);   // "You do not have access to create new items in this area."
```

**Never write `respondError(if: ! kGate('some.key', …))` in a page method.** It is the
same check with the key spelled again, and the spelling is what goes wrong.

The trait also puts the three levels on the component as plain strings — `$gateCreate`,
`$gateModify`, `$gateFull` — for the Blade half:

```blade
<x-dashboard.gate.button gate="content.blogs" :level="$gateCreate" icon="plus" variant="primary" wire:click="create">
    New post
</x-dashboard.gate.button>
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

Go through `RoleService::create()`, never `Role::create()`. A new role starts **closed**
— gates are granted afterwards from the access editor, which is the one place the
lockout guard sees the whole picture.

`RoleService::authorRole()` is the other role the kit resolves by slug. It is
ordinary — rename it, re-gate it, delete it — but `AUTHOR_SLUG` is what the blog reads
to decide whose byline carries a bio and which posts an account may work on. It ships
at `CREATE` on `content.blogs`, and `BlogService::authorRestricted()` holds anybody on
it to their own posts unless something else on their account grants `FULL`.

The exception is `RoleService::protectedRole()`: the one role an install cannot be left
without. It is created with `fullAccessMap()`, marked `is_protected`, and refused to
anybody trying to delete or switch it off. Without it there is a reachable state where
nobody can administer anything and no screen is left to fix that from. Resolving it
again never restores gates somebody deliberately removed.

Test fixtures go through the service for the same reason: `userOfType(UserTypeEnum::ADMIN)`
lands on the protected role, so a test that just wants "an admin" gets one who can
actually open the screen under test.

## Why

- Storing the map as JSON on the role keeps a permission model in **two columns and one
  pivot**. A `role_gates` table would be one row per role per screen, joined on every
  page load, to hold what one small blob holds. The pivot that did earn its place is
  `role_user`, because "which roles" is a set an administrator edits, not a map.
- Keying gates by navigation key rather than by route name means the sidebar and the
  guard read the same value. A page cannot end up visible-but-forbidden or
  reachable-but-hidden, because there is only one fact.
- A ladder rather than a set of flags means a screen asks one question. Four booleans
  per screen is four times the storage and the first place a "can create but not view"
  contradiction gets in.
- Per-administrator overrides live on the **user**, beside the roles they modify. An
  override is a change to what those roles grant, so an account with no live role
  resolves to nothing at all — the override included. That is what makes switching a
  role off actually revoke access rather than leave the overrides standing.
- Resolution is memoised per request on the singleton because the sidebar asks about a
  dozen gates per render. `flush()` after every write, or the screen that just changed
  a gate renders against the map it replaced. When the account that changed is the
  signed-in one, `syncAuthenticated()` rather than `flush()`: the guard holds its own
  `User` instance for the whole request, and a stale copy would re-cache the map the
  write just replaced.

## Example

Resolving an account that holds a narrowed role plus an override:

```php
// roles.gates
['content' => 'view', 'users' => 'full']

// users.gates
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
    return kGateAccess($this->pageGate);
}

public function delete(): bool
{
    $this->checkGate(GateAccessEnum::FULL, 'You do not have delete access to posts.');
    …
}
```

`kGateAccess()` is for a screen that **renders differently** at each level — a heading,
a column, a whole panel. A write is guarded with `checkGate()`, never with
`$this->access->covers(...)`: that is the same question asked the long way round.

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
$this->setPageGate('invoices');
```

Existing roles do not carry the new key, so it starts closed for everybody — grant it
on the roles screen.

## Avoid

- `kPageGate()` in a page's `mount()` where `$this->setPageGate()` is available.
- `respondError(if: ! kGate('literal.key', …))` inside a page method — that is
  `$this->checkGate(…)`, with the key already known.
- Naming the gate key more than once on a screen.

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
- Adding a column to `roles` and forgetting that `User::role()` carries an explicit
  `select()`. A column left out of that list reads back as null everywhere, which looks
  like data rather than like a bug.
- Assuming an admin has a role, or exactly one. The pivot may be empty — a stranded
  admin is a state the workspace is built to show — and it may hold several, so
  anything reading "the role" is already wrong. Go through `GateService`.
- Reading one of an account's roles to decide what it may do. Two roles that both speak
  about a screen are one answer, and only the merge knows it.
- Writing a control with a bare `@if (kGate(...))` on a screen shared with the member
  workspace. `kGate()` answers no for every member; `kGateAction()` is the one that
  passes non-admin routes through.
