<?php

use App\Enums\ActivityActionEnum;
use App\Enums\StatusUser;
use App\Enums\UserRoleEnum;
use App\Models\User;
use App\Models\UserProfile;
use App\Services\ActivityLogService;
use App\Services\UserService;
use App\Traits\WithUserRoleManager;
use Flux\Flux;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component
{
    use WithUserRoleManager;

    public User $user;

    public string $name = '';

    public string $email = '';

    public ?string $phone_number = null;

    public bool $status = true;

    public ?string $password = null;

    /** @var array<string, bool> */
    public array $profileSettings = [];

    public function mount(): void
    {
        $this->user->load(['userProfile', 'userRoles.role']);

        // The role manager modal always targets the account being viewed.
        $this->roleUserId = $this->user->id;

        kSetSiteTitle('users', $this->listKey(), $this->user->name, format: false);
    }

    #[Computed]
    public function roles(): Collection
    {
        return $this->user->activeRoles();
    }

    #[Computed]
    public function isAdminAccount(): bool
    {
        return $this->roles->contains(UserRoleEnum::ADMIN);
    }

    /**
     * Which listing this account belongs to, used for the title and the back link.
     */
    public function listKey(): string
    {
        return match (true) {
            $this->isAdminAccount => 'admins',
            $this->roles->isNotEmpty() => 'members',
            default => 'unassigned',
        };
    }

    #[Computed]
    public function activityLogs(): Collection
    {
        return app(ActivityLogService::class)->getActivityLogsForUser($this->user, limit: 12);
    }

    /**
     * The permission switches held on the account's profile, with their copy.
     *
     * Mirrors UserService::profileDefaultSettings() — add a key in both places.
     *
     * @return array<string, array{label: string, description: string}>
     */
    #[Computed]
    public function settingsCopy(): array
    {
        return [
            'can-delete-account' => [
                'label' => 'Can delete account',
                'description' => 'Remove their own account from the platform.',
            ],
        ];
    }

    public function editAccount(): void
    {
        $this->resetValidation();

        $this->name = $this->user->name;
        $this->email = $this->user->email;
        $this->phone_number = $this->user->phone_number;
        $this->status = $this->user->status->boolValue();
        $this->password = null;

        Flux::modal('accountModal')->show();
    }

    protected function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:50', Rule::unique(User::class, 'email')->ignore($this->user->id)],
            'phone_number' => ['nullable', 'string', 'max:20'],
            'status' => ['boolean'],
            'password' => ['nullable', 'string', 'min:5'],
        ];
    }

    public function save(): bool
    {
        $this->validate();

        $this->user->name = $this->name;
        $this->user->email = strtolower($this->email);
        $this->user->phone_number = $this->phone_number;
        $this->user->status = StatusUser::tryFrom((int) $this->status);

        if ($this->password) {
            $this->user->password = $this->password;
        }

        $this->respondPrimary(if: $this->user->isClean());

        $logService = app(ActivityLogService::class);
        $affectedColumns = $logService->affectedColumns($this->user);

        $this->user->save();

        $logService->logActivity(
            ActivityActionEnum::USER_UPDATE,
            "user: {$this->user->name}",
            $affectedColumns,
            $this->user,
        );

        Flux::modal('accountModal')->close();

        unset($this->activityLogs);

        return $this->respondSuccess('Account has been successfully updated.');
    }

    public function editSettings(): void
    {
        $this->resetValidation();

        // Backfill any switch that was never written for this account.
        app(UserService::class, ['user' => $this->user])->runProfileSettingsUpdate();

        $this->user->load('userProfile');
        $stored = (array) ($this->user->userProfile?->settings ?? []);

        foreach (array_keys($this->settingsCopy) as $key) {
            $this->profileSettings[$key] = (bool) data_get($stored, $key, false);
        }

        Flux::modal('settingsModal')->show();
    }

    public function saveSettings(): bool
    {
        $this->validate(['profileSettings.*' => ['boolean']]);

        $profile = UserProfile::query()->firstOrNew(['user_id' => $this->user->id]);
        $settings = (array) ($profile->settings ?? []);

        foreach (array_keys($this->settingsCopy) as $key) {
            $settings[$key] = (bool) data_get($this->profileSettings, $key, false);
        }

        $profile->settings = $settings;

        $this->respondPrimary(if: $profile->isClean());

        $logService = app(ActivityLogService::class);
        $affectedColumns = $logService->affectedColumns($profile);

        $profile->save();

        $logService->logActivity(
            ActivityActionEnum::SETTINGS_UPDATE,
            "Updated profile permissions for {$this->user->name}.",
            $affectedColumns,
            $profile,
            prefixDescription: false,
        );

        Flux::modal('settingsModal')->close();

        $this->user->load('userProfile');
        unset($this->activityLogs);

        return $this->respondSuccess('Profile permissions have been saved.');
    }

    public function toggleStatus(): bool
    {
        $this->respondError(
            'You cannot suspend your own account.',
            if: $this->user->id === auth()->id(),
        );

        $this->user->status = $this->user->status->isActive() ? StatusUser::SUSPENDED : StatusUser::ACTIVE;
        $this->user->save();

        app(ActivityLogService::class)->logActivity(
            ActivityActionEnum::USER_STATUS_CHANGE,
            "Set {$this->user->name}'s account to {$this->user->status->label()}.",
            model: $this->user,
            prefixDescription: false,
        );

        unset($this->activityLogs);

        return $this->respondSuccess("Account is now {$this->user->status->label()}.");
    }

    protected function afterRoleChange(): void
    {
        $this->user->refresh()->load(['userRoles.role', 'userProfile']);

        unset(
            $this->roles,
            $this->isAdminAccount,
            $this->activityLogs,
        );
    }
};
?>

