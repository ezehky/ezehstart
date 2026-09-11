<?php

namespace App\Services;

use App\Enums\ActivityActionEnum;
use App\Enums\StatusDefault;
use App\Enums\UserTypeEnum;
use App\Models\Role;
use App\Models\User;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Support\Str;

/**
 * Roles, and which admin carries which one.
 *
 * A role is a row an administrator creates — "Media", "Support", "Finance" — not an
 * enum case. What is fixed in code is the *type* of an account (UserTypeEnum), because
 * a type is a workspace and a workspace is three files. A role only ever divides up
 * the admin workspace, so only an admin has one.
 *
 * Every guard in here answers in a sentence rather than a boolean, so the screen can
 * say why instead of failing quietly. Pair each one with its doer: ask the *Reason
 * method first, then call the action.
 */
#[Singleton]
class RoleService
{
    /**
     * The role the install cannot be left without.
     *
     * Created with every gate, marked protected, and refused to anybody trying to
     * delete or deactivate it. Without one there is a reachable state where nobody
     * can administer anything and no screen exists from which to fix that.
     */
    public const PROTECTED_SLUG = 'administrator';

    /**
     * The roles a fresh install ships beyond the protected one — starting points an
     * administrator is expected to rename, re-gate or delete.
     *
     * @var array<string, string>
     */
    public const STARTER_ROLES = [
        // 'Executive' => 'Sees the whole workspace but is not expected to configure it.',
        // 'Media' => 'Runs the blog, the image library and the video library.',
        // 'Support' => 'Answers to member accounts and reads the transaction ledger.',
    ];

    // |||||||||||||||||||||||||||||||||||||||||||||||||||||||||||
    // ROLES

    /**
     * The protected role, created with full access the first time it is asked for.
     *
     * Gates default to none, so a protected role granting nothing would come up with
     * an empty sidebar and no screen left to grant anything from. A role that already
     * exists keeps the gates it has — resolving it must never hand back access
     * somebody deliberately took away.
     */
    public function protectedRole(): Role
    {
        $record = Role::query()->firstOrNew(['slug' => self::PROTECTED_SLUG]);

        if (! $record->exists) {
            $record->name = 'Administrator';
            $record->description = 'Full access to the administration workspace.';
            $record->gates = app(GateService::class)->fullAccessMap();
            $record->status = StatusDefault::ACTIVE;
        }

        // Re-asserted rather than only set on create: an install that lost this flag
        // — a hand-edited row, a bad import — is one delete away from being locked out.
        $record->is_protected = true;
        $record->save();

        return $record;
    }

    /**
     * Create a role. New roles start closed: gates are granted afterwards from the
     * access editor, which is the one place the lockout guard sees the whole picture.
     */
    public function create(string $name, ?string $description = null): Role
    {
        $role = Role::query()->create([
            'name' => trim($name),
            'slug' => $this->uniqueSlug($name),
            'description' => $description ? trim($description) : null,
            'gates' => [],
            'status' => StatusDefault::ACTIVE,
            'is_protected' => false,
        ]);

        app(ActivityLogService::class)->logActivity(
            ActivityActionEnum::ROLE_CREATE,
            "role: {$role->name}",
            model: $role,
        );

        return $role;
    }

    /**
     * Rename a role or switch it off. Returns false when nothing actually changed.
     *
     * The slug is left alone: it is the handle a seeder or a test looks the role up
     * by, and rewording the label must not break them.
     */
    public function update(Role $role, string $name, ?string $description, bool $active): bool
    {
        $role->name = trim($name);
        $role->description = $description ? trim($description) : null;
        $role->status = StatusDefault::tryFrom((int) $active);

        if ($role->isClean()) {
            return false;
        }

        $logService = app(ActivityLogService::class);
        $affectedColumns = $logService->affectedColumns($role);

        $role->save();

        $logService->logActivity(
            ActivityActionEnum::ROLE_UPDATE,
            "role: {$role->name}",
            $affectedColumns,
            model: $role,
        );

        app(GateService::class)->flush();

        return true;
    }

    /**
     * Why this role cannot be edited into that shape, or null when it can.
     */
    public function updateBlockedReason(Role $role, bool $active): ?string
    {
        if ($role->is_protected && ! $active) {
            return 'The protected role cannot be switched off. It is what guarantees somebody can still administer this install.';
        }

        // Switching a role off takes its gates away from everybody on it, which can
        // empty user management just as surely as editing the map would.
        if (! $active && $role->status->isActive()) {
            return app(GateService::class)->roleDeactivationBlockedReason($role);
        }

        return null;
    }

