<?php

namespace App\Services;

use App\Enums\ActivityActionEnum;
use App\Enums\GateAccessEnum;
use App\Enums\StatusDefault;
use App\Models\Role;
use App\Models\User;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Support\Collection;

/**
 * Layer 2 of the authorization stack: which admin pages an account may open, and how
 * far it may go once inside one.
 *
 * A gate is a navigation key — 'content', or 'users.roles' for a child — mapped to a
 * GateAccessEnum. Roles hold the map everybody on that role starts from; an individual
 * administrator may override single keys on top. Nothing here is a Laravel Gate: see
 * policies.md.
 *
 * An admin carries *any number* of roles. Their maps merge with the highest access
 * winning each key, so a role only ever widens what somebody reaches — which is what
 * makes "Media as well as Support" a thing an administrator can express without
 * inventing a third role that is the sum of the two. The personal override sits on
 * top of the merged result and is the only thing that can narrow it.
 *
 * Only an admin is gated. A member has no roles at all, and the member workspace has
 * no gate keys, so asking any of this about one answers NONE and means nothing.
 */
#[Singleton]
class GateService
{
    /**
     * Navigation keys that are never gated.
     *
     * The dashboard is the workspace's front door and the profile is where a denied
     * page redirects to — gate either and a refused administrator has nowhere to
     * land, or bounces between two redirects.
     */
    public const EXEMPT = ['dashboard', 'profile'];

    /**
     * The gate that hands out gates.
     *
     * Lowering it everywhere would leave an install nobody can administer, so every
     * write checks that at least one live admin still holds FULL over it.
     */
    public const ADMINISTRATION = 'users';

    private const LOCKOUT_REASON = 'This would leave nobody with full access to Users, so no access could ever be granted again. Give another role or administrator full access to Users first.';

    /**
     * Resolved gate maps, keyed by user id.
     *
     * The service is a singleton and the sidebar asks about a dozen gates per render,
     * so an account's map is merged once per request rather than once per question.
     *
     * @var array<int, array<string, string>>
     */
    private array $maps = [];

    // |||||||||||||||||||||||||||||||||||||||||||||||||||||||||||
    // PRIVATE

    /**
     * The gate map this account actually resolves to, merged once per request.
     */
    private function resolvedMapFor(User $user): array
    {
        return $this->maps[$user->id] ??= $this->mapFor($user, $user->roles()->get());
    }

    /**
     * Read one resource out of an already-merged gate map.
     *
     * The map is flat with dotted keys, so this indexes it directly — data_get()
     * would read 'users.roles' as a walk into a nested array and find nothing.
     *
     * A child with no gate of its own inherits its parent's, which is what makes
     * "give this role everything under Content" a single entry rather than five.
     */
    private function lookup(array $map, string $resource): GateAccessEnum
    {
        $own = GateAccessEnum::tryFrom((string) ($map[$resource] ?? ''));

        if (! str_contains($resource, '.')) {
            return $own ?? GateAccessEnum::NONE;
        }

        $parent = GateAccessEnum::tryFrom((string) ($map[str($resource)->before('.')->toString()] ?? ''));

        // An explicit denial on the parent shuts the branch whatever the child says.
        // The sidebar drops a denied branch whole, so a child left reachable by URL
        // would put the page and the menu at odds about the same gate.
        //
        // A parent that is merely *absent* does not do this. A branch is otherwise
        // decided by its children — grant one child and the branch opens for it —
        // which is the same rule kNavigationStrictAction() renders.
        if ($parent?->isNone()) {
            return GateAccessEnum::NONE;
        }

        return $own ?? $parent ?? GateAccessEnum::NONE;
    }

    /**
     * The map one account resolves to: every live role they carry merged together,
     * with their own overrides laid over the result, key by key.
     *
     * An account with no live role resolves to nothing at all — the override included.
     * An override is a change to what the roles grant, so with nothing underneath it,
     * there is nothing for it to be a change to. That is what makes switching a role
     * off actually revoke access rather than leave the overrides standing.
     *
     * The roles are passed in rather than read off the account so that a *candidate*
     * set can be tested before it is written.
     *
     * @param  Collection<int, Role>|iterable<Role>  $roles
     */
    private function mapFor(User $user, iterable $roles): array
    {
        if (! $user->user_type->carriesRole()) {
            return [];
        }

        $live = collect($roles)->filter(fn (Role $role) => $role->grantsAccess());

        if ($live->isEmpty()) {
            return [];
        }

        return [
            ...$this->mergeRoleMaps($live),
            ...$user->gatesArray(),
        ];
    }

