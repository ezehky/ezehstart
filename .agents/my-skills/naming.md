# naming.md

## Rule

### Classes and files

| Kind | Pattern | Real examples |
| --- | --- | --- |
| Status enum | `Status{Subject}` — **prefix** | `StatusUser`, `StatusCohort`, `StatusAdmission`, `StatusTransaction`, `StatusDefault`, `StatusYes`, `StatusPolicy`, `StatusClassSession`, `StatusAttendance` |
| Non-status enum | `{Subject}Enum` — **suffix** | `UserTypeEnum`, `TransactionTypeEnum`, `PolicyTypeEnum`, `FaqTypeEnum`, `DayOfWeekEnum`, `SocialProviderEnum`, `VendorEnum`, `ActivityActionEnum` |
| Service | `{Subject}Service` | `TrainingService`, `ActivityLogService`, `SiteConfigurationService` |
| Trait | `With{Capability}` | `WithFormResponseMessage`, `WithEnumHelpers`, `WithCohortAdmin` |
| Validation rule | `{Subject}Rule` | `EmailRule`, `ImageRule`, `MoneyRule` |
| Cast | `{Subject}Cast` | `MoneyCast`, `TimeCast` |
| Mailable | `{Subject}Email` | `WelcomeEmail`, `ClassReminderEmail`, `RefundStatusEmail` |
| Notification | `{Subject}Notification` | `GeneralNotification` |
| Middleware | `{Role}Middleware` | `AdminMiddleware`, `TrainerMiddleware`, `UserMiddleware` |
| Console command | `{Verb}{Subject}Command` | `SendClassReminderCommand`, `SyncCohortStatusCommand` |
| Controller | `{Subject}Controller`, invokable | `LandingPageController`, `PaymentCallbackController` |
| Model | Singular `StudlyCase` | `Cohort`, `ClassSession`, `TransactionCharge` |
| Seeder | `{Model}Seeder` | `FaqSeeder`, `RoleSeeder`, `SiteConfigSeeder` |
| Factory | `{Model}Factory` | `UserFactory` |
| Test | `{Area}{Subject}Test` | `AdminFaqTest`, `TrainerPortalTest`, `CohortRefundTest` |

### Livewire page files

`resources/views/pages/{workspace}/{group}/⚡{kebab-case}.blade.php`

The **`⚡` prefix is mandatory** — it is how Livewire 4 recognises a single-file
component in this project (`livewire.make_command.emoji = true`). The Livewire name
drops the emoji and the extension:

```
resources/views/pages/admin/configs/⚡faqs.blade.php  →  'pages::admin.configs.faqs'
resources/views/pages/user/training/⚡cohort.blade.php →  'pages::user.training.cohort'
```

Page filenames are **plural for listings**, **singular for a single record**:
`⚡cohorts.blade.php` (list) vs `⚡cohort.blade.php` (one). Multi-word is kebab-case:
`⚡cohort-students.blade.php`, `⚡account-settings.blade.php`.

### Blade components

`resources/views/components/{group}/{kebab-case}.blade.php` → `<x-group.kebab-case />`.
Groups in use: `dashboard`, `form`, `site`, `training`, `finance`, `layouts`, `lv`.
`lv/` holds Livewire SFCs used as embedded components (also `⚡`-prefixed).

### Routes

- Route names are dot-scoped: `admin.config.faqs`, `user.training.cohorts` → actually
  `user.cohorts`, `trainer.attendance`, `admin.cohort.students`.
- The group prefix (`admin.`, `user.`, `trainer.`) comes from `bootstrap/app.php`;
  never repeat it inside the route file.
- Sub-resources of one record nest under the parent's singular name:
  `admin.cohort`, `admin.cohort.students`, `admin.cohort.schedule`.
- Public policy routes use the enum value as the name: `terms`, `privacy`, `cookies`.

### Methods

