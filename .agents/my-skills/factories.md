# factories.md

## Rule

There is exactly **one** factory in this project: `database/factories/UserFactory.php`.
Every other model is created in tests by a **file-local seed helper** (see
[testing.md](testing.md)) or in seeders by `updateOrCreate`.

```php
<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
            'ip_address' => fake()->ipv4(),
        ];
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }
}
```

### Conventions when you do add one

- `/** @use HasFactory<UserFactory> */` on the model's `use HasFactory` line:

```php
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, SoftDeletes, WithDynamicModelFormatting;
```

- `/** @extends Factory<User> */` on the factory class.
- `definition(): array` with an `@return array<string, mixed>` docblock.
- `fake()` — the global helper, **not** `$this->faker`.
- States are named methods returning `static`, each with a one-line docblock:
  `unverified()`, `active()`, `concluded()`.
- Hash the password once into a `static` property — the factory is called hundreds of
  times per suite and bcrypt is deliberately slow.

### Using it in tests

```php
$admin = User::factory()->create(['status' => StatusUser::ACTIVE]);
$user = User::factory()->unverified()->create();
$users = User::factory()->count(3)->create();
```

Note the factory does **not** assign a role. Roles are rows, so tests wire them
explicitly:

```php
$role = app(RoleService::class)->protectedRole();
$admin->roles()->syncWithoutDetaching([$role->id]);
```

### The alternative — file-local seed helpers

For everything else, define a named helper in the test file:

```php
/**
 * A question already on the site.
 */
function seededFaq(array $overrides = []): Faq
{
    return Faq::query()->create([
        'faq_type' => FaqTypeEnum::GENERAL,
        'question' => 'Do I need any design experience to join?',
        'answer' => 'No. Our programmes start from the fundamentals.',
        'flow_order' => 1,
        'status' => StatusDefault::ACTIVE,
        ...$overrides,
    ]);
}
```

Called as `seededFaq(['flow_order' => 4])`. The `...$overrides` spread at the end is
the state mechanism.

### When to write a real factory instead

Write one when **all** of these hold:

- three or more test files need the same model,
- the model has several meaningful states,
- the setup is more than a flat `create([...])` (relations, nested records).

`Cohort` is the strongest current candidate — it needs a training, a schedule, and a
status. If you add it, add `HasFactory` to the model and follow the conventions above.

## Why

- Models are `#[Unguarded]`, so `Model::query()->create([...])` already accepts a flat
  array — a factory adds little for a model used in one test file.
- A helper named for the scenario (`seededFaq`, `seededCohort`) documents the fixture
  better than a chain of anonymous states.
- `fake()` over `$this->faker` because Pest tests are closures, not classes — there is
  no `$this->faker` in a `test()` body.
- The static password hash keeps the suite fast; bcrypt at cost 12 per user would
  dominate the run time.

## Example

`tests/Feature/AdminFaqTest.php` combines both mechanisms:

```php
beforeEach(function () {
    $admin = User::factory()->create(['status' => StatusUser::ACTIVE]);   // factory
    $role = app(RoleService::class)->protectedRole();
    $admin->roles()->syncWithoutDetaching([$role->id]);

    $this->actingAs($admin);
});

function seededFaq(array $overrides = []): Faq { … }                      // helper
```

## Template

If a model genuinely earns a factory:

```php
<?php

namespace Database\Factories;

use App\Enums\StatusInvoice;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Invoice>
 */
class InvoiceFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'reference' => kReferenceId('INV-'),
            'title' => fake()->sentence(3),
            'amount' => fake()->numberBetween(100000, 5000000),
            'issued_at' => now(),
            'status' => StatusInvoice::DRAFT,
        ];
    }

    /**
     * Indicate that the invoice has been issued to the customer.
     */
    public function issued(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => StatusInvoice::ISSUED,
            'issued_at' => now(),
        ]);
    }

    /**
     * Indicate that the invoice has been settled.
     */
    public function paid(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => StatusInvoice::PAID,
        ]);
    }
}
```

```php
// app/Models/Invoice.php
/** @use HasFactory<InvoiceFactory> */
use HasFactory, WithDynamicModelFormatting;
```

```php
$invoice = Invoice::factory()->issued()->create();
$paid = Invoice::factory()->paid()->for($user)->create();
```

Create with `php artisan make:factory InvoiceFactory --model=Invoice --no-interaction`.

## Avoid

- Adding a factory for a model used by a single test file — write the helper.
- `$this->faker` — use `fake()`.
- A factory without the `@extends Factory<Model>` docblock.
- `HasFactory` on the model without the `/** @use HasFactory<XFactory> */` line.
- Hashing a password per record instead of once into a `static`.
- States that are not documented.
- Assuming `User::factory()` grants a role — it does not.
- Seeded production content in a factory (that is a seeder) or Faker data in a seeder
  (that is a factory).
