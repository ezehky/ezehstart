<?php

namespace App\Services;

use App\Enums\ActivityActionEnum;
use App\Enums\GateAccessEnum;
use App\Enums\StatusDefault;
use App\Models\Role;
use App\Models\User;
use Illuminate\Container\Attributes\Singleton;

/**
 * Layer 2 of the authorization stack: which admin pages an account may open, and how
 * far it may go once inside one.
 *
 * A gate is a navigation key — 'content', or 'users.roles' for a child — mapped to a
 * GateAccessEnum. Roles hold the map everybody on that role starts from; an individual
 * administrator may override single keys on top. Nothing here is a Laravel Gate: see
 * policies.md.
 *
 * Only an admin is gated. A member has no role at all, and the member workspace has no
 * gate keys, so asking any of this about one answers NONE and means nothing.
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
        return $this->maps[$user->id] ??= $this->mapFor($user, $user->role()->first());
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
     * The map one account resolves to: their role's map with their own overrides laid
     * over it, key by key.
     *
     * An account with no live role resolves to nothing at all — the override included.
     * An override is a change to what a role grants, so with no role underneath it,
     * there is nothing for it to be a change to. That is what makes switching a role
     * off actually revoke access rather than leave the overrides standing.
     *
     * The role is passed in rather than read off the user so that a *candidate* map
     * can be tested before it is written.
     */
    private function mapFor(User $user, ?Role $role): array
    {
        if (! $user->user_type->carriesRole() || ! $role?->grantsAccess()) {
            return [];
        }

        return [
            ...$role->gatesArray(),
            ...$user->gatesArray(),
        ];
    }

    /**
     * Walk every admin in the system and ask whether at least one still holds full
     * access to user management.
     *
     * `$substitute` is handed each account and the role it is on, and returns the map
     * to test — which is how a change is checked *before* it is written.
     *
     * @param  callable(User, ?Role): array  $substitute
     */
    private function administrationSurvives(callable $substitute): bool
    {
        $admins = User::query()
            ->admins()
            ->with('role')
            ->get();

        foreach ($admins as $admin) {
            if ($this->lookup($substitute($admin, $admin->role), self::ADMINISTRATION)->isFull()) {
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
            fn (User $admin, ?Role $assigned) => $this->mapFor(
                $admin,
                $assigned?->is($role) ? $candidate : $assigned,
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
            fn (User $admin, ?Role $assigned) => $this->mapFor(
                $admin->is($user) ? $candidate : $admin,
                $assigned,
            )
        );

        return $survives ? null : self::LOCKOUT_REASON;
    }

    /**
     * Why this account cannot be moved onto that role — or off every role, when it is
     * null — or null when it can.
     */
    public function assignmentBlockedReason(User $user, ?Role $role): ?string
    {
        $candidate = (clone $user)->fill(['role_id' => $role?->id]);

        $survives = $this->administrationSurvives(
            fn (User $admin, ?Role $assigned) => $admin->is($user)
                ? $this->mapFor($candidate, $role)
                : $this->mapFor($admin, $assigned)
        );

        return $survives ? null : self::LOCKOUT_REASON;
    }

    /**
     * Why this role cannot be switched off right now, or null when it can.
     *
     * Deactivating takes the role's whole map away from everybody on it, so it can
     * empty user management just as surely as editing that map key by key would.
     */
    public function roleDeactivationBlockedReason(Role $role): ?string
    {
        $candidate = (clone $role)->fill(['status' => StatusDefault::INACTIVE]);

        $survives = $this->administrationSurvives(
            fn (User $admin, ?Role $assigned) => $this->mapFor(
                $admin,
                $assigned?->is($role) ? $candidate : $assigned,
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
}