| Kind | Pattern | Examples |
| --- | --- | --- |
| Livewire action | `camelCase` verb | `save()`, `create()`, `edit()`, `delete()`, `toggleStatus()`, `openRoleManager()`, `statusAction()` |
| Livewire computed | `camelCase` noun (plural for collections) | `grouped()`, `students()`, `metrics()`, `statusOptions()`, `preview()` |
| Livewire private helper | `camelCase`, `private` | `resetForm()`, `resetTrainingForm()`, `refreshRoleState()` |
| Model boolean getter | `is*` / `has*` / `can*` / `allow*` | `isLocked()`, `hasSchedules()`, `canEnroll()`, `allowUpdate()` |
| Model value getter | plain noun/verb | `displayName()`, `locationLabel()`, `progress()`, `lockedReason()` |
| Enum predicate | `is{CASE}()` — one per case | `isActive()`, `isConcluded()`, `isCohortEnrollment()` |
| Enum presenter | plain noun | `label()`, `color()`, `defaultTitle()`, `shortLabel()`, `icon()`, `url()` |
| Service getter | `get*` or plain noun | `getCurrent()`, `getActivityLogsForUser()`, `admins()`, `pendingItems()` |
| Service action | verb | `logActivity()`, `requestWithdrawal()`, `recordConsent()`, `grant()`, `revoke()` |
| Service guard | `{action}BlockedReason()` returning `?string` | `grantBlockedReason()`, `revokeBlockedReason()` |
| Trait ensure-guard | `ensure*()` | `ensureCohortIsEditable()`, `ensureCohortOwnership()` |
| Helper function | `k{PascalCase}` | `kSlug()`, `kMoneyFormat()`, `kSetSiteTitle()`, `kSafeImage()` |

### Variables & properties

| Kind | Convention | Examples |
| --- | --- | --- |
| Livewire prop bound to a DB column | `snake_case`, **exactly the column name** | `$faq_type`, `$flow_order`, `$training_starts_at`, `$is_online` |
| Livewire prop not bound to a column | `camelCase` | `$previewing`, `$accountStatus`, `$cohortId`, `$roleUserId`, `$faqCases` |
| Livewire model prop | the model name, `camelCase` | `public ?Faq $faq = null;`, `public Cohort $cohort;` |
| Service instance local | `$serviceInstance` or `$service` | `$serviceInstance = app(ActivityLogService::class);` |
| Loop item in a table | `$item` | `@forelse ($this->students as $item)` |
| Loop item elsewhere | the domain noun | `$cohort`, `$faqType`, `$metric`, `$provider` |
| Closure query param | `$query`, nested ones named | `fn ($query) => …`, `fn ($admission) => …`, `fn ($roleQuery) => …` |
| Boolean local | `$is*` / `$has*` / `$can*` | `$isActive`, `$hasAccess`, `$canApply` |

### Blade

- `wire:key` on any looped row: `"{singular}-{{ $item->id }}"` → `wire:key="faq-{{ $item->id }}"`.
- Flux modal names are `camelCase` + `Modal`: `faqModal`, `trainingModal`,
  `userRolesModal`.
- Alpine `x-data` keys are `camelCase`: `mobileSidebarOpen`, `uploading`, `fileName`.
- Browser events dispatched from PHP are kebab-case: `attendance-synced`; the one
  short exception is `attr`.

### Database

- Tables: plural `snake_case` — `class_sessions`, `transaction_charges`.
  Exception in use: `transaction_evidence` (uncountable).
- Columns: `snake_case`.
- Foreign keys: `{singular}_id` — `cohort_id`, `training_id`, `trainer_role_id`.
- Booleans stored as int enums, named `is_*` or `status`: `is_online`, `is_default`.
- Timestamps: `*_at` — `training_starts_at`, `accepted_at`, `last_seen_at`,
  `effective_at`, `sent_at`.
- Ordering column: `flow_order`.
- Money columns are plain nouns storing minor units: `fee`, `amount`, `amount_paid`,
  `balance`, `compare_fee`.
- Type/category columns take the enum's subject, which is also the table's singular
  name: `faq_type`, `policy_type`, `transaction_type`, `transaction_group`,
  `transaction_wallet`, `day_of_week`.
- **No column is ever a bare SQL keyword.** Prefix it with the table's singular name —
  `user_type`, never `type`; `post_group`, never `group`. See below.

#### Reserved words are never column names

A column named after an SQL keyword has to be quoted for the rest of its life — in a
raw expression, in `whereRaw`, in `selectRaw`, in a join written by hand, in whatever
reporting tool reads the database in two years. One of those backticks always gets
forgotten, and what comes back is a syntax error pointing at the wrong token.

**The fix is a prefix, and the prefix is the table's singular name.**

| Table | No | Yes |
| --- | --- | --- |
| `users` | `type` | `user_type` |
| `posts` | `group` | `post_group` |
| `invoices` | `order` | `invoice_order` |
| `site_settings` | `key`, `value` | `setting_key`, `setting_value` |
| `videos` | `index` | `video_index` |

