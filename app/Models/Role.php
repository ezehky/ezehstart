<?php

namespace App\Models;

use App\Enums\GateAccessEnum;
use App\Enums\StatusUser;
use App\Enums\UserRoleEnum;
use App\Services\GateService;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\AsArrayObject;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Unguarded]
class Role extends Model
{
    protected function casts(): array
    {
        return [
            'name' => UserRoleEnum::class,
            'gates' => AsArrayObject::class,
        ];
    }

    // Getters

    /**
     * The stored gate map as a plain array, for the callers that merge or count it.
     *
     * The cast hands back an ArrayObject so a nested key can be written in place.
     * Anything spreading, counting or `array_key_exists`-ing the map wants an array,
     * and this is the one place that conversion is spelled out.
     */
    public function gatesArray(): array
    {
        return $this->gates?->toArray() ?? [];
    }

    // Methods

    /**
     * The access this role grants over one navigation key, e.g. 'users' or
     * 'users.roles'. An unconfigured role grants nothing.
     *
     * Delegated rather than read here directly: child-inherits-parent and the
     * explicit-NONE cascade are one rule, and a second copy of it on the model is a
     * second copy to get wrong. Note the map is *flat with dotted keys*, so this is
     * not a data_get() lookup — see gates.md.
     *
     * This answers for the *role* only. To ask what a given administrator may do,
     * go through GateService — an admin can carry an override the role knows
     * nothing about.
     */
    public function gateFor(string $resource): GateAccessEnum
    {
        return app(GateService::class)->roleAccessFor($this, $resource);
    }

    // Relationships

    public function userRoles(): HasMany
    {
        return $this->hasMany(UserRole::class);
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'user_roles')
            ->withPivot('id', 'status')
            ->wherePivot('status', StatusUser::ACTIVE);
    }

    // Scopes

    #[Scope]
    public function isAdmin(Builder $builder): void
    {
        $builder->where('name', UserRoleEnum::ADMIN);
    }

    #[Scope]
    public function isUser(Builder $builder): void
    {
        $builder->where('name', UserRoleEnum::USER);
    }
}
