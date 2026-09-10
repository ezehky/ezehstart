<?php

use App\Enums\PolicyTypeEnum;
use App\Enums\StatusDefault;
use App\Enums\StatusPolicy;
use App\Enums\StatusUser;
use App\Enums\StatusYes;
use App\Enums\UserRoleEnum;
use App\Models\NotificationType;
use App\Models\Policy;
use App\Models\Role;
use App\Models\User;
use App\Models\UserRole;
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
 * An active account carrying the given role.
 *
 * Almost every feature test starts here — a workspace is unreachable without a role,
 * so a plain User::factory() account can only ever assert a redirect.
 */
function userWithRole(UserRoleEnum $role, array $attributes = []): User
{
    $user = User::factory()->create([
        'status' => StatusUser::ACTIVE,
        ...$attributes,
    ]);

    $roleRecord = Role::query()->firstOrCreate(['name' => $role]);

    UserRole::query()->create([
        'user_id' => $user->id,
        'role_id' => $roleRecord->id,
        'status' => StatusDefault::ACTIVE,
    ]);

    return $user;
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
 * An account holding no role at all, which can sign in but reach no workspace.
 */
function userWithoutRole(array $attributes = []): User
{
    return User::factory()->create([
        'status' => StatusUser::ACTIVE,
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
