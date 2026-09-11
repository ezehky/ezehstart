<?php

namespace Database\Seeders;

use App\Enums\StatusUser;
use App\Enums\UserTypeEnum;
use App\Models\User;
use App\Services\RoleService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * The people the other demo seeders hang off: two dozen members, a few administrators
 * with different reach, and an author with a byline.
 *
 * Every account is created against its email, so running this twice refreshes the
 * same two dozen rather than making another two dozen.
 */
class DemoUserSeeder extends Seeder
{
    /** How many member accounts to make. */
    public const MEMBERS = 24;

    public function run(): void
    {
        $service = app(RoleService::class);

        // Members. A handful are left unverified and a couple suspended, because a
        // listing where every row looks identical hides the states that matter.
        for ($index = 1; $index <= self::MEMBERS; $index++) {
            User::query()->firstOrCreate(
                ['email' => "member{$index}@example.test"],
                [
                    'name' => fake()->name(),
                    'password' => Hash::make('password'),
                    'phone_number' => fake()->numerify('080########'),
                    'email_verified_at' => $index % 6 === 0 ? null : now()->subDays(rand(1, 300)),
                    'ip_address' => fake()->ipv4(),
                    'user_type' => UserTypeEnum::USER,
                    'status' => $index % 11 === 0 ? StatusUser::SUSPENDED : StatusUser::ACTIVE,
                    'created_at' => now()->subDays(rand(1, 400)),
                ]
            );
        }

        // An author, so the blog has a byline and the narrowed-to-own-posts path has
        // somebody to narrow to.
        $author = User::query()->firstOrCreate(
            ['email' => 'author@example.test'],
            [
                'name' => 'Ada Writes',
                'password' => Hash::make('password'),
                'email_verified_at' => now(),
                'user_type' => UserTypeEnum::ADMIN,
                'status' => StatusUser::ACTIVE,
            ]
        );

        $author->roles()->syncWithoutDetaching([$service->authorRole()->id]);

        $author->userProfile()->updateOrCreate([], [
            'bio' => 'Writes about the things this site is about. Invented, like everything else in the demo data.',
            'socials' => ['x' => 'adawrites'],
        ]);

        // A second administrator on no role at all: the account the admins listing is
        // meant to call out, and the one the gate screens are designed around.
        User::query()->firstOrCreate(
            ['email' => 'unassigned@example.test'],
            [
                'name' => 'Grace Unassigned',
                'password' => Hash::make('password'),
                'email_verified_at' => now(),
                'user_type' => UserTypeEnum::ADMIN,
                'status' => StatusUser::ACTIVE,
            ]
        );
    }
}
