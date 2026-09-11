# seeders.md

## Rule

Seeders live in `database/seeders/`, named `{Subject}Seeder`, and are **idempotent** —
every one uses `updateOrCreate` or `firstOrCreate` so re-running refreshes data without
duplicating it.

```php
<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Services\RoleService;
use Illuminate\Database\Seeder;

class RoleSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * Only what an install cannot start without: the protected role, which carries
     * every gate. The starter roles beside it are examples, created closed and only
     * ever created — re-seeding must not hand back access somebody took away.
     */
    public function run(): void
    {
        $service = app(RoleService::class);

        $service->protectedRole();

        foreach (RoleService::STARTER_ROLES as $name => $description) {
            if (Role::query()->where('slug', str($name)->slug())->exists()) {
                continue;
            }

            $service->create($name, $description);
        }
    }
}
```

### `DatabaseSeeder` — order matters

```php
class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $seeders = [];

        if (! kSiteConfig()) {
            $seeders[] = SiteConfigSeeder::class;
        }

        $seeders = [
            ...$seeders,
            CountrySeeder::class,
            StateSeeder::class,
            RoleSeeder::class,
            TrainerRoleSeeder::class,
            UserSeeder::class,
            // Both run after the site config so their copy can quote the site's
            // own name and contact details.
            PolicySeeder::class,
            FaqSeeder::class,
        ];

        $this->call($seeders);
    }
}
```

Notes:

- `use WithoutModelEvents;`
- `SiteConfigSeeder` runs **conditionally** — only when no configuration exists yet.
- Dependency order: reference data → roles → users → content that quotes the config.
- A comment explains any non-obvious ordering.
- One `$this->call($seeders)` with the array.

### Enum-driven seeders

Anything whose rows mirror an enum iterates the enum, so adding a case seeds a row:

```php
foreach (NotificationTopicEnum::cases() as $topic) {
    NotificationType::updateOrCreate(['topic' => $topic]);
}
```

Roles are **not** one of these. They are rows an administrator creates, there is no
enum behind them, and the seeder lays down starting points rather than a vocabulary.

### Content seeders

Content lives in a `protected` method returning a typed array; `run()` stays a loop.
The array index supplies the display order.

```php
/**
 * The questions prospective students ask before they enrol.
 *
 * Seeded in display order and matched on the question itself, so re-running the
 * seeder refreshes the answers without duplicating any of them. Everything here
 * is editable afterwards under Admin → Config → FAQs.
 *
 * Answers are markdown. Links are written relative so they follow the site the
 * seeder runs against rather than baking in whatever APP_URL was set at the time.
 */
class FaqSeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->faqs() as $order => $faq) {
            Faq::query()->updateOrCreate(
                ['question' => $faq['question']],
                [
                    ...$faq,
                    'faq_type' => FaqTypeEnum::GENERAL,
                    'flow_order' => $order + 1,
                    'status' => StatusDefault::ACTIVE,
                ]
            );
        }
    }

    /**
     * @return array<int, array{question: string, answer: string}>
     */
    protected function faqs(): array
    {
        $name = kSiteConfig('name');
        $site = \is_string($name) && $name !== '' ? $name : (string) config('app.name');

        return [
            [
                'question' => 'Do I need any design experience to join?',
                'answer' => 'No. Our programmes start from the fundamentals and build up, …',
            ],
            …
            [
                'question' => 'How does the affiliate programme work?',
                'answer' => "Share your affiliate code from your dashboard. When someone enrols through it, a commission is credited to your {$site} wallet, …",
            ],
        ];
    }
}
```

Rules for seeded content:

- **Match on the natural key** (`['question' => …]`, `['email' => …]`,
  `['name' => $role]`) so the second run updates rather than inserts.
- **Relative links only** (`/terms#payments`) so the copy follows whatever host it is
  seeded on.
- Read the site name from `kSiteConfig()` with a `config('app.name')` fallback.
- A class docblock explaining what the copy is and where it can be edited afterwards.

### User seeder

```php
$adminRole = app(RoleService::class)->protectedRole();

$data = [[
    'name' => 'Admin User',
    'email' => 'admin@example.test',
    'password' => 'password',
    'user_type' => UserTypeEnum::ADMIN,
]];

foreach ($data as $userData) {
    $user = User::firstOrCreate(['email' => $userData['email']], $userData);

    // Attached rather than synced: re-seeding a live install must not strip roles
    // somebody added to this account.
    $user->roles()->syncWithoutDetaching([$adminRole->id]);
}
```

`firstOrCreate` on the email, so re-seeding never duplicates the account or resets a
password somebody changed. The admin goes on the **protected** role rather than a
starter one: the starters ship closed, and an install whose only admin reaches nothing
has no screen left to fix itself from.

### Running

```bash
php artisan db:seed
php artisan db:seed --class=FaqSeeder
php artisan migrate:fresh --seed
```

### Seeders are tested

`tests/Feature/FaqSeederTest.php` exists — a seeder that ships user-facing copy gets a
test covering idempotency and content correctness.

## Why

- Idempotency means `db:seed` is safe to run against a live database to refresh
  reference data and default copy — which is how new FAQs and policy versions are
  actually rolled out.
- Matching on the natural key rather than the id means the seeder does not care what
  ids exist.
- Iterating enums keeps `roles` and `trainer_roles` in lockstep with the code with no
  second list to maintain.
- Relative links and config-sourced names keep seeded copy correct across local,
  staging, and production without per-environment seed data.
- The conditional `SiteConfigSeeder` avoids overwriting a configured site every time
  someone reseeds reference data.

## Example

`RoleSeeder` (above) is the minimal form; `FaqSeeder` (above) is the content form.

## Template

```php
<?php

namespace Database\Seeders;

use App\Enums\InvoiceChannelEnum;
use App\Enums\StatusInvoice;
use App\Models\InvoiceTemplate;
use Illuminate\Database\Seeder;

/**
 * The invoice templates finance starts from.
 *
 * Matched on the template name, so re-running the seeder refreshes the wording
 * without duplicating any of them. Everything here is editable afterwards under
 * Admin → Finance → Templates.
 */
class InvoiceTemplateSeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->templates() as $order => $template) {
            InvoiceTemplate::query()->updateOrCreate(
                ['name' => $template['name']],
                [
                    ...$template,
                    'channel' => InvoiceChannelEnum::EMAIL,
                    'flow_order' => $order + 1,
                    'status' => StatusInvoice::DRAFT,
                ]
            );
        }
    }

    /**
     * @return array<int, array{name: string, body: string}>
     */
    protected function templates(): array
    {
        $name = kSiteConfig('name');
        $site = \is_string($name) && $name !== '' ? $name : (string) config('app.name');

        return [
            [
                'name' => 'Cohort enrolment',
                'body' => "Thank you for enrolling with {$site}. See [our terms](/terms#payments).",
            ],
        ];
    }
}
```

Register it in `DatabaseSeeder::run()` in dependency order, then
`php artisan db:seed --class=InvoiceTemplateSeeder`.

## Avoid

- `Model::create()` in a seeder — always `updateOrCreate` / `firstOrCreate`.
- `truncate()` or `delete()` before seeding.
- Matching on `id`.
- Absolute URLs (`https://site.test/terms`) in seeded copy.
- Hard-coding the site name instead of `kSiteConfig('name')`.
- `$this->call()` in an order that breaks a foreign key.
- Overwriting an existing site configuration unconditionally.
- `roles()->sync()` (strips granted roles) — use `syncWithoutDetaching()`.
- Faker in a seeder that ships production data; `fake()` belongs in factories.
- A seeder that ships user-facing copy without a test.
