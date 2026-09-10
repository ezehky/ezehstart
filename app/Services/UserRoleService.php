<?php

namespace App\Services;

use App\Enums\ActivityActionEnum;
use App\Enums\StatusDefault;
use App\Enums\UserRoleEnum;
use App\Models\Role;
use App\Models\User;
use App\Models\UserRole;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

#[Singleton]
class UserRoleService
{
    /**
     * Sets of roles an account may never hold at the same time.
     *
     * The starter ships none — admin and user stack freely. Add a group here the
     * moment two roles become exclusive populations (staff versus learner, say),
     * and the role manager switches from "grant" to "switch" for them on its own.
     *
     * @var array<int, array<int, UserRoleEnum>>
     */
    public const EXCLUSIVE_GROUPS = [];

    /**
     * Resolve the role record for an enum case, creating it the first time it is used.
     *
     * A newly created admin role starts with every gate at full access. Gates default
     * to none, so an admin role granting nothing would come up with an empty sidebar
     * and no screen left to grant anything from. Every other role starts closed.
     *
     * A role that already exists is returned untouched — resolving a role must never
     * hand back access somebody deliberately took away.
     */
    public function role(UserRoleEnum $role): Role
    {
        $record = Role::query()->firstOrNew(['name' => $role]);

        if (! $record->exists) {
            $record->gates = $role->isAdmin() ? app(GateService::class)->fullAccessMap() : [];
            $record->save();
        }

        return $record;
    }

    /**
     * The roles a user actively carries.
     *
     * @return Collection<int, UserRoleEnum>
     */
    public function rolesFor(User $user): Collection
    {
        return $user->userRoles()
            ->isActive()
            ->with('role')
            ->get()
            ->map(fn (UserRole $userRole) => $userRole->role?->name)
            ->filter()
            ->values();
    }

    /**
     * Grant a role. Returns false when the user already carries it or a guard blocks it.
     */
    public function grant(User $user, UserRoleEnum $role): bool
    {
        if ($this->grantBlockedReason($user, $role)) {
            return false;
        }

        if (! $this->activate($user, $role)) {
            return false;
        }

        app(ActivityLogService::class)->logActivity(
            ActivityActionEnum::USER_ROLE_GRANT,
            "Granted the {$role->label()} role to {$user->name}.",
            model: $user,
            prefixDescription: false,
        );

        return true;
    }

    /**
     * Revoke a role. Returns false when the user does not carry it or a guard blocks it.
     *
     * The assignment is deactivated rather than deleted, so the history of who once held
     * what survives and granting the role back reuses the same row.
     */
    public function revoke(User $user, UserRoleEnum $role): bool
    {
        if ($this->revokeBlockedReason($user, $role)) {
            return false;
        }

        if (! $this->deactivate($user, $role)) {
            return false;
        }

        app(ActivityLogService::class)->logActivity(
            ActivityActionEnum::USER_ROLE_REVOKE,
            "Removed the {$role->label()} role from {$user->name}.",
            model: $user,
            prefixDescription: false,
        );

        return true;
    }

    /**
     * Move an account across an exclusivity boundary in one step.
     *
     * A plain grant cannot do this: the two sides are exclusive, and dropping the current
     * role first would momentarily strand the account. So the swap happens together.
     */
    public function switchTo(User $user, UserRoleEnum $target): bool
    {
        if ($this->switchBlockedReason($user, $target)) {
            return false;
        }

        $dropped = $this->conflictingRoles($user, $target);

        DB::transaction(function () use ($user, $target, $dropped): void {
            foreach ($dropped as $role) {
                $this->deactivate($user, $role);
            }

            $this->activate($user, $target);
        });

        $from = collect($dropped)->map(fn (UserRoleEnum $role) => $role->label())->implode(' and ');

        app(ActivityLogService::class)->logActivity(
            ActivityActionEnum::USER_ROLE_SWITCH,
            "Switched {$user->name} from {$from} to {$target->label()}.",
            model: $user,
            prefixDescription: false,
        );

        return true;
    }