    /**
     * Fold several role maps into one, the highest access winning each key.
     *
     * Additive on purpose: a role is a grant, so holding a second one can only widen
     * what an account reaches. Were the merge to take the lowest, adding a narrow role
     * to a broad one would quietly revoke access nobody asked to revoke, and an
     * administrator handing somebody "Media as well" would be taking access away.
     *
     * NONE is the floor rather than a veto here: a key one role denies and another
     * grants resolves to the grant. Denying one administrator specifically is what the
     * personal override is for, and it still wins — it is laid over this result.
     *
     * @param  Collection<int, Role>  $roles
     */
    private function mergeRoleMaps(Collection $roles): array
    {
        $merged = [];

        foreach ($roles as $role) {
            foreach ($role->gatesArray() as $key => $value) {
                $level = GateAccessEnum::tryFrom((string) $value);

                if (! $level instanceof GateAccessEnum) {
                    continue;
                }

                $held = GateAccessEnum::tryFrom((string) ($merged[$key] ?? ''));

                if ($held === null || $level->rank() > $held->rank()) {
                    $merged[$key] = $level->value;
                }
            }
        }

        return $merged;
    }

    /**
     * One account's role set with a single role swapped for a candidate version of
     * itself — the shape every "would this change lock us out?" guard tests against.
     *
     * A role the account does not hold is not added. The question is what *this*
     * account resolves to after the edit, and editing a role nobody has put them on
     * does not put them on it.
     *
     * @param  Collection<int, Role>|iterable<Role>  $roles
     * @return Collection<int, Role>
     */
    private function withCandidateRole(iterable $roles, Role $candidate): Collection
    {
        return collect($roles)
            ->map(fn (Role $role) => $role->is($candidate) ? $candidate : $role)
            ->values();
    }

    /**
     * Walk every admin in the system and ask whether at least one still holds full
     * access to user management.
     *
     * `$substitute` is handed each account and the roles it carries, and returns the
     * map to test — which is how a change is checked *before* it is written.
     *
     * @param  callable(User, Collection<int, Role>): array  $substitute
     */
    private function administrationSurvives(callable $substitute): bool
    {
        $admins = User::query()
            ->admins()
            ->with('roles')
            ->get();

        foreach ($admins as $admin) {
            if ($this->lookup($substitute($admin, $admin->roles), self::ADMINISTRATION)->isFull()) {
                return true;
            }
        }

        return false;
    }

    // |||||||||||||||||||||||||||||||||||||||||||||||||||||||||||
    // PUBLIC

    /**
     * Every gateable area of the admin workspace, read off the navigation tree so a
     * new screen becomes gateable by being added to the sidebar and nowhere else.
     *
     * @return array<string, array{label: string, children: array<string, string>}>
     */
    public function resources(): array
    {
        $output = [];

        foreach (kPageNavigationLinks('admin', strict: false) as $key => $item) {
            if (\in_array($key, self::EXEMPT, true)) {
                continue;
            }

            $children = [];

            foreach (data_get($item, 'children', []) as $childKey => $child) {
                $children["{$key}.{$childKey}"] = data_get($child, 'label') ?: kBreakText($childKey);
            }

            $output[$key] = [
                'label' => data_get($item, 'label') ?: kBreakText($key),
                'children' => $children,
            ];
        }

        return $output;
    }

    /**
     * Every gate key, parents and children together. The validation whitelist.
     *
     * @return array<int, string>
     */
    public function keys(): array
    {
        $keys = [];

        foreach ($this->resources() as $key => $resource) {
            $keys[] = $key;
            $keys = [...$keys, ...array_keys($resource['children'])];
        }

        return $keys;
    }

