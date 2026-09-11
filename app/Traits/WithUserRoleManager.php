<?php

namespace App\Traits;

use App\Enums\GateAccessEnum;
use App\Enums\UserTypeEnum;
use App\Models\Role;
use App\Models\User;
use App\Services\GateService;
use App\Services\RoleService;
use Flux\Flux;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;

/**
 * Drives the "account access" modal shared by the admins list, the users list and
 * the user view.
 *
 * Type and roles are edited together because they are one decision. A type is which
 * workspace the account signs in to; a role only exists inside the admin one. Editing
 * them on separate screens makes it possible to pick roles for an account that is
 * about to stop being an admin, and then to save both.
 *
 * The role box is a set, not a choice: an admin carries any number and the maps merge
 * (see GateService). What the form posts is what the account ends up holding, so
 * unticking a role revokes it.
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
     * The ticked role ids, as strings because that is what a checkbox group posts.
     * Empty means no role at all — a real choice for an admin, and the only choice
     * for a member.
     *
     * @var array<int, string>
     */
    public array $accountRoles = [];

    public function openRoleManager(User $user): void
    {
        $this->respondError(
            'You do not have access to change what accounts reach.',
            if: ! kGate(GateService::ADMINISTRATION, GateAccessEnum::FULL),
        );

        $this->resetValidation();

        $this->roleUserId = $user->id;
        $this->accountType = $user->user_type->value;
        $this->accountRoles = $user->roles->pluck('id')->map(fn ($id) => (string) $id)->all();

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
        return $this->roleUserId ? User::query()->with('roles')->find($this->roleUserId) : null;
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
     * Picking a member empties the role box, so the modal never shows a member holding
     * one. Nothing is written until save.
     */
    public function updatedAccountType(): void
    {
        if (! $this->pendingType()?->carriesRole()) {
            $this->accountRoles = [];
        }

        unset($this->accessBlockedReason, $this->pendingAccountType);
    }

    public function updatedAccountRoles(): void
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
            ? $service->assignBlockedReason($user, $this->pendingRoles())
            : null;
    }

    public function saveRoleAccess(): bool
    {
        // Putting somebody on a role is handing out access, so it asks for the gate
        // that hands out gates. The button is hidden as well; this is the boundary.
        $this->respondError(
            'You do not have access to change what accounts reach.',
            if: ! kGate(GateService::ADMINISTRATION, GateAccessEnum::FULL),
        );

        $user = $this->resolveRoleUser();

        // Validated inline rather than through rules(). A class method beats a trait
        // method in PHP, so a screen with a rules() of its own would silently drop
        // these and save whatever came off the wire.
        $this->validate($this->roleManagerRules());

        $service = app(RoleService::class);
        $type = $this->pendingType();
        $roles = $type?->carriesRole() ? $this->pendingRoles() : collect();

        // The guard's own wording, rather than a generic failure.
        $reason = $this->accessBlockedReason;
        $this->respondError($reason ?? '', if: $reason !== null);

        $changed = $type === $user->user_type
            ? $service->syncRoles($user, $roles)
            : $service->changeType($user, $type, $roles);

        $this->respondPrimary(if: ! $changed);

        Flux::modal('userRolesModal')->close();

        $this->refreshRoleState();

        return $this->respondSuccess("Access for {$user->name} has been saved.");
    }

    /**
     * The whitelist the modal posts against. Role ids are compared as strings because
     * that is what a checkbox group sends.
     *
     * @return array<string, array<int, mixed>>
     */
    protected function roleManagerRules(): array
    {
        return [
            'accountType' => ['required', Rule::in(UserTypeEnum::values())],
            'accountRoles' => ['array'],
            'accountRoles.*' => [Rule::in($this->assignableRoles->pluck('id')->map(fn ($id) => (string) $id)->all())],
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

    /**
     * The roles currently ticked, resolved against the assignable list rather than
     * fetched: an id that is not on offer is not a role, whatever was posted.
     *
     * @return Collection<int, Role>
     */
    private function pendingRoles(): Collection
    {
        return collect($this->accountRoles)
            ->map(fn ($id) => $this->assignableRoles->firstWhere('id', (int) $id))
            ->filter()
            ->unique('id')
            ->values();
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
