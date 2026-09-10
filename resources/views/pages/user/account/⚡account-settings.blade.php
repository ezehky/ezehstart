<?php

use App\Models\NotificationType;
use App\Models\User;
use App\Services\UserService;
use App\Traits\WithFormResponseMessage;
use Livewire\Component;

new class extends Component
{
    use WithFormResponseMessage;

    public User $user;

    public array $notifications = [];

    /** @var array<int, array{title: string, description: string|null}> keyed by notification type id */
    public array $notificationTypes = [];

    public function mount(): void
    {
        $this->user = auth()->user();

        $userService = app(UserService::class, ['user' => $this->user]);

        // Backfill any settings/preferences that don't exist yet for this user.
        $userService->runProfileSettingsUpdate();
        $userService->runNotificationPreferencesUpdate();

        // Only the types still on offer. A retired type keeps its rows until the
        // admin deletes it, and neither should appear as a switch in the meantime.
        $types = NotificationType::query()->active()->inFlowOrder()->get();

        $preferences = $this->user->notificationPreferences;

        foreach ($types as $type) {
            $this->notificationTypes[$type->id] = [
                'title' => $type->title,
                'description' => $type->description,
            ];

            $this->notifications[$type->id] = (bool) $preferences
                ->firstWhere('notification_type_id', $type->id)?->status?->boolValue();
        }

        kSetSiteTitle('profile', 'settings');
    }

    protected function rules(): array
    {
        return [
            'notifications.*' => ['boolean'],
        ];
    }

    public function save(): void
    {
        $this->validate();

        foreach ($this->notifications as $typeId => $status) {
            $this->user->notificationPreferences()
                ->where('notification_type_id', $typeId)
                ->update(['status' => $status]);
        }

        $this->respondSuccess('Your preferences have been saved.');
    }
};
?>

<div class="mx-auto max-w-3xl space-y-6">
    <x-dashboard.tab-nav
        active="account-settings"
        isUser
        title="Account Settings"
        subtitle="Control notifications, appearance, and language preferences."
    />

    <form wire:submit="save" class="space-y-6">
        <flux:card class="space-y-6">
            <div>
                <flux:heading level="2" size="sm">Notifications</flux:heading>
                <flux:text class="mt-1">Choose what you'd like to hear from us.</flux:text>
            </div>

            <div class="space-y-4">
                @foreach ($notificationTypes as $typeId => $type)
                    <flux:switch
                        wire:key="notification-{{ $typeId }}"
                        wire:model="notifications.{{ $typeId }}"
                        :label="$type['title']"
                        :description="$type['description']"
                    />
                @endforeach
            </div>

            <flux:separator variant="subtle" />

            <div>
                <flux:heading level="2" size="sm">Appearance</flux:heading>
                <flux:text class="mt-1">Choose how the dashboard looks on this device.</flux:text>
            </div>

            <flux:radio.group x-data variant="segmented" x-model="$flux.appearance">
                <flux:radio value="light" icon="sun">Light</flux:radio>
                <flux:radio value="dark" icon="moon">Dark</flux:radio>
                <flux:radio value="system" icon="computer-desktop">System</flux:radio>
            </flux:radio.group>

            <flux:separator variant="subtle" />

            <div>
                <flux:heading level="2" size="sm">Language</flux:heading>
                <flux:text class="mt-1">More languages are coming soon.</flux:text>
            </div>

            <flux:select value="en" disabled>
                <flux:select.option value="en">English</flux:select.option>
            </flux:select>

            <div class="flex justify-end">
                <flux:button type="submit" variant="primary" icon="check">Save preferences</flux:button>
            </div>
        </flux:card>
    </form>
</div>
