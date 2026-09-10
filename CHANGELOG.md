# Changelog

All notable changes to this starter kit are recorded here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the kit uses
[Semantic Versioning](https://semver.org/spec/v2.0.0.html).

A minor release may add tables, enum cases and route names. It will not rename the
ones already there — that is what a major is for.

## [Unreleased]

### Added

#### Video library

A second media library, deliberately shaped like the first. Same folder tree, same
visibility rules, same usage-backed delete guard — a screen that already knows
`x-form.image-slot` needs nothing new to learn `x-form.video-slot`.

- **`videos`, `video_folders`, `video_usages`.** A video row is a *reference*, not a
  file: nothing is uploaded, nothing lands on a disk, and removing a video at the
  provider removes it here. That is why the table is so much narrower than `images` —
  no path, no mime type, no size, no optimiser.
- **`VideoProviderEnum` is an allowlist, not a convenience.** YouTube and Vimeo. The
  row stores a provider and the provider's own id, never a URL, and the player URL is
  rebuilt from those two every time it is rendered. There is no author-supplied URL
  left in the row to be trusted later.
- **`BlogService::sanitize()` now rebuilds every iframe rather than cleaning it.**
  `strip_tags` keeps the attributes on a tag it allows, and an iframe is the one
  element where that is not survivable — `sandbox`, `srcdoc`, `allow` and
  `referrerpolicy` are all things an author would otherwise be setting for us. The
  `src` is run back through `VideoProviderEnum`, and the tag is written again from
  what came out. An iframe pointing at any other host is dropped rather than cleaned,
  because there is no version of it we can vouch for.
- **Body embeds claim their videos from the saved HTML**, not from a slot
  (`VideoLibraryService::syncFromHtml()`). The editor drops iframes in freely, so the
  finished content is the only thing that actually knows what the post embedded.
- **`config.uploads.user-video-limit`**, default 25. Not a storage limit — a video
  costs no disk — but a library nobody can find anything in is not a library.
- The tiptap editor gained a video button, and `/video-library` is in both workspaces.

### Changed

- **`ImageVisibilityEnum` is now `MediaVisibilityEnum`.** Both libraries answer "who
  may see this" identically, and two copies would drift the first time either gained a
  case. Stored values are untouched — `private`, `role`, `public` — so this is a rename
  in code only, with no migration. `description()` takes the noun to use, because
  "Only you and administrators can see this image" is wrong on a video screen.

### Fixed

- **The rich-text editor applied nothing.** `x-data` passes its object through Vue's
  `reactive()`, which deep-proxies a class instance, so `this.editor = new Editor(...)`
  put the whole ProseMirror instance behind a proxy. Commands still ran and the toolbar
  still lit up, but ProseMirror tracks nodes and decorations by object identity and the
  view stopped repainting — bold "worked" and the text never went bold. The editor now
  lives in the factory's closure, outside the reactive tree.
- **Rich-text content was unstyled.** The editor and the public post body both asked
  for `prose prose-slate dark:prose-invert`, but `@tailwindcss/typography` is not a
  dependency of this kit, so there were no `.prose` rules at all and preflight flattened
  every heading, list and quote. Replaced with a `.rich-prose` block alongside the
  existing `.markdown-prose`, which also gives the Placeholder extension the
  `is-editor-empty` rule it needs to show at all.
- **The link button no longer opens a `window.prompt`.** An inline bar in the toolbar
  instead — seeded with the existing href so a link is edited rather than retyped,
  Enter to apply, Escape to dismiss.
- **Editor content could be lost by clicking Save straight from the editor.** The
  documented `rich-text:flush` event had no dispatcher anywhere. The editor now pushes
  on every change; the entanglement is deferred and the wrapper is `wire:ignore`, so
  that costs no request per keystroke.

## [1.2.0] - 2026-09-09

### Added

#### Sign-in hardening

- **Login throttle.** `WithAuthWorker` gained `ensureIsNotRateLimited()`,
  `recordFailedAttempt()` and `clearRateLimit()`, checked *before* the credential test
  rather than after — a throttle that only runs on success costs an attacker nothing.
  Keyed on the email **and** the address together: on the email alone, anybody could
  lock out an account whose address they know; on the address alone, one office would
  lock each other out. Attempts and lockout minutes are site configuration, clamped in
  code so a silly value cannot disable the feature.
- **`PasswordSecurityService`** (`#[Singleton]`) owns the strength rule and the reuse
  check. `updatePassword()` is now the only place a password should be written — it
  stores the new one and files the old hash away in one call, which is what stops
  history ending up with a gap in it. Eight characters is the floor whether or not
  "strong passwords" is on; the switch relaxes composition, not length.
- **`password_histories` table.** Append-only, `created_at` only, pruned to the
  configured depth — keeping hashes forever is a liability with no matching benefit.
  Every remembered hash is checked individually, because bcrypt salts each one and no
  query can do it for us.
- **`user_two_factors` table and TOTP two-factor**, via `pragmarx/google2fa-laravel`
  and `bacon/bacon-qr-code`. Its own table rather than columns on `users`, so the
  secret and the recovery codes are not sitting in memory on every request. The QR is
  rendered locally: the provisioning URI contains the shared secret, and handing that
  to an image service to draw would defeat the point of the feature. Enrolment is not
  finished until a generated code verifies, so somebody who scans the QR and closes
  the tab is not locked out by a factor they never set up. Recovery codes are
  single-use and removed when spent; the "remember this device" token is stored only
  as a hash.
- **Social sign-in** (`laravel/socialite`), through `SocialAccountService` and
  `SocialAuthController`. Resolution matches the provider id first and the email only
  as a fallback, because somebody can change the email on their Google account and
  matching on it first would strand them with a second local account. Unlinking the
  only remaining way into an account is refused. Tokens are encrypted at rest.
- **`user_connected_accounts` table**, unique on `(provider, provider_id)` so one
  provider identity cannot be claimed by two local accounts, and on
  `(user_id, provider)` so one account links a provider once.
- **`kSiteFlag()`** helper. `kSiteConfig()` applies its `$default` to any *falsy*
  value, so a switch an administrator deliberately turned **off** read back as its
  default — which for a security feature meant "off" silently read as "on". Every
  on/off switch goes through `kSiteFlag()` instead.

#### Image library

- **`images`, `image_folders` and `image_usages` tables**, with `ImageLibraryService`
  and one screen shared by both workspaces.
- `title` and `file_path` are separate columns, and `file_path` is unique and written
  once. That is what makes "rename the title, not the URL" structurally true rather
  than a rule somebody has to remember.
- **`image_usages` carries a restricting foreign key**, so "delete only images nobody
  is using" is enforced by the database rather than by a service check that can be
  bypassed. The same reasoning `user_consents` already uses for policies.
- **`ImageVisibilityEnum`** — `PRIVATE`, `ROLE`, `PUBLIC` — carried by the image and
  never inherited from its folder. Folders are labels people reorganise freely, and
  permissions that move when a file is dragged somewhere are permissions nobody can
  reason about. Leaving `ROLE` nulls the role column, so changing visibility twice
  cannot quietly restore an old audience.
- Multiple upload, a folder tree, search by title, a per-member quota administrators
  are exempt from, and `spatie/laravel-image-optimizer` where its binaries are
  installed — where they are not, it does nothing rather than failing an upload.

#### Blog

- **`posts`, `categories`, `categorizables`, `tags` and `taggables` tables**, with
  `BlogService`.
- **Categories and tags are polymorphic.** One `categories` table serves every kind of
  category, separated by `CategoryGroupEnum`, and slugs are unique *per group* — so
  "skincare" can legitimately be both a blog category and a product one. A second
  domain reuses the table, the admin screen and the tree code rather than repeating
  all three.
- **`x-form.rich-text`** — a tiptap editor bound to a Livewire property, writing back
  on blur rather than on every keystroke, because a response landing mid-word moves
  the cursor. It inserts images through `<livewire:lv.image-picker>` and never talks
  to an upload endpoint itself.
- **`BlogService::sanitize()`** runs over editor HTML on the way in. HTML from a form
  is untrusted however trusted the author is — an author account is exactly what an
  attacker would go for to get a script onto every reader's page.
- `published_at` is stamped the first time a post goes live and left alone afterwards,
  so fixing a typo three months later does not shove it back to the top of the feed. A
  post published with a future date is not live yet.
- Public `/blog` and `/blog/{slug}`, which refuse anything not live — a draft is not
  reachable by guessing a URL.

#### Transaction ledger

- **`transactions`, `transaction_gateways`, `transaction_metas`,
  `transaction_evidence`, `transaction_balances` and `transaction_charges` tables**,
  with `TransactionService`.
- **Balances are derived from confirmed rows, never stored on the user**, so a balance
  cannot silently disagree with the ledger that is supposed to explain it. Writes
  happen under `lockForUpdate()`: two requests spending the same balance would
  otherwise both read it before either wrote, and both would be allowed through.
- `transaction_balances` is unique on `transaction_id`. A second snapshot would mean
  the movement was applied twice, and the index makes that impossible rather than
  merely unlikely.
- A settled transaction is never re-settled, so a webhook arriving twice is a no-op
  rather than a second credit.
- Money is integer minor units throughout, read through `MoneyCast`.

#### Reference data

- **`countries` table**, seeded from `database/data/countries.json` — a file in the
  repository, not a download at seed time. `composer setup` has to work on a laptop
  with no network and in a CI job with no egress, and a seeder that reaches the
  internet turns a first-run install into a coin toss on somebody else's uptime.
- `user_profiles` gained `country_id`, nulling on delete so removing a country from
  the reference list does not take somebody's profile with it.

### Changed

#### `notification_subscriptions` → `notification_types` + `notification_preferences`

**Breaking.** Notification types are rows now rather than enum cases alone, so a new
one can be added — and an existing one silenced platform-wide — without a deploy.
`NotificationTypeEnum` stays as the seed source and the name the code refers to.

- `notification_type` is deliberately **not** cast to the enum on `NotificationType`.
  A backed-enum cast would throw on the first type an administrator added, which is
  the whole reason these are rows. Use `knownType()` when you need it back as a case;
  it returns null for a custom one.
- `User::notificationSubscriptions()` → `User::notificationPreferences()`.
- `UserService::runNotificationSubscriptionsUpdate()` →
  `runNotificationPreferencesUpdate()`, which backfills against the *active type rows*
  — so a type added later reaches existing accounts on their next visit.
- New admin screen at Admin → Site configuration → Notification types.

**To take it:** rename both call sites, run the migrations, and run
`NotificationTypeSeeder` before anything reads a preference. With no type rows the
backfill correctly writes nothing, which looks exactly like the feature is broken.

#### Other

- **`WithPasswordTools`** now delegates to `PasswordSecurityService`. `$passwordNote`
  is filled in a `bootWithPasswordTools()` hook, because what it should say depends on
  whether strong passwords are switched on. It also gained `passwordReuseError()`.
- **`layouts::site`** — a fifth layout surface, for public Livewire pages. A public
  page has no sidebar and no navigation links, so `layouts::app` cannot render it.
- **Site configuration** gained `security` and `uploads` groups. Two-factor and social
  sign-in default **off**; strong passwords, password history and passwordless sign-in
  default **on**, so an install nobody has configured yet is the strict one. Every
  switch closes its route as well as hiding its button.

### Dependencies

Added `laravel/socialite`, `pragmarx/google2fa-laravel`, `bacon/bacon-qr-code` and
`spatie/laravel-image-optimizer`, plus the tiptap packages on the npm side.

> **`laravel/socialite` holds guzzle at 7.x.** Laravel 13 ships guzzle 8, and
> `league/oauth1-client` caps below it, so installing Socialite downgraded guzzle
> 8.2.0 → 7.15.5 for the whole application — every `Http::` call, not only
> Socialite's. Removing Socialite is what lifts that.

## [1.1.0] - 2026-09-09

### Added

#### Legal pages, versioned and consented to

Terms, privacy and cookie copy now lives in the database, is written in markdown
from the admin, and is served on public routes that a crawler and an email footer
can both link to.

- **`policies` table and `Policy` model.** One row is one *version* of one page.
  Published text is never edited — it is superseded by a new draft, and the old row
  is archived rather than deleted, because a consent record points at it and text
  that could change afterwards would make the record worthless. `canEdit()` is true
  only for drafts, and the admin screen enforces it.
- **`PolicyTypeEnum`** (`terms`, `privacy`, `cookies`) drives everything. The route
  loop in `routes/web.php`, the admin editor and the site footer all iterate the
  cases, so adding one publishes a page without an edit anywhere else — the three
  `match()` arms on the enum will fail loudly until you fill them in.
- **Public routes** `/terms`, `/privacy`, `/cookies`, named after the enum case
  (`route('terms')`). Served by `PolicyPageController`, a plain controller rather
  than a Livewire page: a legal page is read-only, has to be crawlable, and is
  linked from contracts nobody can go back and edit. `?version=` reaches an
  archived version so somebody can read the exact text they accepted; a draft is
  never reachable.
- **`PolicyContentService`** (`#[Singleton]`) resolves which version is in force
  and compiles markdown into anchored sections. A heading may carry an explicit
  anchor — `## 5. Refunds {#refunds}` — which keeps `/terms#refunds` working after
  somebody rewords the heading. Compilation is cached against the row's
  `updated_at`, so an edit changes the key and the stale entry falls away on its
  own.
- **`live` scope**, distinct from `published`: a policy published today to take
  effect next month is not yet the one people are held to, and the public page
  keeps showing the one that is.
- **Rendering** via `<x-site.legal-page>` — a sticky contents sidebar built from
  the section anchors, a version and last-updated line, and an empty state for a
  type with nothing published yet.

#### Consent capture

- **`user_consents` table and `UserConsent` model.** Deliberately thin and
  immutable: `$timestamps = false`, because consent is a point-in-time fact and a
  table with an `updated_at` that never moved would invite somebody to move it. It
  stores `accepted_at`, IP and user agent — what makes the row evidence rather
  than a claim. `restrictOnDelete` on `policy_id` means a consented-to policy
  cannot be deleted out from under its records.
- **`UserService::recordConsent()`** uses `firstOrCreate` against the
  `[user_id, policy_id]` unique index; a double submit is a thing that happens,
  not an error worth showing anybody.
- **`User::outstandingConsents()`** returns the in-force policies an account has
  not accepted. Because consent is recorded against a specific version, publishing
  a replacement puts everybody back in that list until they accept it.
- **`<x-form.consent-field>`** is shared by every screen that creates an account
  (register and passwordless both). The label is assembled by hand so it can carry
  real links to the policies — a checkbox whose terms are not reachable is not
  consent — and it lists whatever currently requires consent, so a newly published
  type appears without a template edit.
- **Registration** resolves the required policies *before* opening the write
  transaction and records consent inside it, so an account and its consent land
  together or not at all.

#### FAQs

- **`faqs` table, `Faq` model and `FaqTypeEnum`.** Questions with markdown
  answers, an active toggle, and a `flow_order` column the `inFlowOrder` scope
  sorts on. Rendered on the landing page by `<x-site.faq>`.
- Admin editor at **Site configuration → FAQs** — create, edit, set the order,
  toggle active and delete, each write logged.

#### Confirmation modals for destructive writes

- **`<x-dashboard.confirm-modal>`** replaces `wire:confirm` everywhere a workspace
  asks before a destructive action. The browser's own dialog cannot be styled,
  cannot carry an amount or a consequence, and is suppressed outright in some
  in-app browsers — which would leave somebody confirming nothing at all. The
  dialog closes on click rather than in the action, so a guard that refuses the
  write and throws leaves the toast on screen instead of the modal.
- Wired into user deletion, role grant and revoke, social handle removal, security
  settings, FAQ deletion and policy publishing.
- **`WithUserRoleManager`** gained `confirmRoleAction()` / `applyRoleAction()` and
  a `pendingRoleEntry` computed property. The matrix row is re-read when the
  dialog is accepted rather than carried through it — the action it confirms may
  have moved on since it opened.

#### Icon picker

- **`kFluxIcons()`** lists every name `<flux:icon>` can render, read from Blade's
  registered anonymous component paths rather than a hard-coded vendor path — so
  the list stays correct if either side moves, and an icon published into
  `resources/views/flux` shadows the bundled Heroicon of the same name. Pass
  `grouped: true` to key them by source. The directory scan is memoized per
  request.
- **`<x-form.icon-picker>`** is a searchable modal picker bound with `wire:model`.
  It derives its modal name from the bound property, so a page carrying two
  pickers gets two distinct modals.

#### Activity log

- New `ActivityActionEnum` cases: `policy.create`, `policy.update`,
  `policy.publish`, `faq.create`, `faq.update`, `faq.delete`. Publishing is its own
  case rather than an update — it is the moment a version becomes the text people
  are held to, and an audit trail that cannot tell the two apart is not worth
  reading.

#### Admin navigation

- **Site configuration** gained *Policies* and *FAQs* entries
  (`admin.config.policies`, `admin.config.faqs`).

### Changed

- `kGreeting()` accepts a `$greet` argument to override the time-of-day greeting.
- Seeders: `PolicySeeder` ships publishable v1.0 copy for all three policy types,
  `FaqSeeder` a general set. Both run from `DatabaseSeeder`.

### Removed

- **`kit:install` command**, `KitPackageEnum` and `InstallKitCommand`. Optional
  add-ons behind a wizard were a second way to set the project up, and the kit is
  better with one.

### Documentation

- `.agents/my-skills/` updated for the confirmation-modal pattern across
  `forms.md`, `tables.md`, `pages.md`, `ui.md`, `components.md` and `checklist.md`.
- Added the `emil-design-eng` skill — UI polish, component design and animation
  guidance.
- Added the `testing-best-practices` skill, replacing `pest-testing`; refreshed the
  `laravel-best-practices` rule set.

### Upgrading

```bash
php artisan migrate
php artisan db:seed --class=PolicySeeder   # optional: starting copy
php artisan db:seed --class=FaqSeeder      # optional
```

Three tables are added — `policies`, `user_consents`, `faqs` — and nothing
existing is altered.

`PolicySeeder` publishes v1.0 of all three types live and effective immediately,
with terms and privacy marked as requiring consent — so registration starts asking
for it as soon as the seeder runs. **The seeded copy is boilerplate, not legal
advice.** Read it, replace it with your own, and remember that a published version
is superseded rather than edited: draft v2.0 from Admin → Site configuration →
Policies and publish that. Skip the seeder if you would rather write the first
version yourself; `/terms`, `/privacy` and `/cookies` render an empty state until
something is published.

If you already had a `/terms` or `/privacy` route of your own, the enum loop in
`routes/web.php` now claims those paths and route names.

Suite at this release: 122 tests, 266 assertions, all passing.

## [1.0.0] - 2026-09-04

Initial starter kit — authentication with passwordless and OTP email
verification, roles, the admin and member workspaces, account management, site
configuration on the local disk, activity logging, and the house style in
`.agents/my-skills/`.
