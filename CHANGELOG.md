# Changelog

All notable changes to this starter kit are recorded here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the kit uses
[Semantic Versioning](https://semver.org/spec/v2.0.0.html).

A minor release may add tables, enum cases and route names. It will not rename the
ones already there — that is what a major is for.

## [Unreleased]

Larger than a minor, and it renames things that already shipped — `users.type`,
`UserRoleEnum`, the `user_roles` table, `admin.members`. By the rule at the top of this
file that makes the next release a major. See **Upgrading**.

### Added

#### Newsletter and the cookie notice

The two switches under `preferences` that had nowhere to be turned on and nothing
reading them. Both now have an admin card on **Site configuration → Preferences** and
a consumer on the public pages.

- **A newsletter sign-up with no subscribers table.** An address is a `users` row —
  the account if one exists, and a new `StatusUser::NEWSLETTER_SUBSCRIBER` row if not —
  and the subscription itself is the `ANNOUNCEMENTS` switch in
  `notification_preferences` that every account already carries. One address is one
  row, and a send reads the list every other announcement reads rather than a second
  one that would have to be kept in step with it.
- **Registering later claims the row.** `WithAuthWorker::createUser()` takes over a
  newsletter row for the same address instead of colliding with the unique index, so
  somebody who signed up in March and registered in June keeps their id and their
  subscription. `emailAvailableRule()` is what stops `unique:users,email` refusing them
  in the first place, and the password, passwordless and social flows all go through it.
- **A subscriber row is not an account.** No password, no verified address, and the
  sign-in flows say so rather than reporting a credential mismatch for a password that
  was never set. `User::registered()` keeps them out of the members listing, its
  metrics, the admin dashboard counts and the sign-up trends;
  `StatusUser::forSelect()` keeps the status off the dropdowns, because it is what a
  row *is* until somebody registers rather than something an administrator assigns.
- **Two placements, two switches.** `preferences.newsletter.footer` puts a block above
  the legal links on every public page; `preferences.newsletter.popup` puts a corner
  card up after `popup-delay` seconds. A corner card rather than a modal: a modal that
  appears on a timer has to take focus from somebody mid-sentence to stay usable from a
  keyboard. Dismissal is per-browser, in `localStorage`.
- **`preferences.accept-cookies`** renders a notice in the base shell — every page,
  not just the public ones, because the session cookie it describes is set by signing
  in. It is a notice, not a consent gate: this kit sets session and CSRF cookies and
  nothing else. An install that adds analytics needs a real choice and should turn this
  off and put one in its place.
- **`NewsletterService`** is `#[Singleton]` and owns every read of the four nested
  switches. They sit a level deeper than `kSiteFlag()` reaches, and reading one with
  `kSiteConfig()` would hand back the default the moment somebody turned it off.

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

#### Data tables

Every listing in the kit was building the same six things by hand. **`WithDataTable`**
owns them once: row selection, a bulk bar, a column manager, sorting, a date range, and
taking the whole listing away as a file. All of it is optional — a page gets checkboxes
only where it renders them, and bulk delete only where `tableDeletable()` says so.

- **`columnMaker()`, `filterMaker()` and `metricMaker()`** replace the loose arrays
  these used to be. A key spelled wrong in an array is silently ignored; a named
  argument is a fatal error, and a tone outside the palette says so rather than
  rendering grey.
- **`<x-table.*>`** — `columns`, `rows`, `cell`, `select`, `bulk-bar`,
  `active-filters`, `column-manager`, `export-modal`, `summary` and `empty`. A listing
  is assembled from these rather than from a table written out again per screen.
- **Exports in three formats** through **`ExportService`** — CSV and XLSX via
  `SpreadsheetService`, PDF through a print view, because a PDF is a document rather
  than a sheet and wants a heading and a date on it. What can leave is what the account
  can already see: the gate and the column list decide the file.
- **Imports** through **`WithFileImport`**, which is the same file work in reverse. A
  bad row does not stop the run — half a file rejected because line 40 has a typo is an
  import nobody finishes — so each row is tried on its own and the failures come back
  named for the screen to show.
- **`WithStatusToggle`** flips a row between active and inactive from the listing,
  through `<x-util.e-badge>`. Same gate, same activity log, same toast as the edit form
  it saves somebody opening.

#### Account deletion, end to end

Deletion was a button. It is now a window, with a way back out of it.

- **The grace period.** Asking to be deleted moves the account to `PENDING_DELETION`
  and stamps `deletion_scheduled_at`. Nothing is destroyed until that date passes, and
  cancelling puts everything back — which is the entire point of the delay.
  `config.user.account-deletion-days` sets the window, clamped to 1–365 in code so a
  hand-edited JSON file cannot schedule a deletion for the next tick.
- **`account:process-deletions`**, nightly, does the work at the end of the window:
  anonymize where there is history worth keeping, remove outright where there is not.
- **`account:send-deletion-reminders`** warns at five days and one day
  (`DeletionReminderEnum`), recording each send in **`user_deletion_reminders`** so a
  retried schedule cannot mail the same warning twice.
- **A signed restore link in the email.** `/account/restore/{user}` — somebody who
  changed their mind should not have to sign in to an account that is on its way out.
- **Deleted accounts** (`admin.deleted-accounts`), where anonymized rows surface at
  all. They are soft-deleted, so every other listing's default scope hides them:
  `AccountDeletionService::trashedQuery()` is the only way to reach one, and this screen
  is where it is restored or purged for good.
- Three mails — scheduled, reminder, cancelled — and `--dry-run` on both commands.

#### A copy of what is held about you

**`AccountDataExportService`** and `/app/account/download-data`, behind
`config.user.allow-data-download`. The companion to deletion: a kit that ships "delete
what you hold about me" without "show me what you hold about me" is half of the
obligation.

One JSON document rather than a spreadsheet, because an account is a profile plus a
ledger plus a consent history plus two library indexes — several shapes at once, which
is what a nested document is for and what a CSV cannot be. The password hash and its
history, the two-factor secret, the recovery codes and the admin gate map are
deliberately **left out**: they are *about* the account without belonging to the
person, and including them only widens the blast radius of one leaked export.

#### Impersonation

**`ImpersonationService`** — an administrator signs in as a member to see what they
see, because "the button does not work" is unanswerable from the outside. It is also
the most dangerous thing an admin can do, so every part of it is built assuming it will
one day be abused or forgotten about:

- **Never admin to admin.** Becoming a peer is privilege escalation with a support
  story attached, and the audit trail would name the wrong person for everything that
  followed.
- **The real identity lives in the session**, never in a signed URL or a query
  parameter. A token in a link is a token that gets copied, logged and replayed.
- **Both ends are logged as the administrator** — before the swap and after it — so the
  trail reads "X started acting as Y" rather than going quiet.
- **It expires after an hour.** While it runs the session passes `UserMiddleware` and
  never `AdminMiddleware`, which is why the expiry is checked there.
- **Account-altering screens are closed**, each with an `abort_if` in `mount()` rather
  than a hidden button. Impersonation is for looking.

#### Authors

A seeded **Author** role (`RoleService::authorRole()`), matched by slug rather than by
gate: plenty of roles reach the blog, but only this one says the person *is* an author,
which is what decides whose byline carries a bio and which posts they are held to. The
role can be renamed, re-gated or deleted freely; the slug is what the blog is written
against. Bio and social handles live on `user_profiles`, and `<x-site.author-card>`
renders the byline on the public post page.

#### Scheduled posts and announcements

- **`blog:publish-scheduled`**, every minute — a post scheduled for 09:00 that appears
  at 09:15 has missed the thing it was scheduled for. Each post is claimed before it is
  published, so two overlapping runs cannot announce it twice.
- **`NotificationSubscriberService`** fans an announcement out to everybody subscribed
  to a notification type, in chunks. It deliberately knows nothing about posts: a
  release note, a price change and a maintenance window all want the same machinery,
  and each should be a caller rather than a copy. Subscription is the
  `notification_preferences` row an account already owns, so there is no second list to
  keep in step with the settings screen.
- `NewPostEmail` and its template.

#### Captcha on the guest forms

**`CaptchaService`**, **`CaptchaRule`**, **`WithCaptcha`** and `<x-form.captcha>`,
backed by Cloudflare Turnstile. Deliberately a per-form check rather than a challenge
in front of the whole site: a site-wide challenge is a setting on a Cloudflare-proxied
domain rather than something an administrator switches on from this dashboard, and the
middleware version of it would sit in front of every Livewire round trip, the social
callback and every gateway webhook. The form is what is being abused, so the form is
where the token is checked.

The widget and the rule are added together by `captchaRules()`, never separately — a
page that renders the widget without the rule is decorated rather than protected, and
one that adds the rule without the widget cannot be submitted. Off until switched on,
and off regardless while the Turnstile keys are missing, because a switch turned on
against empty credentials would make every guest form on the install unsubmittable.

#### Guards on every emailed code

**`WithOtpGuard`** gives all four code flows the same two bounds: five wrong guesses
destroy the outstanding code, and another cannot be asked for inside 60 seconds. A
six-digit code is a million guesses wide, so an expiry is not on its own a bound —
given an unlimited guess rate the whole space fits inside the window. Destroying the
code is what closes that, and the resend floor is what stops the destruction being
undone by simply asking for another.

**`PasswordResetOtpService`** moves the forgotten-password code into Laravel's own
`password_reset_tokens` table, so the broker's `expire` setting stays the one place the
lifetime is configured and a pending reset survives a cache flush. Only the hash is
stored. **`WithAccountOtp`** puts the resend floor in one place rather than at the five
call sites that were each remembering it.

#### Activity log retention

`activity:prune-logs`, nightly, and `config.security.activity-log-retention-days`.
**Off by default at 0**: throwing away an audit trail is a decision somebody makes, not
something an install should start doing quietly. Anything above 0 is floored at 30
days, and `--days` prunes once without changing the setting that would then prune every
night.

#### Trends and metrics

- **`TrendService`** and `TrendPeriodEnum` — the series behind every sparkline. A total
  says where something stands; the line says whether it got there steadily or in one
  week. Bucketing a date column the way the driver spells it, reading the window in one
  grouped query and padding the buckets nothing landed in is the same six steps every
  time, and an unpadded gap draws a line climbing through months that never happened.
- **`WithMetrics`** and a reworked `<x-dashboard.stat-card>` — one tile, named
  arguments, a tone from the palette, an optional trend.

#### Per-account dashboard arrangements

**`DashboardManagerService`** — what one account has arranged for itself, as a JSON file
per account on the same disk the site configuration uses. These are preferences:
nothing joins on them, nothing reports on them, and a column somebody hid is not worth a
migration, an index and a row per account per screen. The column manager saves as each
switch is thrown, and what it stores is the list of columns put *away* — so a column
added to a screen next month appears for everybody rather than staying hidden from the
people who use that screen most.

#### Site configuration, split up

One long form became four screens under `/app-splash/site-config`: **Info**,
**Security**, **Preferences**, and a raw **JSON editor** for the key no screen has grown
a field for yet. **`WithSiteConfigProcessor`** carries the save and the audit entry, and
its `configSubject()` is abstract rather than defaulted — these switches close sign-in
routes and set the password policy, so a new configuration screen has to say what it is
answerable for instead of logging as "something".

New keys: `security.captcha`, `security.activity-log-retention-days`,
`user.account-deletion-days`, `user.anonymous-after-deletion`,
`user.allow-data-download`, `uploads.user-video-limit`,
`preferences.mobile-floating-menu`, `preferences.accept-cookies`,
`preferences.newsletter.*`.

#### Components

- **`<x-form.date-field>`** — a date field with a calendar, for the Flux tier that has
  none. Single or range, and the value is a plain `YYYY-MM-DD` string, which is what
  makes a date filter read like every other filter in the project. The binding runs both
  ways, so a property cleared on the server empties the box rather than leaving a range
  printed in a field the listing below has stopped honouring.
- **`<x-util.e-badge>`** — an enum as a badge, or as a switch where the page can flip
  it. The switch appears only for a two-case enum that answers a boolean question, and
  an account below the level sees the badge instead.
- **`<x-util.countdown>`** — the shared timer behind the resend floor, the impersonation
  expiry and the deletion window.
- **`<x-util.floating-actions>`** — a long form's action bar, pinned while its resting
  place is off screen and dropped back into the page the moment you scroll to it. The
  space it leaves is held open, so nothing jumps.
- **`<x-dashboard.page-header>`** and **`<x-dashboard.breadcrumb>`** — the block every
  dashboard screen opens with. Passing nothing is the normal case: `kSetSiteTitle()` has
  already named the page, and this is what finally renders it.
- **`<x-dashboard.gate.button>`**, **`<x-dashboard.gate.menu-item>`** and
  **`WithGateProps`** — `setPageGate()` names the gate a screen sits behind and closes
  the screen to an account that does not hold it. Hiding the sidebar entry was always a
  courtesy; a page whose key is only remembered for its buttons is still reachable by
  anyone who types the URL.
- **`<x-dashboard.mobile-menu>`** and a floating bottom bar for the member workspace,
  behind `config.preferences.mobile-floating-menu` and off by default — an install that wants
  only the drawer should not have to turn a second navigation off.
- **`<x-site.author-card>`**, **`<x-dashboard.role.badges>`**,
  **`<x-transaction.amount>`**, **`<x-auth.passwordless>`**,
  **`<x-auth.social-providers>`**.
- **Recovery codes download** as a text file from the two-factor screen, and
  **`UploadModifiedEmail`** tells a member when somebody at the site changed something
  in their library — their uploads are private to them, so a change they did not make is
  something they should hear about rather than discover.

#### Demo seeders

`DemoSeeder`, and the `DemoUserSeeder`, `DemoContentSeeder` and `DemoTransactionSeeder`
under it — everything a developer wants on screen and nothing an install needs, so
deliberately absent from `DatabaseSeeder`:

```bash
php artisan db:seed --class=DemoSeeder
```

A fresh install gets roles, countries, notification types and one administrator. It does
not get twenty invented people, a ledger full of invented money, or placeholder legal
copy nobody wrote. Every seeder under it matches on a natural key, so a second run
refreshes rather than doubles, and the uploaded images and saved dashboard arrangements
are cleared first.

### Changed

- **An admin now carries any number of roles.** `user_roles` — a pivot with a status
  column — is replaced by **`role_user`**, a plain made-or-unmade pivot, unique on the
  pair. The maps merge with the **highest access winning each key**, so a second role
  only ever widens what somebody reaches, which is what makes "Media as well as Support"
  something an administrator can express without inventing a third role that is the sum
  of the two. The personal override sits on top of the merged result and is the only
  thing that can narrow it. `UserRoleService` and the `UserRole` model are gone;
  **`RoleService`** replaces both, and every guard on it answers in a sentence rather
  than a boolean so the screen can say *why*.
- **`UserRoleEnum` is now `UserTypeEnum`, and `users.type` is now `users.user_type`.**
  Type is the workspace an account signs in to and is fixed in code; a role is a row an
  administrator creates. One name for both was where the confusion came from — and
  `type` was a reserved word in the column besides.
- **`ImageVisibilityEnum` is now `MediaVisibilityEnum`.** Both libraries answer "who may
  see this" identically, and two copies would drift the first time either gained a case.
  Stored values are untouched — `private`, `role`, `public` — so this is a rename in code
  only, with no migration. `description()` takes the noun to use, because "Only you and
  administrators can see this image" is wrong on a video screen.
- **`<x-status>` is now `<x-util.e-badge>`**, and it can be a switch as well as a badge.
- **Admin routes regrouped.** `admin.members` → `admin.users`, `admin.site-config` →
  `admin.config.site`, and the rest of the configuration screens moved under the
  `admin.config.` prefix. `admin.deleted-accounts` is new, as are the per-member library
  routes `admin.user-image-library` and `admin.user-video-library` — a member's uploads
  are private and deliberately absent from the admin library and picker, so those routes
  are the only way in, reached from the account page and never by browsing.
- **`<x-dashboard.gates-modal>` → `<x-dashboard.gate.modal>`** and
  **`<x-dashboard.user-roles-modal>` → `<x-dashboard.role.modal>`**.
- **The admins listing absorbed the unassigned-accounts screen** as a "No live role"
  filter. A separate page for one filtered view of a list that now has filters was a
  second screen to keep in step for no gain.

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
- **Sign-in sent some accounts to the wrong workspace.** The redirect still read the
  old `type` reference after the rename, so the fallback ran instead of the match.
  Remember that `UserTypeEnum::dashboardRoute()` returns a URL, not a route name — pass
  it to `redirect()->to()`.

### Removed

- **`UserRole`, `UserRoleService` and the `user_roles` table** — see Changed.
- **The unassigned-accounts screen**, now a filter on the admins listing.
- **`<x-dashboard.user-role>`** and **`<x-status>`**.

### Dependencies

Added `spatie/laravel-pdf` with `dompdf/dompdf` for PDF exports, and
`openspout/openspout` for CSV and XLSX in both directions — openspout streams a workbook
a row at a time rather than loading it into memory, which is what keeps an import of any
size flat on memory.

dompdf ships with the kit and needs nothing installed. The other laravel-pdf drivers —
browsershot, gotenberg, chrome, weasyprint — each need their own package and binaries;
`LARAVEL_PDF_DRIVER` picks between them.

### Upgrading

```bash
php artisan migrate:fresh --seed
```

**This is the release that renames things.** `users.type` → `users.user_type`,
`user_roles` → `role_user`, `UserRoleEnum` → `UserTypeEnum`, `admin.members` →
`admin.users`, `admin.site-config` → `admin.config.site`, `ImageVisibilityEnum` →
`MediaVisibilityEnum`, `<x-status>` → `<x-util.e-badge>`. A project already built on
1.2.0 has to sweep for each of those; a project starting here has nothing to do.

Role assignments do not carry across: `user_roles` held a status column and `role_user`
does not, because an inactive assignment and no assignment grant exactly the same access
and only one of the two can be reasoned about. Re-assign from **Admins → Manage roles**,
where an account can now hold several.

Two new environment keys, both optional:

```env
TURNSTILE_SITE_KEY=
TURNSTILE_SECRET_KEY=
LARAVEL_PDF_DRIVER=dompdf
```

The captcha switch does nothing until both Turnstile keys are present — deliberately,
because a switch turned on against empty credentials renders a widget that can never
issue a token, and every guest form on the install becomes unsubmittable.

Four scheduled commands are new, and they need a scheduler actually running:
`account:send-deletion-reminders` and `account:process-deletions` nightly,
`blog:publish-scheduled` every minute, and `activity:prune-logs` nightly — the last of
which does nothing until somebody sets a retention window. Each takes `--dry-run`.

Defaults worth knowing before an install goes live: account deletion is **on**,
anonymize-after-deletion is **on**, the data download is **on**, the captcha is **off**,
the mobile floating menu is **off**, and activity-log retention is **off** — keep
everything.

Suite at this point: 742 tests, 1848 assertions, all passing.

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
  the cursor. It inserts images through `<livewire:livewire.library.image-picker>` and never talks
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