`type` is the one written by reflex, and it is the one to watch. It is reserved in
ANSI SQL, it is a keyword in several engines, and it collides with Eloquent's own
`$model->type` besides. Write `{singular}_type` — the enum name already says the same
thing: `UserTypeEnum` → `user_type`, `FaqTypeEnum` → `faq_type`,
`TransactionTypeEnum` → `transaction_type`.

Keywords that turn up as tempting column names. The list is illustrative, not
exhaustive — that is the point of the rule:

```
type    group   order   key     value   index   option  match   check   default
range   rank    action  level   mode    state   role    user    system  usage
start   end     first   last    next    left    right   read    write   desc
limit   offset  set     show    when    where   with    count   sum     position
```

When in doubt, prefix. A prefixed column is never wrong; a bare one might be.

**Not reserved, and deliberately bare.** `status`, `name`, `slug`, `title`,
`description`, `content`, `excerpt`, `reference`, `amount`, `balance`, `visibility`,
`flow_order`, and every `is_*`, `*_at`, `*_id`. These are the house names and they are
safe — do not "fix" them into `post_status` or `user_name`.

**Framework tables are exempt.** `cache.key`, `cache.value`, `cache_locks.owner`,
`notifications.type`, `jobs.queue`, `sessions.payload` are Laravel's own schema and
Laravel's own queries quote them correctly. Never rename a column in a table this
project did not design.

Every first-party column in this kit already obeys the rule — `users.user_type`,
`activity_logs.activity_log_action`, `faqs.faq_type`, `transactions.transaction_type`.
There is no grandfathered exception to copy.

## Why

- The `Status*` prefix makes every status enum sort together in `app/Enums/` and reads
  naturally at the call site: `StatusCohort::ONGOING`.
- `*Enum` suffix on non-status enums avoids collisions with model names
  (`Policy` the model vs `PolicyTypeEnum` the enum; `Category` vs `CategoryGroupEnum`).
  Note `Role` has **no** enum beside it — roles are rows, and what a *type* of
  account is lives in `UserTypeEnum`.
- `With*` traits signal composition at the `use` line: `use WithPagination,
  WithUserRoleManager;` reads as a list of capabilities.
- Livewire props named exactly after columns let `$this->fill($model->only([...]))`
  and `$model->fill($this->only([...]))` work with no mapping layer.
- `$item` in tables keeps every table body visually identical across 15 pages.

## Example

From `resources/views/pages/admin/configs/⚡faqs.blade.php`:

```php
public ?Faq $faq = null;              // model prop, named after the model
public FaqTypeEnum $faq_type = FaqTypeEnum::GENERAL;  // snake_case: it is a column
public int $flow_order = 1;           // snake_case: it is a column
public bool $previewing = false;      // camelCase: UI-only state
public array $faqCases;               // camelCase: derived option list
```

```php
public function toggleStatus(Faq $faq): bool   // camelCase verb, returns bool
public function delete(Faq $faq): bool
private function resetForm(): void             // private helper
#[Computed] public function grouped(): Collection  // computed noun
```

## Template

```php
// Enum — status vocabulary
app/Enums/StatusInvoice.php        enum StatusInvoice: int

// Enum — anything else
app/Enums/InvoiceTypeEnum.php      enum InvoiceTypeEnum: string

// Page
resources/views/pages/admin/finance/⚡invoices.blade.php   → 'pages::admin.finance.invoices'
routes/admin.php:
    Route::livewire('/invoices', 'pages::admin.finance.invoices')->name('invoices');
    Route::livewire('/invoice/{invoice:reference}', 'pages::admin.finance.invoice')->name('invoice');
```

## Avoid

- `InvoiceStatusEnum` — status enums take the `Status` **prefix**, no `Enum` suffix.
- `StatusEnum` on its own, or a generic `Status` enum — the shared one is
  `StatusDefault`.
- Page files without the `⚡` prefix — Livewire will not discover them as SFCs.
- `snake_case` Livewire methods, `PascalCase` props, Hungarian prefixes.
- `$row`, `$record`, `$model`, `$data` as the table loop variable — it is `$item`.
- `get`/`set` prefixes on model getters (`getDisplayName()`); it is `displayName()`.
- Helper functions without the `k` prefix — every global function in
  `app/Helpers/` is `k*`.
- Repeating the route group prefix inside the route file
  (`->name('admin.faqs')` inside `routes/admin.php` yields `admin.admin.faqs`).
- A column named `type`, `group`, `order`, `key`, `value`, `index`, `action` or any
  other SQL keyword — prefix it with the table's singular name.
