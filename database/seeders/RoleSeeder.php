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
     * Roles are rows an administrator creates, so this only lays down what an install
     * cannot start without: the protected role, which carries every gate and is what
     * the seeded admin signs in on.
     *
     * The starter roles beside it are examples — created closed, and expected to be
     * renamed, re-gated or deleted. They are only ever created, never updated:
     * re-seeding must not hand back access somebody deliberately took away.
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
