---
name: house-style
description: "The house style for this codebase — read it before writing any PHP or Blade here. Covers where code goes (the 13 app/ folders and the pages:: view tree), Livewire 4 single-file components and the ⚡ filename convention, the With* traits, the k*() helper inventory, enum naming and WithEnumHelpers, #[Singleton] services, #[Unguarded] models with casts() and #[Scope], the nine-step form save and respondSuccess(), Flux UI usage and the tone palette, activity logging on admin writes, the two workspaces and their route files, and Pest conventions. Also says what this project deliberately does NOT use — no Actions, Repositories, Jobs, Events, Policies, or Form Requests — and what to reach for instead. Trigger whenever adding a screen, model, migration, service, trait, enum, helper, component, or test; whenever naming a file, class, method, route, or column; and whenever a Laravel default would conflict with how this project is written."
---

# House style

This project has a specific, consistent style. Code you add has to disappear into it.
Generic Laravel advice will produce code that is technically correct and visibly foreign.

## Read these first

The full library lives in **`.agents/my-skills/`**. Two files are mandatory:

| Read | When |
| --- | --- |
| `.agents/my-skills/agent.md` | **Before generating any code.** The ten laws, the hard bans, the generation procedure |
| `.agents/my-skills/checklist.md` | **Before reporting a task done.** The review gate |

Then load only the topic files the task actually touches. `.agents/my-skills/README.md`
is the index — it maps every task to its file.

## The short version

If you read nothing else before starting:

1. **Never invent architecture.** `app/` has 13 folders. Use them. There is no
   `app/Actions`, `app/Repositories`, `app/Jobs`, `app/Events`, `app/Policies`,
   `app/Http/Requests`, or `app/Livewire`.
2. **Every screen is a Livewire single-file component** at
   `resources/views/pages/**/⚡name.blade.php`, routed with
   `Route::livewire('/path', 'pages::group.name')`. Never a class component, never
   a `render()` method.
3. **Every status is an enum** using `WithEnumHelpers`, with one `is{CASE}()` per case.
   Status enums are prefixed (`StatusUser`); everything else is suffixed (`UserTypeEnum`).
4. **Every model** is `#[Unguarded]` with a `casts()` method and `#[Scope]` scopes.
   Never `$fillable`, never `protected $casts`, never `scopeFoo()`.
5. **Every form** follows the nine-step `save()` and ends with `respondSuccess()`.
   Rules live in `protected function rules(): array`, never in `#[Validate]`.
6. **Every admin write is logged** via `ActivityLogService` — call `affectedColumns()`
   *before* `save()`.
7. **Flux first**, Tailwind second, hand-rolled markup last. Always pair a light class
   with its `dark:` variant.
8. **Business logic lives in a `#[Singleton]` service**, resolved with `app()`, never `new`.
9. **Every change gets a Pest test**, run with `php artisan test --compact --filter=`.
10. **Run `vendor/bin/pint --dirty`** before you finish.

## Before you write

Follow this order every time:

1. **Find the closest sibling** already in the repo and copy its shape. A new admin
   listing? Open `resources/views/pages/admin/users/⚡members.blade.php`. A new single
   record view? `⚡user-view.blade.php`. A new form? `⚡site-config.blade.php`.
2. Check `app/Enums/` for an existing enum before adding a status vocabulary.
3. Check `app/Services/` before writing business logic.
4. Check `app/Traits/` before writing shared page behaviour.
5. Check `resources/views/components/` and Flux before creating a component.
6. Check `app/Helpers/` for an existing `k*()` before writing a formatter.

## A note on the examples

This library was extracted from a cohort-based training platform, and many of its
examples still name cohorts, trainings, admissions and transactions. **Those are
illustrations of the pattern, not of this codebase.** None of those models exist here.
Read them for the shape — the folder, the naming, the method order, the comment voice —
and substitute your own domain.

Where a file states a fact about *this* repo (a folder map, a trait inventory, a route
table), it has been brought in line with what actually ships.
