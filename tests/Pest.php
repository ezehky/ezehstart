<?php

use App\Enums\PolicyTypeEnum;
use App\Enums\StatusPolicy;
use App\Enums\StatusUser;
use App\Enums\StatusYes;
use App\Enums\UserTypeEnum;
use App\Models\NotificationType;
use App\Models\Policy;
use App\Models\Role;
use App\Models\User;
use App\Services\RoleService;
use Database\Seeders\NotificationTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->beforeEach(function () {
        // The site configuration lives in a JSON file on the local disk, not in a
        // table, so RefreshDatabase does not touch it. Faking the disk keeps each
        // test starting from an unconfigured install and stops the suite writing
        // over the real site-configuration.json.
        Storage::fake('local');

        // config('_site-config') is injected once when the app boots; clear it so
        // one test's configuration cannot leak into the next.
        config(['_site-config' => []]);
        cache()->forget('site_configuration');
    })
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

/**
 * An active account of the given type.
 *
 * Almost every feature test starts here. An admin lands on the protected role unless
 * another is named — that role carries every gate, so a test that just wants "an
 * admin" gets one who can actually open the screen under test. Build the role with a
 * bare firstOrCreate instead and the admin hits a 404 on every admin screen, which is
 * a fixture problem masquerading as a broken page.
 */
function userOfType(UserTypeEnum $type, array $attributes = [], ?Role $role = null): User
{
    $factory = User::factory();

    if ($type->carriesRole()) {
        $factory = $factory->admin($role);
    }

    return $factory->create([
        'status' => StatusUser::ACTIVE,
        ...$attributes,
    ]);
}

/**
 * A role with a gate map, for the tests that care what an admin can and cannot reach.
 *
 * @param  array<string, string>  $gates
 */
function roleWithGates(string $name, array $gates = []): Role
{
    $role = app(RoleService::class)->create($name);

    $role->gates = $gates;
    $role->save();

    return $role;
}

/**
 * A published policy of the given type, in force from now.
 *
 * Most tests only care that one exists, so the content defaults to something with
 * two headings in it — enough for the section compiler to have work to do.
 */
function publishedPolicy(PolicyTypeEnum $type = PolicyTypeEnum::TERMS, array $attributes = []): Policy
{
    return Policy::query()->create([
        'policy_type' => $type,
        'version' => '1.0',
        'title' => $type->defaultTitle(),
        'intro' => 'What this page covers.',
        'content' => '## 1. First heading

The first body.

## 2. Second heading

The second body.',
        'requires_consent' => StatusYes::YES,
        'status' => StatusPolicy::PUBLISHED,
        'effective_at' => now(),
        ...$attributes,
    ]);
}

/**
 * An admin with no role, which can sign in but reaches nothing beyond the dashboard
 * and its own profile.
 */
function adminWithoutRole(array $attributes = []): User
{
    return User::factory()->create([
        'status' => StatusUser::ACTIVE,
        'user_type' => UserTypeEnum::ADMIN,
        'role_id' => null,
        ...$attributes,
    ]);
}

/**
 * Lay down the notification types the seeder ships.
 *
 * RefreshDatabase does not seed, and the preference backfill writes one row per
 * *active type row* — with none, it correctly writes nothing. Any test asserting
 * that a member ends up with switches has to put the types there first.
 *
 * @return Collection<int, NotificationType>
 */
function seededNotificationTypes(): Collection
{
    (new NotificationTypeSeeder)->run();

    return NotificationType::query()->inFlowOrder()->get();
}
