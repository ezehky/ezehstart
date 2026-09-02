<?php

use App\Enums\ActivityActionEnum;
use App\Enums\UserRoleEnum;
use App\Models\Role;
use App\Services\ActivityLogService;
use App\Services\UserRoleService;
use App\Traits\WithFormResponseMessage;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component
{
    use WithFormResponseMessage;

    public function mount(): void
    {
        kSetSiteTitle('users', 'roles');
    }

    #[Computed]
    public function roles(): Collection
    {
        return Role::query()
            ->withCount([
                'userRoles',
                'userRoles as active_users_count' => fn ($query) => $query->isActive(),
            ])
            ->orderBy('id')
            ->get();
    }

    /**
     * Role types defined in the application that have no row in the roles table yet.
     *
     * @return array<int, UserRoleEnum>
     */
    #[Computed]
    public function missingRoles(): array
    {
        $existing = $this->roles->map(fn (Role $role) => $role->name)->filter();

        return collect(UserRoleEnum::cases())
            ->reject(fn (UserRoleEnum $role) => $existing->contains($role))
            ->values()
            ->all();
    }

    public function description(UserRoleEnum $role): string
    {
        return match ($role) {
            UserRoleEnum::ADMIN => 'Runs the administration workspace: configuration and user management.',
            UserRoleEnum::USER => 'Reaches the member workspace and their own account settings.',
        };
    }

    public function dashboardRoute(UserRoleEnum $role): ?string
    {
        return match ($role) {
            UserRoleEnum::ADMIN => route('admin.admins'),
            UserRoleEnum::USER => route('admin.members'),
        };
    }

    public function syncRoles(): bool
    {
        $missing = $this->missingRoles;

        $this->respondPrimary('Every role type already exists.', if: $missing === []);

        $service = app(UserRoleService::class);

        foreach ($missing as $role) {
            $service->role($role);
        }

        $names = collect($missing)->map(fn (UserRoleEnum $role) => $role->label())->implode(', ');

        app(ActivityLogService::class)->logActivity(
            ActivityActionEnum::CREATE,
            "Created missing role types: {$names}.",
            prefixDescription: false,
        );

        unset($this->roles, $this->missingRoles);

        return $this->respondSuccess('Missing role types have been created.');
    }
};
?>

<div class="space-y-6">
    <flux:card class="space-y-6">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
            <div>
                <flux:heading level="2" size="lg">Roles</flux:heading>
                <flux:text class="mt-1">
                    The role types an account can carry. A user may hold more than one at the same time.
                </flux:text>
            </div>

            @if ($this->missingRoles)
                <flux:button variant="primary" icon="plus" wire:click="syncRoles">
                    Create {{ count($this->missingRoles) }} missing role(s)
                </flux:button>
            @endif
        </div>

        <flux:table>
            <flux:table.columns>
                <flux:table.column>Role</flux:table.column>
                <flux:table.column>What it can do</flux:table.column>
                <flux:table.column>Active users</flux:table.column>
                <flux:table.column>Total assignments</flux:table.column>
                <flux:table.column>Actions</flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
                @forelse ($this->roles as $item)
                    <flux:table.row wire:key="role-{{ $item->id }}">
                        <flux:table.cell>
                            <x-dashboard.user-roles :roles="collect([$item->name])->filter()" />
                        </flux:table.cell>
                        <flux:table.cell class="max-w-md text-slate-500 dark:text-slate-400">
                            {{ $item->name ? $this->description($item->name) : '—' }}
                        </flux:table.cell>
                        <flux:table.cell class="font-medium">{{ number_format($item->active_users_count) }}</flux:table.cell>
                        <flux:table.cell>{{ number_format($item->user_roles_count) }}</flux:table.cell>
                        <flux:table.cell>
                            @if ($item->name)
                                <flux:button
                                    icon="arrow-top-right-on-square"
                                    variant="ghost"
                                    size="sm"
                                    :href="$this->dashboardRoute($item->name)"
                                    wire:navigate
                                >
                                    View users
                                </flux:button>
                            @endif
                        </flux:table.cell>
                    </flux:table.row>
                @empty
                    <flux:table.row>
                        <flux:table.cell colspan="5">
                            <x-dashboard.workspace-no-record
                                label="Roles"
                                icon="identification"
                                text="No role types exist yet. Create them to start assigning accounts."
                            />
                        </flux:table.cell>
                    </flux:table.row>
                @endforelse
            </flux:table.rows>
        </flux:table>
    </flux:card>
</div>
