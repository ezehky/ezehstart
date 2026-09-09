<?php

namespace App\Traits;

use App\Enums\UserRoleEnum;
use App\Models\User;
use App\Services\UserRoleService;
use Flux\Flux;
use Livewire\Attributes\Computed;

/**
 * Drives the "manage roles" modal shared by the admins list and the user view.
 *
 * @property-read User|null $roleUser
 * @property-read array<int, array{role: string, label: string, description: string, has: bool, blocked: string|null}> $roleMatrix
 * @property-read array{role: string, label: string, description: string, has: bool, action: string, blocked: string|null}|null $pendingRoleEntry
 */
trait WithUserRoleManager
{
    use WithFormResponseMessage;

    public ?int $roleUserId = null;

    public ?string $pendingRole = null;

    public function openRoleManager(User $user): void
    {
        $this->roleUserId = $user->id;

        unset($this->roleUser, $this->roleMatrix, $this->pendingRoleEntry);

        $this->reset('pendingRole');

        Flux::modal('userRolesModal')->show();
    }

    #[Computed]
    public function roleUser(): ?User
    {
        return $this->roleUserId ? User::query()->find($this->roleUserId) : null;
    }

    /**
     * The roles the modal offers. Every case by default — override on a listing that
     * should only manage a subset.
     *
     * @return array<int, UserRoleEnum>
     */
    protected function roleManagerRoles(): array
    {
        return UserRoleEnum::cases();
    }

    /**
     * Every offered role with its current state, the action it accepts, and the reason
     * that action is unavailable when it is.
     *
     * @return array<int, array{role: string, label: string, description: string, has: bool, action: string, blocked: string|null}>
     */
    #[Computed]
    public function roleMatrix(): array
    {
        if (! $user = $this->roleUser) {
            return [];
        }

        $service = app(UserRoleService::class);
        $held = $service->rolesFor($user);

        return collect($this->roleManagerRoles())
            ->map(function (UserRoleEnum $role) use ($service, $user, $held): array {
                $entry = [
                    'role' => $role->value,
                    'label' => $role->label(),
                    'description' => $this->roleDescription($role),
                    'has' => $held->contains($role),
                ];

                if ($entry['has']) {
                    return [...$entry, 'action' => 'revoke', 'blocked' => $service->revokeBlockedReason($user, $role)];
                }

                // Crossing an exclusivity boundary is a swap, not an addition.
                if ($service->conflictingRoles($user, $role) !== []) {
                    return [...$entry, 'action' => 'switch', 'blocked' => $service->switchBlockedReason($user, $role)];
                }

                return [...$entry, 'action' => 'grant', 'blocked' => $service->grantBlockedReason($user, $role)];
            })
            ->all();
    }

    /**
     * Giving a role up — on its own or as half of a switch — asks first. The row that
     * was clicked is remembered here rather than carried through the dialog, which
     * only ever confirms the one action.
     */
    public function confirmRoleAction(string $role): void
    {
        $this->pendingRole = $role;

        unset($this->pendingRoleEntry);

        Flux::modal('roleActionModal')->show();
    }

    /**
     * The matrix row the confirmation is about, re-read rather than trusted: the
     * action it accepts may have moved on since the modal was opened.
     *
     * @return array{role: string, label: string, description: string, has: bool, action: string, blocked: string|null}|null
     */
    #[Computed]
    public function pendingRoleEntry(): ?array
    {
        return collect($this->roleMatrix)->firstWhere('role', $this->pendingRole);
    }

    public function applyRoleAction(): bool
    {
        $entry = $this->pendingRoleEntry;

        abort_unless((bool) $entry, 404);

        return $entry['action'] === 'switch'
            ? $this->switchRole($entry['role'])
            : $this->revokeRole($entry['role']);
    }

    public function switchRole(string $role): bool
    {
        $user = $this->resolveRoleUser();
        $enum = UserRoleEnum::from($role);
        $service = app(UserRoleService::class);

        $reason = $service->switchBlockedReason($user, $enum);
        $this->respondError($reason ?? '', if: $reason !== null);

        $dropped = collect($service->conflictingRoles($user, $enum))
            ->map(fn (UserRoleEnum $current) => $current->label())
            ->implode(' and ');

        $this->respondPrimary("{$user->name} could not be switched.", if: ! $service->switchTo($user, $enum));

        $this->refreshRoleState();

        return $this->respondSuccess("{$user->name} switched from {$dropped} to {$enum->label()}.");
    }

    public function grantRole(string $role): bool
    {
        $user = $this->resolveRoleUser();
        $enum = UserRoleEnum::from($role);
        $service = app(UserRoleService::class);

        // Surface the guard's own wording rather than a generic failure.
        $reason = $service->grantBlockedReason($user, $enum);
        $this->respondError($reason ?? '', if: $reason !== null);

        $this->respondPrimary(
            "{$user->name} already carries the {$enum->label()} role.",
            if: ! $service->grant($user, $enum),
        );

        $this->refreshRoleState();

        return $this->respondSuccess("{$enum->label()} role granted to {$user->name}.");
    }

    public function revokeRole(string $role): bool
    {
        $user = $this->resolveRoleUser();
        $enum = UserRoleEnum::from($role);
        $service = app(UserRoleService::class);

        // Surface the guard's own wording rather than a generic failure.
        $reason = $service->revokeBlockedReason($user, $enum);
        $this->respondError($reason ?? '', if: $reason !== null);

        $this->respondPrimary(
            "{$user->name} does not carry the {$enum->label()} role.",
            if: ! $service->revoke($user, $enum),
        );

        $this->refreshRoleState();

        return $this->respondSuccess("{$enum->label()} role removed from {$user->name}.");
    }

    /**
     * Refresh whatever the host page renders once a role changed. Pages override this.
     */
    protected function afterRoleChange(): void {}

    private function refreshRoleState(): void
    {
        unset($this->roleUser, $this->roleMatrix, $this->pendingRoleEntry);

        $this->reset('pendingRole');

        $this->afterRoleChange();
    }

    private function resolveRoleUser(): User
    {
        $user = $this->roleUser;

        abort_unless((bool) $user, 404);

        return $user;
    }

    private function roleDescription(UserRoleEnum $role): string
    {
        return match ($role) {
            UserRoleEnum::ADMIN => 'Full access to the administration workspace.',
            UserRoleEnum::USER => 'Access to the member workspace and account settings.',
        };
    }
}
