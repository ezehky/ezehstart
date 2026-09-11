<?php

namespace Database\Seeders;

use App\Enums\UserTypeEnum;
use App\Models\User;
use App\Services\RoleService;
use Illuminate\Database\Seeder;

class UserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * Idempotent on purpose: seeding a database that already has these accounts
     * must not duplicate them or reset a password somebody changed.
     *
     * The admin is put on the protected role rather than on a starter one — the
     * starters ship closed, and an install whose only admin reaches nothing has
     * no screen left to fix itself from.
     */
    public function run(): void
    {
        $adminRole = app(RoleService::class)->protectedRole();

        $data = [
            [
                'name' => 'Admin User',
                'email' => 'admin@example.test',
                'password' => 'password',
                'email_verified_at' => now(),
                'ip_address' => '127.0.0.1',
                'user_type' => UserTypeEnum::ADMIN,
            ],
        ];

        foreach ($data as $userData) {
            $user = User::firstOrCreate(
                ['email' => $userData['email']],
                $userData
            );

            // Attached rather than synced: seeding an install that has been running
            // for a while must not strip roles somebody added to this account.
            $user->roles()->syncWithoutDetaching([$adminRole->id]);
        }
    }
}