<div class="space-y-6">
    <div>
        <flux:button
            variant="ghost"
            size="sm"
            icon="arrow-left"
            :href="route('admin.'.$this->listKey())"
            wire:navigate
        >
            Back to list
        </flux:button>
    </div>

    @if ($this->roles->isEmpty())
        <flux:callout icon="exclamation-triangle" variant="warning">
            <flux:callout.heading>This account holds no role</flux:callout.heading>
            <flux:callout.text>
                It cannot sign in to any workspace. Use <span class="font-medium">Manage roles</span> to grant one.
            </flux:callout.text>
        </flux:callout>
    @endif

    {{-- Identity --}}
    <flux:card class="space-y-6">
        <div class="flex flex-col gap-5 lg:flex-row lg:items-start lg:justify-between">
            <div class="flex items-start gap-4">
                <x-dashboard.avatar :user="$user" size="lg" />
                <div class="min-w-0 space-y-2">
                    <flux:heading level="1" size="xl">{{ $user->name }}</flux:heading>
                    <div class="space-y-1 text-sm text-slate-500 dark:text-slate-400">
                        <div>{{ $user->email }}</div>
                        <div>{{ $user->phone_number ?: 'No phone number' }}</div>
                    </div>
                    <div class="flex flex-wrap items-center gap-2 pt-1">
                        <x-status :status="$user->status" />
                        <x-dashboard.user-roles :roles="$this->roles" />
                        @if ($user->hasVerifiedEmail())
                            <flux:badge size="sm" color="green">Email verified</flux:badge>
                        @else
                            <flux:badge size="sm" color="amber">Email unverified</flux:badge>
                        @endif
                    </div>
                </div>
            </div>

            <div class="flex flex-wrap gap-2">
                <flux:button icon="pencil-square" variant="primary" wire:click="editAccount">Edit account</flux:button>
                <flux:button icon="shield-check" variant="filled" wire:click="openRoleManager({{ $user->id }})">
                    Manage roles
                </flux:button>
                <flux:button
                    :icon="$user->status->isActive() ? 'lock-closed' : 'lock-open'"
                    :variant="$user->status->isActive() ? 'danger' : 'filled'"
                    wire:click="toggleStatus"
                    wire:confirm="{{ $user->status->isActive() ? 'Suspend this account?' : 'Reactivate this account?' }}"
                >
                    {{ $user->status->isActive() ? 'Suspend' : 'Activate' }}
                </flux:button>
            </div>
        </div>

        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <x-dashboard.mini-stat :value="$user->createdAtHuman()" label="Joined" />
            <x-dashboard.mini-stat :value="$user->last_seen_at ? $user->lastSeenAtDiffForHumans() : 'Never'" label="Last seen" />
            <x-dashboard.mini-stat :value="$user->timezone" label="Timezone" />
            <x-dashboard.mini-stat :value="$user->profileCompletion().'%'" label="Profile completion" />
        </div>
    </flux:card>

    <div class="grid gap-6 xl:grid-cols-[minmax(0,1fr)_minmax(320px,0.7fr)]">
        <div class="space-y-6">
            {{-- Activity --}}
            <flux:card>
                <div class="flex items-start justify-between gap-4">
                    <div>
                        <flux:heading level="2" size="lg">Activity</flux:heading>
                        <flux:text class="mt-1 text-sm">What this account has done recently.</flux:text>
                    </div>
                    <flux:button
                        variant="ghost"
                        size="sm"
                        icon="arrow-top-right-on-square"
                        :href="route('admin.activity-logs', ['q' => $user->email])"
                        wire:navigate
                        aria-label="View all activity logs"
                    />
                </div>

                <div class="mt-5 divide-y divide-slate-100 dark:divide-slate-800">
                    @forelse ($this->activityLogs as $log)
                        <div class="py-3 first:pt-0 last:pb-0" wire:key="user-log-{{ $log->id }}">
                            <p class="text-sm leading-5 text-slate-700 dark:text-slate-200">{{ $log->description }}</p>
                            <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">{{ $log->createdAtDiffForHumans() }}</p>
                        </div>
                    @empty
                        <div class="py-10 text-center text-sm text-slate-500 dark:text-slate-400">
                            No activity has been recorded for this account.
                        </div>
                    @endforelse
                </div>
            </flux:card>
        </div>

        <div class="space-y-6">
            {{-- Profile --}}
            @php($profile = $user->userProfile)
            <flux:card class="space-y-4">
                <flux:heading level="2" size="lg">Profile</flux:heading>

                @php($details = [
                    'Gender' => $profile?->gender?->label(),
                    'City' => $profile?->city,
                    'Postal code' => $profile?->postal_code,
                ])

                <dl class="divide-y divide-slate-100 text-sm dark:divide-slate-800">
                    @foreach ($details as $label => $value)
                        <div class="flex items-start justify-between gap-4 py-2.5 first:pt-0 last:pb-0">
                            <dt class="text-slate-500 dark:text-slate-400">{{ $label }}</dt>
                            <dd class="text-right font-medium text-slate-900 dark:text-white">{{ $value ?: '—' }}</dd>
                        </div>
                    @endforeach
                </dl>

                @if ($profile?->bio)
                    <div class="border-t border-slate-100 pt-4 dark:border-slate-800">
                        <p class="text-xs font-semibold uppercase tracking-wide text-slate-400">Bio</p>
                        <p class="mt-2 text-sm text-slate-600 dark:text-slate-300">{{ $profile->bio }}</p>
                    </div>
                @endif
            </flux:card>

            {{-- Permissions --}}
            <flux:card class="space-y-4">
                <div class="flex items-start justify-between gap-4">
                    <div>
                        <flux:heading level="2" size="lg">Permissions</flux:heading>
                        <flux:text class="mt-1 text-sm">What this account is allowed to do.</flux:text>
                    </div>
                    <flux:button variant="ghost" size="sm" icon="pencil-square" wire:click="editSettings" aria-label="Edit permissions" />
                </div>

                <dl class="divide-y divide-slate-100 text-sm dark:divide-slate-800">
                    @foreach ($this->settingsCopy as $key => $copy)
                        <div class="flex items-center justify-between gap-4 py-2.5 first:pt-0 last:pb-0">
                            <dt class="text-slate-500 dark:text-slate-400">{{ $copy['label'] }}</dt>
                            <dd>
                                @if (data_get($profile?->settings, $key, false))
                                    <flux:badge size="sm" color="green">Allowed</flux:badge>
                                @else
                                    <flux:badge size="sm" color="red">Blocked</flux:badge>
                                @endif
                            </dd>
                        </div>
                    @endforeach
                </dl>
            </flux:card>
        </div>
    </div>

    <flux:modal name="accountModal" class="md:w-150">
        <form wire:submit="save" class="space-y-6">
            <div>
                <flux:heading size="lg">Edit account</flux:heading>
                <flux:text class="mt-1">Update this account's sign-in details.</flux:text>
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <flux:input label="Name" wire:model="name" autofocus badge="required" />
                <flux:input type="email" label="Email" wire:model="email" badge="required" />
                <flux:input label="Phone number" wire:model="phone_number" placeholder="e.g. +234 800 000 0000" />
                <flux:input
                    type="password"
                    label="Password"
                    wire:model="password"
                    placeholder="Leave blank to keep current"
                    viewable
                />
            </div>

            <flux:switch wire:model="status" label="Active account" description="Allow this account to sign in." />

            <div class="flex justify-end gap-3">
                <flux:modal.close>
                    <flux:button variant="ghost">Cancel</flux:button>
                </flux:modal.close>

                <flux:button type="submit" variant="primary">Save account</flux:button>
            </div>
        </form>
    </flux:modal>

    <flux:modal name="settingsModal" class="md:w-135">
        <form wire:submit="saveSettings" class="space-y-6">
            <div>
                <flux:heading size="lg">Profile permissions</flux:heading>
                <flux:text class="mt-1">
                    Control what <span class="font-medium">{{ $user->name }}</span> can do from their own dashboard.
                </flux:text>
            </div>

            <div class="space-y-4">
                @foreach ($this->settingsCopy as $key => $copy)
                    <flux:switch
                        wire:key="setting-{{ $key }}"
                        wire:model="profileSettings.{{ $key }}"
                        :label="$copy['label']"
                        :description="$copy['description']"
                    />
                @endforeach
            </div>

            <div class="flex justify-end gap-3">
                <flux:modal.close>
                    <flux:button variant="ghost">Cancel</flux:button>
                </flux:modal.close>

                <flux:button type="submit" variant="primary">Save permissions</flux:button>
            </div>
        </form>
    </flux:modal>

    <x-dashboard.user-roles-modal :user="$this->roleUser" :matrix="$this->roleMatrix" />
</div>
