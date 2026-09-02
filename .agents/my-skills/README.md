# Skills Library

**A knowledge base for AI agents working on this codebase.**

This is not documentation. It is a set of instructions that teach any AI agent
(Claude Code, Copilot, Cursor, ChatGPT, Gemini, …) exactly how this project is built,
so that generated code lands in the codebase indistinguishable from what is already
there — with little or no modification.

---

## Start here

**→ [agent.md](agent.md)** — the master instruction file. Read it before generating any
code.

**→ [checklist.md](checklist.md)** — the review gate. Walk it before reporting any task
complete.

**→ [templates.md](templates.md)** — every copy-paste template in one place.

---

## The stack, in one table

| | |
| --- | --- |
| Laravel | 13 |
| PHP | 8.3+ |
| UI | Livewire 4 — **single-file components only** (`⚡name.blade.php`) |
| Components | Flux UI free v2 |
| CSS | Tailwind v4 (CSS-first, lime accent, `zinc`→`slate` remap) |
| JS | Alpine (bundled with Livewire) |
| Tests | Pest 5 |
| Format | Laravel Pint, default preset |
| Types | PHPStan level 1 (see `phpstan.neon`) |

This is a **starter kit**, not an application: authentication, roles, two workspaces
(**admin** and **member**), and account management. No domain models ship with it.

> **About the examples.** This library was written against a cohort-based training
> platform, and many examples still name cohorts, trainings, admissions and
> transactions. Read them for the *shape* — folder, naming, method order, comment
> voice — and substitute your own domain. None of those models exist here. Where a
> file states a fact about this repo (a folder map, an inventory, a route table), it
> describes what actually ships.

---

## All skills

### Foundations

| Skill | Read it when |
| --- | --- |
| [agent.md](agent.md) | **Always, first.** The ten laws, the hard bans, the generation procedure |
| [checklist.md](checklist.md) | **Always, last.** Before reporting done |
| [architecture.md](architecture.md) | Adding anything — the folder map and layering |
| [naming.md](naming.md) | Naming any file, class, method, property, column, or route |
| [conventions.md](conventions.md) | Writing any line of PHP or Blade |
| [laravel.md](laravel.md) | Reaching for a framework feature |

### The UI layer

| Skill | Read it when |
| --- | --- |
| [livewire.md](livewire.md) | Writing any component |
| [pages.md](pages.md) | Building a screen — the six page shapes |
| [routing.md](routing.md) | Adding a route or a nav entry |
| [layouts.md](layouts.md) | Anything about the page shell, title, or SEO |
| [components.md](components.md) | Creating or using a Blade component |
| [ui.md](ui.md) | Writing markup — Flux, Tailwind, tones, dark mode, Alpine |
| [tables.md](tables.md) | Rendering a listing |
| [filters.md](filters.md) | Adding a filter |
| [search.md](search.md) | Adding search |
| [pagination.md](pagination.md) | Paginating |
| [dashboard.md](dashboard.md) | Metric cards, charts, feeds |
| [forms.md](forms.md) | Any form — the nine-step save |
| [file_uploads.md](file_uploads.md) | Any upload |

### The domain layer

| Skill | Read it when |
| --- | --- |
| [services.md](services.md) | Writing business logic |
| [models.md](models.md) | Creating or editing a model |
| [enums.md](enums.md) | Any status, type, or category |
| [traits.md](traits.md) | Sharing behaviour — the `With*` traits |
| [helpers.md](helpers.md) | Formatting anything — the `k*()` inventory |
| [validation.md](validation.md) | Validating input |
| [activity-logging.md](activity-logging.md) | **Any admin write** — mandatory |

### Data

| Skill | Read it when |
| --- | --- |
| [database.md](database.md) | Keys, indexes, money, morphs, transactions |
| [migrations.md](migrations.md) | Writing a migration |
| [seeders.md](seeders.md) | Shipping default data |
| [factories.md](factories.md) | Test fixtures |

### Cross-cutting

| Skill | Read it when |
| --- | --- |
| [auth.md](auth.md) | Anything touching sign-in, roles, or consent |
| [middleware.md](middleware.md) | Workspace access |
| [policies.md](policies.md) | Authorization — **without** Laravel Policies |
| [notifications.md](notifications.md) | Toasts, flashes, bell notifications, admin queues |
| [mail.md](mail.md) | Sending email |
| [commands.md](commands.md) | Artisan commands and the schedule |
| [testing.md](testing.md) | Every change needs a test |

### Deliberately not used — read before you assume

| Skill | Says |
| --- | --- |
| [actions.md](actions.md) | No `app/Actions/`. Page methods, traits, services instead |
| [repositories.md](repositories.md) | No repositories. Computed props, scopes, service getters |
| [jobs.md](jobs.md) | No `app/Jobs/`. Queued mail + scheduled commands |
| [events.md](events.md) | No Laravel events. Direct calls + Livewire/Alpine browser events |

---

## Every skill has the same five sections

| Section | Contains |
| --- | --- |
| **Rule** | What to do, stated as an instruction |
| **Why** | The reasoning behind the choice, so it can be applied to new cases |
| **Example** | Real code copied from this project |
| **Template** | The preferred shape for new code |
| **Avoid** | Patterns that must never be generated, because they do not match |

---

## The short version

If you read nothing else:

1. **Never invent architecture.** `app/` has 13 folders. Use them.
2. **Every screen is a Livewire SFC** at `resources/views/pages/**/⚡name.blade.php`,
   routed with `Route::livewire('/path', 'pages::group.name')`.
3. **Every status is an enum** using `WithEnumHelpers`, with one `is{CASE}()` per case.
4. **Every model** is `#[Unguarded]` with a `casts()` method and `#[Scope]` scopes.
5. **Every form** follows the nine-step `save()` and ends with `respondSuccess()`.
6. **Every admin write is logged** via `ActivityLogService` — `affectedColumns()`
   before `save()`.
7. **Flux first**, Tailwind second, hand-rolled markup last. Six tones. Always `dark:`.
8. **Business logic lives in a `#[Singleton]` Service**, resolved with `app()`.
9. **Every change gets a Pest test**, run with `--compact --filter=`.
10. **Run `vendor/bin/pint --dirty`** before you finish.

---

## Related files at the project root

| File | Authority |
| --- | --- |
| `AGENTS.md` | Laravel Boost guidelines — authoritative on **framework** usage |
| `CLAUDE.md` | The orientation file every session reads first |
| `.agents/my-skills/` | **This library — authoritative on project style.** Where the two overlap, follow this |
| `.agents/skills/house-style/SKILL.md` | The skill that routes agents into this library |
| `.agents/skills/` | Boost's bundled per-package skills (Livewire, Flux, Pest, Tailwind, Blaze) |

To inspect the schema, run `php artisan db:table <name>` or use Boost's
`database-schema` tool — there is no generated snapshot file.
