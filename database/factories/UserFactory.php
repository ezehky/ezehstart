<?php

namespace Database\Factories;

use App\Enums\UserTypeEnum;
use App\Models\Role;
use App\Models\User;
use App\Services\RoleService;
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
            'user_type' => UserTypeEnum::USER,
        ];
    }

    /**
     * An administrator, on the protected role unless another one is named.
     *
     * The default is deliberate: the protected role carries every gate, so a test
     * that just wants "an admin" gets one who can actually open the screen under
     * test. Pass a role to narrow it, or call withRoles() for more than one.
     *
     * The role is attached after creation rather than set as an attribute: roles live
     * in a pivot now, so there is no column to state. Resolving it inside the callback
     * also keeps a factory that is never created from seeding a protected role as a
     * side effect of being defined.
     */
    public function admin(?Role $role = null): static
    {
        return $this->state(fn (array $attributes) => [
            'user_type' => UserTypeEnum::ADMIN,
        ])->afterCreating(function (User $user) use ($role) {
            $user->roles()->syncWithoutDetaching([
                ($role ?? app(RoleService::class)->protectedRole())->id,
            ]);

            $user->unsetRelation('roles');
        });
    }

    /**
     * An administrator holding several roles at once — the case where the gate maps
     * merge rather than one of them simply winning.
     */
    public function withRoles(Role ...$roles): static
    {
        return $this->state(fn (array $attributes) => [
            'user_type' => UserTypeEnum::ADMIN,
        ])->afterCreating(function (User $user) use ($roles) {
            $user->roles()->syncWithoutDetaching(collect($roles)->pluck('id')->all());

            $user->unsetRelation('roles');
        });
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
