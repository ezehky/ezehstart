<?php

namespace App\Traits;

use App\Enums\GateAccessEnum;
use App\Models\Role;
use App\Models\User;
use App\Services\GateService;
use Flux\Flux;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;

/**
 * Drives the gate editor shared by the roles listing and the single-account view.
 *
 * The same grid edits both subjects. A role's map is absolute — a key it does not
 * carry is access nobody on that role has. An administrator's map is an override laid
 * over their role, so it has a third state the role editor does not: a blank row means
 * "whatever the role says", and is not the same answer as No access.
 *
 * @property-read array<int, array{key: string, label: string, level: GateAccessEnum}> $gateInheritance
 */
trait WithGateManager
{
    use WithFormResponseMessage;

    /**
     * One row per gateable screen, in sidebar order.
     *
     * A list rather than a key => level map because Livewire reads a dot in a
     * wire:model path as a step into a nested array, and half these keys are dotted
     * ('users.roles'). Binding by row index keeps the key intact.
     *
     * @var array<int, array{key: string, label: string, child: bool, level: string}>
     */
    public array $gateRows = [];

    public ?int $gateRoleId = null;

    public ?int $gateUserId = null;

    /**
     * Is the editor pointed at one administrator rather than at a whole role?
     */
    public function editingAdminGates(): bool
    {
        return $this->gateUserId !== null;
    }

    /**
     * Open the editor on a role.
     */
    public function openRoleGates(Role $role): void
    {
        $this->resetValidation();

        $this->gateRoleId = $role->id;
        $this->gateUserId = null;

        $this->buildGateRows($role->gatesArray());

        unset($this->gateInheritance);

        Flux::modal('gatesModal')->show();
    }

    /**
     * Open the editor on one administrator's override.
     */
    public function openAdminGates(User $user): void
    {
        $this->resetValidation();

        $this->gateRoleId = $user->role_id;
        $this->gateUserId = $user->id;

        // Null means the account has never been overridden, which is every row blank
        // — not every row denied. gatesArray() flattens both to [], and here that is
        // the right reading: a blank row *is* how "inherit" is shown.
        $this->buildGateRows($user->gatesArray());

        unset($this->gateInheritance);

        Flux::modal('gatesModal')->show();
    }

    /**
     * Who the open editor is about, for the modal heading.
     */
    public function gateSubjectLabel(): string
    {
        if ($this->editingAdminGates()) {
            return (string) (User::query()->find($this->gateUserId)?->name ?? 'this account');
        }

        return (string) (Role::query()->find($this->gateRoleId)?->name ?? 'this role');
    }

    /**
     * What the role underneath grants, shown beside each row so an override reads as
     * a change rather than as a value out of nowhere. Empty for a role edit.
     *
     * @return array<string, GateAccessEnum>
     */
    #[Computed]
    public function gateInheritance(): array
    {
        if (! $this->editingAdminGates() || ! $role = Role::query()->find($this->gateRoleId)) {
            return [];
        }

        return collect($this->gateRows)
            ->mapWithKeys(fn (array $row) => [$row['key'] => $role->gateFor($row['key'])])
            ->all();
    }

    /**
     * Fill every row with full access. The starting point for a new role that is
     * meant to be broad, rather than eighteen selects set by hand.
     */
    public function grantEveryGate(): void
    {
        foreach ($this->gateRows as $index => $row) {
            $this->gateRows[$index]['level'] = GateAccessEnum::FULL->value;
        }
    }

    /**
     * Empty every row. On a role that closes everything; on an administrator it puts
     * them back on their role, which is why the button says different things.
     */
    public function clearEveryGate(): void
    {
        foreach ($this->gateRows as $index => $row) {
            $this->gateRows[$index]['level'] = '';
        }
    }

    public function saveGates(): bool
    {
        $service = app(GateService::class);

        // Validated inline rather than through rules(). A class method beats a trait
        // method in PHP, so a screen with a rules() of its own — the account view has
        // one — would silently drop these and post whatever came off the wire.
        $this->validate([
            'gateRows' => ['array'],
            'gateRows.*.key' => ['required', 'string', Rule::in($service->keys())],
            'gateRows.*.level' => ['nullable', 'string', Rule::in(GateAccessEnum::values())],
        ]);

        // A blank row is an instruction, not a missing value: on a role it means no
        // access, on an override it means inherit. Dropping it here is what makes the
        // override sparse — only the keys somebody actually set are written.
        $submitted = collect($this->gateRows)
            ->filter(fn (array $row) => $row['level'] !== '')
            ->mapWithKeys(fn (array $row) => [$row['key'] => $row['level']])
            ->all();

        return $this->editingAdminGates()
            ? $this->saveAdminGates($service, $submitted)
            : $this->saveRoleGates($service, $submitted);
    }

    /**
     * Rebuild the grid from a stored map. A key the map does not carry comes back
     * blank, which each subject reads its own way.
     */
    private function buildGateRows(?array $map): void
    {
        $map ??= [];
        $rows = [];

        foreach (app(GateService::class)->resources() as $key => $resource) {
            $rows[] = [
                'key' => $key,
                'label' => $resource['label'],
                'child' => false,
                'level' => (string) ($map[$key] ?? ''),
            ];

            foreach ($resource['children'] as $childKey => $label) {
                $rows[] = [
                    'key' => $childKey,
                    'label' => $label,
                    'child' => true,
                    'level' => (string) ($map[$childKey] ?? ''),
                ];
            }
        }

        $this->gateRows = $rows;
    }

    private function saveRoleGates(GateService $service, array $submitted): bool
    {
        $role = Role::query()->find($this->gateRoleId);

        $this->respondError('That role no longer exists.', if: ! $role);

        $reason = $service->roleGatesBlockedReason($role, $submitted);
        $this->respondError($reason ?? '', if: $reason !== null);

        $this->respondPrimary(if: ! $service->updateRoleGates($role, $submitted));

        Flux::modal('gatesModal')->close();

        $this->afterGateChange();

        return $this->respondSuccess("Access for the {$role->name} role has been saved.");
    }

    private function saveAdminGates(GateService $service, array $submitted): bool
    {
        $user = User::query()->with('role')->find($this->gateUserId);

        $this->respondError('That account no longer exists.', if: ! $user);

        // Nothing overridden at all is not an empty override, it is no override —
        // and the difference is what puts the account back on its role.
        $gates = $submitted === [] ? null : $submitted;

        $reason = $service->adminGatesBlockedReason($user, $gates);
        $this->respondError($reason ?? '', if: $reason !== null);

        $this->respondPrimary(if: ! $service->updateAdminGates($user, $gates));

        Flux::modal('gatesModal')->close();

        $this->afterGateChange();

        return $this->respondSuccess(
            $gates === null
                ? 'This account is back on the access its role grants.'
                : 'Access for this account has been saved.'
        );
    }

    /**
     * Hook for the screen to refresh whatever it renders the gates from.
     */
    protected function afterGateChange(): void
    {
        //
    }
}
