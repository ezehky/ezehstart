<?php

namespace App\Models;

use App\Enums\StatusUser;
use App\Enums\UserRoleEnum;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Builder;
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
        ];
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
