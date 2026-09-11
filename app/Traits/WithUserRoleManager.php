<?php

namespace App\Traits;

use App\Enums\UserTypeEnum;
use App\Models\Role;
use App\Models\User;
use App\Services\RoleService;
use Flux\Flux;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;

/**
 * Drives the "account access" modal shared by the admins list, the members list and
 * the user view.
 *
 * Type and role are edited together because they are one decision. A type is which
 * workspace the account signs in to; a role only exists inside the admin one. Editing
 * them on separate screens makes it possible to pick a role for an account that is
 * about to stop being an admin, and then to save both.
 *
 * @property-read User|null $roleUser
 * @property-read Collection<int, Role> $assignableRoles
 * @property-read UserTypeEnum|null $pendingAccountType
 * @property-read string|null $accessBlockedReason
 */
trait WithUserRoleManager
{
    use WithFormResponseMessage;

    public ?int $roleUserId = null;

    public string $accountType = '';

    /**
     * The chosen role id, as a string because it comes off a `<select>`. Empty means
     * no role — a real choice for an admin, and the only choice for a member.
     */
    public string $accountRole = '';

    public function openRoleManager(User $user): void
    {
        $this->resetValidation();

        $this->roleUserId = $user->id;
        $this->accountType = $user->user_type->value;
        $this->accountRole = (string) ($user->role_id ?? '');

        unset($this->roleUser, $this->assignableRoles, $this->accessBlockedReason, $this->pendingAccountType);

        Flux::modal('userRolesModal')->show();
    }

    /**
     * The type currently in the box, for the modal to branch on.
     *
     * Exposed as a computed rather than read off $accountType in the view: the modal
     * is an anonymous component and gets no access to a Livewire property.
     */
    #[Computed]
    public function pendingAccountType(): ?UserTypeEnum
    {
        return $this->pendingType();
    }

    #[Computed]
    public function roleUser(): ?User
    {
        return $this->roleUserId ? User::query()->with('role')->find($this->roleUserId) : null;
    }

    /**
     * The roles the modal offers.
     *
     * Only live ones: putting an account on a switched-off role would grant nothing
     * and read as a bug rather than as the deliberate suspension it is.
     *
     * @return Collection<int, Role>
     */
    #[Computed]
    public function assignableRoles(): Collection
    {
        return Role::query()->isActive()->orderBy('name')->get();
    }

    /**
     * Picking a member clears the role box, so the modal never shows a member holding
     * one. Nothing is written until save.
     */
    public function updatedAccountType(): void
    {
        if (! $this->pendingType()?->carriesRole()) {
            $this->accountRole = '';
        }

        unset($this->accessBlockedReason, $this->pendingAccountType);
    }

    public function updatedAccountRole(): void
    {
        unset($this->accessBlockedReason);
    }

    /**
     * Why the combination currently in the boxes would be refused, or null when it
     * would be accepted. Rendered live so the Save button can say why it will not work
     * before it is pressed.
     */
    #[Computed]
    public function accessBlockedReason(): ?string
    {
        if (! $user = $this->roleUser) {
            return null;
        }

        $type = $this->pendingType();

        if (! $type) {
            return null;
        }

        $service = app(RoleService::class);

        if ($type !== $user->user_type) {
            return $service->typeChangeBlockedReason($user, $type);
        }

        return $type->carriesRole()
            ? $service->assignBlockedReason($user, $this->pendingRole())
            : null;
    }

    public function saveRoleAccess(): bool
    {
        $user = $this->resolveRoleUser();

        // Validated inline rather than through rules(). A class method beats a trait
        // method in PHP, so a screen with a rules() of its own would silently drop
        // these and save whatever came off the wire.
        $this->validate($this->roleManagerRules());

        $service = app(RoleService::class);
        $type = $this->pendingType();
        $role = $type?->carriesRole() ? $this->pendingRole() : null;

        // The guard's own wording, rather than a generic failure.
        $reason = $this->accessBlockedReason;
        $this->respondError($reason ?? '', if: $reason !== null);

        $changed = $type === $user->user_type
            ? $service->assign($user, $role)
            : $service->changeType($user, $type, $role);

        $this->respondPrimary(if: ! $changed);

        Flux::modal('userRolesModal')->close();

        $this->refreshRoleState();

        return $this->respondSuccess("Access for {$user->name} has been saved.");
    }

    /**
     * The whitelist the modal posts against. A role id is compared as a string
     * because that is what a `<select>` sends.
     *
     * @return array<string, array<int, mixed>>
     */
    protected function roleManagerRules(): array
    {
        return [
            'accountType' => ['required', Rule::in(UserTypeEnum::values())],
            'accountRole' => ['nullable', Rule::in($this->assignableRoles->pluck('id')->map(fn ($id) => (string) $id)->all())],
        ];
    }

    /**
     * Refresh whatever the host page renders once access changed. Pages override this.
     */
    protected function afterRoleChange(): void {}

    private function pendingType(): ?UserTypeEnum
    {
        return UserTypeEnum::tryFrom($this->accountType);
    }

    private function pendingRole(): ?Role
    {
        return $this->accountRole === ''
            ? null
            : $this->assignableRoles->firstWhere('id', (int) $this->accountRole);
    }

    private function refreshRoleState(): void
    {
        unset($this->roleUser, $this->assignableRoles, $this->accessBlockedReason, $this->pendingAccountType);

        $this->afterRoleChange();
    }

    private function resolveRoleUser(): User
    {
        $user = $this->roleUser;

        abort_unless((bool) $user, 404);

        return $user;
    }
}