    /**
     * Strip a submitted map down to what may actually be stored: known keys and real
     * enum values.
     *
     * NONE is dropped by default, because on a role an absent key already means none
     * and keeping both spellings would make two maps that mean the same thing.
     *
     * On an administrator's override it is the opposite — absent means "inherit the
     * role" and NONE means "deny this even though the role allows it", which are
     * different instructions. That is what $keepDenials is for.
     */
    public function normalize(array $gates, bool $keepDenials = false): array
    {
        $known = $this->keys();

        return collect($gates)
            ->only($known)
            ->map(fn ($value) => GateAccessEnum::tryFrom((string) $value))
            ->filter(fn (?GateAccessEnum $level) => $level instanceof GateAccessEnum
                && ($keepDenials || ! $level->isNone()))
            ->map(fn (GateAccessEnum $level) => $level->value)
            ->all();
    }

    /**
     * A map granting full access to everything. What the first admin role is seeded
     * with, and what the "grant everything" button in the editor writes.
     */
    public function fullAccessMap(): array
    {
        return collect($this->keys())
            ->mapWithKeys(fn (string $key) => [$key => GateAccessEnum::FULL->value])
            ->all();
    }

    /**
     * How far this account may go inside one area.
     *
     * One role, so one map: an account is what its role says, adjusted by whatever was
     * overridden on the account itself. The exempt keys answer FULL for everybody, so
     * a refused administrator always has somewhere to land.
     */
    public function accessFor(User $user, string $resource): GateAccessEnum
    {
        if (\in_array(str($resource)->before('.')->toString(), self::EXEMPT, true)) {
            return GateAccessEnum::FULL;
        }

        return $this->lookup($this->resolvedMapFor($user), $resource);
    }

    /**
     * How far one role grants over an area, ignoring any administrator's override.
     *
     * What the gate editor shows beside a blank row as "following the role", and what
     * Role::gateFor() delegates to. It runs the same lookup() as accessFor(), so
     * child-inherits-parent and the explicit-NONE cascade hold here too — a hint that
     * disagreed with the resolved access would be worse than no hint.
     */
    public function roleAccessFor(Role $role, string $resource): GateAccessEnum
    {
        if (\in_array(str($resource)->before('.')->toString(), self::EXEMPT, true)) {
            return GateAccessEnum::FULL;
        }

        return $this->lookup($role->gatesArray(), $resource);
    }

    /**
     * What this account's roles grant over an area *before* their own override is laid
     * on top.
     *
     * What the gate editor shows beside a blank row as "following the roles". It is
     * the merged map of every live role they carry, read through the same lookup() as
     * accessFor(), so a hint that disagreed with the resolved access is not possible.
     */
    public function inheritedAccessFor(User $user, string $resource): GateAccessEnum
    {
        if (\in_array(str($resource)->before('.')->toString(), self::EXEMPT, true)) {
            return GateAccessEnum::FULL;
        }

        if (! $user->user_type->carriesRole()) {
            return GateAccessEnum::NONE;
        }

        return $this->lookup($this->mergeRoleMaps($user->liveRoles()), $resource);
    }

    /**
     * Does this account reach the level being asked for? The question every screen
     * asks, and the one kGate() wraps.
     */
    public function allows(User $user, string $resource, GateAccessEnum $required = GateAccessEnum::VIEW): bool
    {
        return $this->accessFor($user, $resource)->covers($required);
    }

    /**
     * The whole resolved map for one account, for the sidebar and the gate summary
     * on an administrator's own page.
     *
     * @return array<string, GateAccessEnum>
     */
    public function mapForUser(User $user): array
    {
        return collect($this->keys())
            ->mapWithKeys(fn (string $key) => [$key => $this->accessFor($user, $key)])
            ->all();
    }

    /**
     * Why this role's gates cannot be changed to $gates right now, or null when they can.
     */
    public function roleGatesBlockedReason(Role $role, array $gates): ?string
    {
        $candidate = (clone $role)->fill(['gates' => $gates]);

        $survives = $this->administrationSurvives(
            fn (User $admin, Collection $assigned) => $this->mapFor(
                $admin,
                $this->withCandidateRole($assigned, $candidate),
            )
        );

        return $survives ? null : self::LOCKOUT_REASON;
    }

