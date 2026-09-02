<?php

use App\Enums\NotificationTypeEnum;
use App\Models\User;
use App\Services\UserService;
use App\Traits\WithFormResponseMessage;
use Livewire\Component;

new class extends Component
{
    use WithFormResponseMessage;

    public User $user;

    public array $notifications = [];

    public array $notificationTypes;

    public function mount(): void
    {
        $this->user = auth()->user();

        $userService = app(UserService::class, ['user' => $this->user]);

        // Backfill any settings/subscriptions that don't exist yet for this user.
        $userService->runProfileSettingsUpdate();
        $userService->runNotificationSubscriptionsUpdate();

        $this->notificationTypes = NotificationTypeEnum::forSelect();

        $subscriptions = $this->user->notificationSubscriptions;

        foreach ($this->notificationTypes as $type => $label) {
            $this->notifications[$type] = (bool) $subscriptions->firstWhere('notification_type', $type)?->status?->boolValue();
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

        foreach ($this->notifications as $type => $status) {
            $this->user->notificationSubscriptions()
                ->where('notification_type', $type)
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
                @foreach ($notificationTypes as $type => $label)
                    <flux:switch
                        wire:key="notification-{{ $type }}"
                        wire:model="notifications.{{ $type }}"
                        :label="$label"
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
