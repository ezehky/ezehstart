<?php

namespace App\Services;

use App\Enums\ActivityActionEnum;
use App\Enums\GateAccessEnum;
use App\Enums\StatusDefault;
use App\Enums\StatusYes;
use App\Enums\UserTypeEnum;
use App\Models\Role;
use App\Models\User;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Roles, and which admin carries which one.
 *
 * A role is a row an administrator creates — "Media", "Support", "Finance" — not an
 * enum case. What is fixed in code is the *type* of an account (UserTypeEnum), because
 * a type is a workspace and a workspace is three files. A role only ever divides up
 * the admin workspace, so only an admin has any.
 *
 * An admin carries *any number* of them and the maps merge, highest access winning —
 * see GateService. That is why assignment is a set operation here rather than a
 * column write: what gets passed is what the account ends up holding, so a role left
 * out of the set is a role revoked.
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

    /**
     * The role that makes somebody an author.
     *
     * Held by slug rather than by name so the label can be reworded without breaking
     * the byline: an author's public bio and social links hang off this, and
     * BlogService narrows an author to their own posts unless something else on their
     * account grants full access to the blog.
     */
    public const AUTHOR_SLUG = 'author';

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
        $record->is_protected = StatusYes::YES;
        $record->save();

        return $record;
    }

    /**
     * The author role, created on first use with view access to the blog.
     *
     * Unlike the protected role this one is ordinary — it can be renamed, re-gated or
     * deleted. What is fixed is the slug, because that is what the blog reads to
     * decide whose byline gets a bio and which posts an account may edit.
     *
     * It starts at CREATE on content.blogs rather than closed: a role called Author
     * that cannot write a post is a puzzle rather than a starting point. Everything
     * else stays shut.
     */
    public function authorRole(): Role
    {
        $record = Role::query()->firstOrNew(['slug' => self::AUTHOR_SLUG]);

        if (! $record->exists) {
            $record->name = 'Author';
            $record->description = 'Writes and edits their own blog posts.';
            $record->gates = [
                'content.blogs' => GateAccessEnum::CREATE->value,
                'content.image-library' => GateAccessEnum::CREATE->value,
            ];
            $record->status = StatusDefault::ACTIVE;
            $record->is_protected = StatusYes::NO;
            $record->save();
        }

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
            'is_protected' => StatusYes::NO,
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
                ? 'One account still holds this role. Take it off that account first.'
                : "{$count} accounts still hold this role. Take it off those accounts first.";
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
     * Put an admin on exactly this set of roles. Returns false when the set is already
     * what they hold, or when a guard blocks it.
     *
     * The set is absolute, not a list of additions: a role the account holds and the
     * set does not is revoked by the same call. Passing an empty set is allowed and
     * leaves an admin who can sign in but reaches nothing — a holding state, not a
     * broken one, and the admins listing calls it out.
     *
     * @param  iterable<Role>  $roles
     */
    public function syncRoles(User $user, iterable $roles): bool
    {
        $roles = $this->uniqueRoles($roles);

        if ($this->assignBlockedReason($user, $roles)) {
            return false;
        }

        $ids = $roles->pluck('id')->sort()->values();

        if ($user->roles->pluck('id')->sort()->values()->all() === $ids->all()) {
            return false;
        }

        $user->roles()->sync($ids->all());
        $user->unsetRelation('roles');

        app(ActivityLogService::class)->logActivity(
            $roles->isNotEmpty() ? ActivityActionEnum::USER_ROLE_ASSIGN : ActivityActionEnum::USER_ROLE_CLEAR,
            $roles->isNotEmpty()
                ? "Put {$user->name} on ".$this->roleSentence($roles).'.'
                : "Took every role away from {$user->name}.",
            model: $user,
            prefixDescription: false,
        );

        app(GateService::class)->syncAuthenticated($user);

        return true;
    }

    /**
     * Why this account cannot be put on that set of roles, or null when it can.
     *
     * @param  iterable<Role>  $roles
     */
    public function assignBlockedReason(User $user, iterable $roles): ?string
    {
        $roles = $this->uniqueRoles($roles);

        if (! $user->user_type->carriesRole()) {
            return $roles->isEmpty()
                ? null
                : 'Only admin accounts carry roles. Change the account type first.';
        }

        if ($offline = $roles->reject(fn (Role $role) => $role->status->isActive())->first()) {
            return "The {$offline->name} role is switched off, so putting an account on it would grant nothing.";
        }

        return app(GateService::class)->assignmentBlockedReason($user, $roles);
    }

    /**
     * "the Media role", or "the Media and Support roles" — for a log line that reads
     * as a sentence rather than as a list of ids.
     *
     * @param  Collection<int, Role>  $roles
     */
    private function roleSentence(Collection $roles): string
    {
        $names = $roles->pluck('name')->all();

        return 'the '.implode(' and ', array_filter([
            implode(', ', \array_slice($names, 0, -1)),
            end($names),
        ])).' '.kPluralize('role', \count($names), prepend: false);
    }

    /**
     * One row per role, however the caller assembled the set. A form that posts the
     * same id twice is a double submission, not two grants.
     *
     * @param  iterable<Role>  $roles
     * @return Collection<int, Role>
     */
    private function uniqueRoles(iterable $roles): Collection
    {
        return collect($roles)->filter()->unique('id')->values();
    }

    // |||||||||||||||||||||||||||||||||||||||||||||||||||||||||||
    // ACCOUNT TYPE

    /**
     * Move an account between workspaces.
     *
     * A type change is not a role change: it decides which dashboard the account
     * signs in to. Coming *out* of admin clears every role, because a member holding
     * one would be carrying access to a workspace they can no longer open.
     *
     * @param  iterable<Role>  $roles
     */
    public function changeType(User $user, UserTypeEnum $type, iterable $roles = []): bool
    {
        if ($this->typeChangeBlockedReason($user, $type)) {
            return false;
        }

        $from = $user->user_type;
        $roles = $type->carriesRole() ? $this->uniqueRoles($roles) : collect();

        $user->user_type = $type;

        $rolesChanged = $user->roles->pluck('id')->sort()->values()->all()
            !== $roles->pluck('id')->sort()->values()->all();

        if ($user->isClean() && ! $rolesChanged) {
            return false;
        }

        $user->save();

        if ($rolesChanged) {
            $user->roles()->sync($roles->pluck('id')->all());
            $user->unsetRelation('roles');
        }

        app(ActivityLogService::class)->logActivity(
            ActivityActionEnum::USER_TYPE_CHANGE,
            "Moved {$user->name} from {$from->label()} to {$type->label()}.",
            model: $user,
            prefixDescription: false,
        );

        app(GateService::class)->syncAuthenticated($user);

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

        return app(GateService::class)->assignmentBlockedReason($user, []);
    }

    public function adminCount(): int
    {
        return User::query()->admins()->count();
    }
}
