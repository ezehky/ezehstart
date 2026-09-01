<?php

namespace App\Models;

use App\Enums\StatusUser;
use App\Enums\UserRoleEnum;
use App\Traits\WithDynamicModelFormatting;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Collection;

#[Unguarded]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, SoftDeletes, WithDynamicModelFormatting;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'last_seen_at' => 'datetime',
            'status' => StatusUser::class,
        ];
    }

    // Getters

    public function isAdmin(): bool
    {
        return $this->userRoles()
            ->isActive()
            ->whereHas('role', fn ($query) => $query->isAdmin())
            ->exists();
    }

    public function isUser(): bool
    {
        return $this->userRoles()
            ->isActive()
            ->whereHas('role', fn ($query) => $query->isUser())
            ->exists();
    }

    public function hasRole(UserRoleEnum $role): bool
    {
        return match ($role) {
            UserRoleEnum::ADMIN => $this->isAdmin(),
            UserRoleEnum::USER => $this->isUser(),
        };
    }

    /**
     * The roles this user actively carries, read from the loaded relation so a
     * listing can render them without a query per row.
     *
     * @return Collection<int, UserRoleEnum>
     */
    public function activeRoles(): Collection
    {
        return $this->userRoles
            ->filter(fn (UserRole $userRole) => $userRole->status->isActive())
            ->map(fn (UserRole $userRole) => $userRole->role?->name)
            ->filter()
            ->values();
    }

    public function firstName(): string
    {
        $first = (string) str($this->name)->before(' ');

        return $first !== '' ? $first : $this->name;
    }

    public function initials()
    {
        return str($this->name)
            ->explode(' ')
            ->map(fn ($part) => str($part)->substr(0, 1))
            ->take(2)
            ->join('');
    }

    public function profileCompletion(): int
    {
        $profile = $this->userProfile;
        $fields = [
            (bool) $this->avatar,
            (bool) $this->phone_number,
            (bool) $profile?->gender,
            (bool) $profile?->bio,
        ];

        return (int) round(collect($fields)->filter()->count() / \count($fields) * 100);
    }

    // Relationships

    public function userProfile(): HasOne
    {
        return $this->hasOne(UserProfile::class);
    }

    public function roles()
    {
        return $this->belongsToMany(Role::class, 'user_roles')
            ->withPivot('id', 'status')
            ->wherePivot('status', StatusUser::ACTIVE);
    }

    public function userRoles(): HasMany
    {
        return $this->hasMany(UserRole::class)
            ->select('id', 'user_id', 'role_id', 'status');
    }

    public function notificationSubscriptions(): HasMany
    {
        return $this->hasMany(NotificationSubscription::class);
    }

    public function activityLogs(): HasMany
    {
        return $this->hasMany(ActivityLog::class);
    }

    // Scopes

    #[Scope]
    protected function carriesRole(Builder $builder, UserRoleEnum $role): void
    {
        $builder->whereHas('userRoles', fn ($query) => $query
            ->isActive()
            ->whereHas('role', fn ($roleQuery) => $roleQuery->where('name', $role)));
    }

    /**
     * Accounts holding no active role at all. They cannot reach any workspace until
     * one is granted, so they are surfaced on their own admin listing.
     */
    #[Scope]
    protected function carriesNoRole(Builder $builder): void
    {
        $builder->whereDoesntHave('userRoles', fn ($query) => $query->isActive());
    }
}
