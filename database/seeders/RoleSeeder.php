<?php

namespace Database\Seeders;

use App\Enums\UserRoleEnum;
use App\Services\UserRoleService;
use Illuminate\Database\Seeder;

class RoleSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * Goes through UserRoleService rather than the model so that a role created here
     * gets the same starting gates as one created anywhere else — the admin role with
     * everything, every other role closed. Roles that already exist keep the gates
     * they have: reseeding must not hand back access somebody took away.
     */
    public function run(): void
    {
        $service = app(UserRoleService::class);

        foreach (UserRoleEnum::cases() as $role) {
            $service->role($role);
        }
    }
}