    /**
     * Why this account cannot be moved across right now, or null when it can.
     */
    public function switchBlockedReason(User $user, UserRoleEnum $target): ?string
    {
        if ($this->rolesFor($user)->contains($target)) {
            return "This account already holds the {$target->label()} role.";
        }

        // Every role being given up still has to answer for its own commitments.
        foreach ($this->conflictingRoles($user, $target) as $role) {
            if ($reason = $this->commitmentReason($user, $role)) {
                return $reason;
            }
        }

        return null;
    }

    /**
     * The roles that have to go before this account can hold the target role.
     *
     * @return array<int, UserRoleEnum>
     */
    public function conflictingRoles(User $user, UserRoleEnum $target): array
    {
        $held = $this->rolesFor($user);

        return collect(self::EXCLUSIVE_GROUPS)
            ->filter(fn (array $group) => \in_array($target, $group, true))
            ->flatten()
            ->filter(fn (UserRoleEnum $role) => $role !== $target && $held->contains($role))
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Why this role cannot be given right now, or null when it can.
     */
    public function grantBlockedReason(User $user, UserRoleEnum $role): ?string
    {
        if ($this->conflictingRoles($user, $role) !== []) {
            return "This account holds a role that cannot sit alongside {$role->label()}. Switch the account over instead.";
        }

        return null;
    }

    /**
     * Why this role cannot be taken away right now, or null when it can.
     */
    public function revokeBlockedReason(User $user, UserRoleEnum $role): ?string
    {
        $held = $this->rolesFor($user);

        // Stripping the only role would leave the account unable to reach any workspace.
        if ($held->contains($role) && $held->count() <= 1) {
            return 'This is the only role on the account. Grant another role first, otherwise it would be left with no access.';
        }

        return $this->commitmentReason($user, $role);
    }

    /**
     * What this role is still on the hook for, independent of how many roles are held.
     *
     * This is the hook every project fills in: a role that owns live work — open
     * orders, unfinished courses, assigned tickets — returns the sentence explaining
     * why it cannot be dropped yet.
     */
    private function commitmentReason(User $user, UserRoleEnum $role): ?string
    {
        return match ($role) {
            UserRoleEnum::ADMIN => $this->adminRevokeReason($user),
            UserRoleEnum::USER => null,
        };
    }

    /**
     * Turn an assignment on, creating the row the first time. Returns false when it
     * was already active.
     */
    private function activate(User $user, UserRoleEnum $role): bool
    {
        $record = UserRole::query()->firstOrNew([
            'user_id' => $user->id,
            'role_id' => $this->role($role)->id,
        ]);

        if ($record->exists && $record->status->isActive()) {
            return false;
        }

        $record->status = StatusDefault::ACTIVE;
        $record->save();

        return true;
    }

    /**
     * Turn an assignment off without deleting it. Returns false when it was not active.
     */
    private function deactivate(User $user, UserRoleEnum $role): bool
    {
        $roleIds = Role::query()->where('name', $role)->pluck('id');

        return (bool) UserRole::query()
            ->where('user_id', $user->id)
            ->whereIn('role_id', $roleIds)
            ->where('status', StatusDefault::ACTIVE)
            ->update(['status' => StatusDefault::INACTIVE]);
    }

    public function adminCount(): int
    {
        return User::query()
            ->whereHas('userRoles', fn ($query) => $query
                ->isActive()
                ->whereHas('role', fn ($role) => $role->isAdmin()))
            ->count();
    }

    private function adminRevokeReason(User $user): ?string
    {
        if ($user->id === auth()->id()) {
            return 'You cannot remove your own admin role.';
        }

        if ($this->adminCount() <= 1) {
            return 'This is the last admin account, so its admin role cannot be removed.';
        }

        return null;
    }
}
