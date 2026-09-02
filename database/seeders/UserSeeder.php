<?php

namespace Database\Seeders;

use App\Enums\UserRoleEnum;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;

class UserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * Idempotent on purpose: seeding a database that already has these accounts
     * must not duplicate them or reset a password somebody changed.
     */
    public function run(): void
    {
        $data = [
            [
                'name' => 'Admin User',
                'email' => 'admin@example.test',
                'password' => 'password',
                'email_verified_at' => now(),
                'ip_address' => '127.0.0.1',
            ],
        ];

        foreach ($data as $userData) {
            $admin = User::firstOrCreate(
                ['email' => $userData['email']],
                $userData
            );

            if ($adminRole = Role::where('name', UserRoleEnum::ADMIN)->first()) {
                $admin->roles()->syncWithoutDetaching([$adminRole->id]);
            }
        }
    }
}