    /**
     * Why this administrator's override cannot be changed right now, or null when it can.
     */
    public function adminGatesBlockedReason(User $user, ?array $gates): ?string
    {
        $candidate = (clone $user)->fill(['gates' => $gates]);

        $survives = $this->administrationSurvives(
            fn (User $admin, Collection $assigned) => $this->mapFor(
                $admin->is($user) ? $candidate : $admin,
                $assigned,
            )
        );

        return $survives ? null : self::LOCKOUT_REASON;
    }

    /**
     * Why this account cannot be put on exactly that set of roles — the empty set
     * included, which is how every role is taken away — or null when it can.
     *
     * The set is absolute, not a list of additions: what is passed is what the account
     * would end up holding, so a role dropped from it is a role revoked.
     *
     * @param  Collection<int, Role>|iterable<Role>  $roles
     */
    public function assignmentBlockedReason(User $user, iterable $roles): ?string
    {
        $survives = $this->administrationSurvives(
            fn (User $admin, Collection $assigned) => $admin->is($user)
                ? $this->mapFor($user, $roles)
                : $this->mapFor($admin, $assigned)
        );

        return $survives ? null : self::LOCKOUT_REASON;
    }

    /**
     * Why this role cannot be switched off right now, or null when it can.
     *
     * Deactivating takes the role's whole map away from everybody on it, so it can
     * empty user management just as surely as editing that map key by key would.
     * Somebody holding a second role that still grants Users is not locked out by it,
     * which is exactly what the merge has to be asked rather than assumed.
     */
    public function roleDeactivationBlockedReason(Role $role): ?string
    {
        $candidate = (clone $role)->fill(['status' => StatusDefault::INACTIVE]);

        $survives = $this->administrationSurvives(
            fn (User $admin, Collection $assigned) => $this->mapFor(
                $admin,
                $this->withCandidateRole($assigned, $candidate),
            )
        );

        return $survives ? null : self::LOCKOUT_REASON;
    }

    /**
     * Write a role's gate map. Returns false when the submitted map changes nothing.
     */
    public function updateRoleGates(Role $role, array $gates): bool
    {
        $role->gates = $this->normalize($gates);

        if ($role->isClean()) {
            return false;
        }

        $serviceInstance = app(ActivityLogService::class);
        $affectedColumns = $serviceInstance->affectedColumns($role);

        $role->save();

        $serviceInstance->logActivity(
            ActivityActionEnum::ROLE_GATES_UPDATE,
            " the gates on the {$role->name} role.",
            $affectedColumns,
            model: $role,
        );

        $this->flush();

        return true;
    }

    /**
     * Write one administrator's override. Null clears it, putting the account back on
     * whatever its role grants — which is not the same as an empty array, and the
     * caller is expected to know the difference.
     */
    public function updateAdminGates(User $user, ?array $gates): bool
    {
        $user->gates = $gates === null ? null : $this->normalize($gates, keepDenials: true);

        if ($user->isClean()) {
            return false;
        }

        $serviceInstance = app(ActivityLogService::class);
        $affectedColumns = $serviceInstance->affectedColumns($user);

        $user->save();

        $serviceInstance->logActivity(
            $gates === null ? ActivityActionEnum::ADMIN_GATES_RESET : ActivityActionEnum::ADMIN_GATES_UPDATE,
            " the gates for {$user->name}.",
            $affectedColumns,
            model: $user,
        );

        $this->flush();
        $this->syncAuthenticated($user);

        return true;
    }

    /**
     * Drop the per-request map cache. Called after any write, so the screen that just
     * changed a gate renders against the new map rather than the old one.
     */
    public function flush(): void
    {
        $this->maps = [];
    }

    /**
     * Reload the signed-in account when it is the one that just changed.
     *
     * The guard hands out a single User instance for the whole request, and that copy
     * still carries the override — or the roles — as they were when the request began.
     * Flushing the cache alone is not enough: the next kGate() would resolve against
     * that stale copy and cache the very map the write replaced, so an administrator
     * editing their own access would see the change only after a reload.
     *
     * Every writer calls this straight after its flush().
     */
    public function syncAuthenticated(User $user): void
    {
        $current = auth()->user();

        if ($current instanceof User && $current->is($user) && $current !== $user) {
            $current->refresh();
        }

        $this->flush();
    }
}