    /**
     * Why this role cannot be deleted right now, or null when it can.
     */
    public function deleteBlockedReason(Role $role): ?string
    {
        if ($role->is_protected) {
            return 'The protected role cannot be deleted. It is what guarantees somebody can still administer this install.';
        }

        if ($count = $role->users()->count()) {
            return $count === 1
                ? 'One account still holds this role. Move it to another role first.'
                : "{$count} accounts still hold this role. Move them to another role first.";
        }

        return null;
    }

    public function delete(Role $role): bool
    {
        if ($this->deleteBlockedReason($role)) {
            return false;
        }

        // Logged before the row goes, so the entry can still name what it was about.
        app(ActivityLogService::class)->logActivity(
            ActivityActionEnum::ROLE_DELETE,
            "role: {$role->name}",
            model: $role,
        );

        $role->delete();

        app(GateService::class)->flush();

        return true;
    }

    /**
     * A slug nothing else is using. Two roles may share a display name — retiring
     * "Media" and starting a fresh one is a normal thing to do — so the handle is
     * what gets the suffix.
     */
    public function uniqueSlug(string $name, ?int $ignoreId = null): string
    {
        $base = Str::slug($name) ?: 'role';
        $slug = $base;
        $suffix = 2;

        while (Role::query()
            ->where('slug', $slug)
            ->when($ignoreId, fn ($query) => $query->whereKeyNot($ignoreId))
            ->exists()
        ) {
            $slug = "{$base}-{$suffix}";
            $suffix++;
        }

        return $slug;
    }

    // |||||||||||||||||||||||||||||||||||||||||||||||||||||||||||
    // ASSIGNMENT

    /**
     * Put an admin on a role. Returns false when they are already on it or a guard
     * blocks it.
     *
     * Clearing the role — passing null — is allowed and leaves an admin who can sign
     * in but reaches nothing. That is a holding state, not a broken one, and the
     * admins listing calls it out.
     */
    public function assign(User $user, ?Role $role): bool
    {
        if ($this->assignBlockedReason($user, $role)) {
            return false;
        }

        if ($user->role_id === $role?->id) {
            return false;
        }

        $user->role_id = $role?->id;
        $user->save();

        app(ActivityLogService::class)->logActivity(
            $role ? ActivityActionEnum::USER_ROLE_ASSIGN : ActivityActionEnum::USER_ROLE_CLEAR,
            $role
                ? "Put {$user->name} on the {$role->name} role."
                : "Took the role away from {$user->name}.",
            model: $user,
            prefixDescription: false,
        );

        app(GateService::class)->flush();

        return true;
    }

    /**
     * Why this account cannot be moved to that role, or null when it can.
     */
    public function assignBlockedReason(User $user, ?Role $role): ?string
    {
        if (! $user->user_type->carriesRole()) {
            return 'Only admin accounts carry a role. Change the account type first.';
        }

        if ($role && ! $role->status->isActive()) {
            return "The {$role->name} role is switched off, so putting an account on it would grant nothing.";
        }

        return app(GateService::class)->assignmentBlockedReason($user, $role);
    }

    // |||||||||||||||||||||||||||||||||||||||||||||||||||||||||||
    // ACCOUNT TYPE

    /**
     * Move an account between workspaces.
     *
     * A type change is not a role change: it decides which dashboard the account
     * signs in to. Coming *out* of admin clears the role, because a member holding
     * one would be carrying access to a workspace they can no longer open.
     */
    public function changeType(User $user, UserTypeEnum $type, ?Role $role = null): bool
    {
        if ($this->typeChangeBlockedReason($user, $type)) {
            return false;
        }

        $from = $user->user_type;

        $user->user_type = $type;
        $user->role_id = $type->carriesRole() ? $role?->id : null;

        if ($user->isClean()) {
            return false;
        }

        $user->save();

        app(ActivityLogService::class)->logActivity(
            ActivityActionEnum::USER_TYPE_CHANGE,
            "Moved {$user->name} from {$from->label()} to {$type->label()}.",
            model: $user,
            prefixDescription: false,
        );

        app(GateService::class)->flush();

        return true;
    }

    /**
     * Why this account cannot become that type right now, or null when it can.
     */
    public function typeChangeBlockedReason(User $user, UserTypeEnum $type): ?string
    {
        if ($user->user_type === $type) {
            return "This account is already {$type->label(lowercase: true)}.";
        }

        // Leaving the admin workspace is the only direction that can lock anybody out.
        if (! $user->user_type->isAdmin()) {
            return null;
        }

        if ($user->id === auth()->id()) {
            return 'You cannot take your own admin access away.';
        }

        if ($this->adminCount() <= 1) {
            return 'This is the last admin account, so it cannot be moved out of the admin workspace.';
        }

        return app(GateService::class)->assignmentBlockedReason($user, null);
    }

    public function adminCount(): int
    {
        return User::query()->admins()->count();
    }
}
