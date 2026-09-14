<?php

use App\Models\NotificationType;
use App\Models\User;
use App\Services\NewsletterService;
use App\Services\UserService;
use App\Traits\WithFormResponseMessage;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Where the unsubscribe link in an email lands.
 *
 * The newsletter is already off by the time this paints — one click is the whole
 * promise of that link, and making somebody confirm it on arrival is the thing
 * the link exists to avoid. The checkboxes are for putting something back, or for
 * taking the rest off as well, by a person who is already here.
 *
 * Reached through a signed URL with no session behind it, so the account is the
 * one bound from the link rather than auth()->user(): the recipient may never
 * have had an account, or may be signed in as somebody else on that browser.
 */
new #[Layout('layouts::site')] class extends Component
{
    use WithFormResponseMessage;

    public User $user;

    /** @var array<int, bool> keyed by notification type id */
    public array $preferences = [];

    /** @var array<int, array{title: string, description: string|null}> keyed by notification type id */
    public array $notificationTypes = [];

    public function mount(User $user): void
    {
        $this->user = $user;

        app(NewsletterService::class)->unsubscribe($user->email);

        $this->loadPreferences();

        kSetSiteTitle('Unsubscribed');
    }

    protected function rules(): array
    {
        return [
            'preferences.*' => ['boolean'],
        ];
    }

    public function save(): void
    {
        $this->validate();

        foreach ($this->preferences as $typeId => $status) {
            $this->user->notificationPreferences()
                ->where('notification_type_id', $typeId)
                ->update(['status' => $status]);
        }

        $this->loadPreferences();

        $this->respondSuccess('Your email preferences have been saved.');
    }

    /**
     * Every switch off in one go. Somebody who wanted the whole lot stopped should
     * not have to clear the boxes one at a time to say so.
     */
    public function unsubscribeAll(): void
    {
        $this->preferences = array_fill_keys(array_keys($this->preferences), false);

        $this->save();
    }

    /**
     * The switches this account carries, as the settings screen renders them.
     *
     * Read from the rows rather than from NotificationTypeEnum: a type is a row an
     * administrator can add without a deploy, and only the active ones are still
     * on offer — a retired type keeps its preferences until it is deleted, and
     * neither should appear as a box to tick in the meantime.
     */
    private function loadPreferences(): void
    {
        // A subscriber row created before a type existed has no switch for it yet,
        // and a missing switch would render as an unchecked box that saves nothing.
        app(UserService::class, ['user' => $this->user])->runNotificationPreferencesUpdate();

        $types = NotificationType::query()->active()->inFlowOrder()->get();
        $saved = $this->user->notificationPreferences()->get();

        $this->notificationTypes = [];
        $this->preferences = [];

        foreach ($types as $type) {
            $this->notificationTypes[$type->id] = [
                'title' => $type->title,
                'description' => $type->description,
            ];

            $this->preferences[$type->id] = (bool) $saved
                ->firstWhere('notification_type_id', $type->id)?->status?->boolValue();
        }
    }
};
?>

<div class="mx-auto flex w-full max-w-3xl flex-col px-6 py-16">
    <x-dashboard.icon-box icon="check-circle" tone="emerald" />

    <h1 class="mt-5 font-heading text-3xl font-bold tracking-tight dark:text-white">
        You are unsubscribed
    </h1>

    <flux:text class="mt-3 text-lg dark:text-slate-400">
        We will not send any more newsletters to <strong>{{ $user->email }}</strong>. Account
        emails — sign-in codes, security alerts and anything about a change you made —
        are not part of this and still go out.
    </flux:text>

    @if ($notificationTypes)
        <form wire:submit="save" class="mt-8">
            <flux:card class="space-y-6">
                <div>
                    <flux:heading level="2" size="sm">What you still hear from us</flux:heading>
                    <flux:text class="mt-1">
                        Tick anything you would like to keep, or clear the lot.
                    </flux:text>
                </div>

                <flux:checkbox.group class="space-y-4">
                    @foreach ($notificationTypes as $typeId => $type)
                        <flux:checkbox
                            wire:key="preference-{{ $typeId }}"
                            wire:model="preferences.{{ $typeId }}"
                            :label="$type['title']"
                            :description="$type['description']"
                        />
                    @endforeach
                </flux:checkbox.group>

                <flux:separator variant="subtle" />

                <div class="flex flex-wrap items-center gap-3">
                    <flux:button type="submit" variant="primary">Save preferences</flux:button>

                    <flux:button type="button" wire:click="unsubscribeAll" variant="subtle">
                        Unsubscribe from everything
                    </flux:button>
                </div>
            </flux:card>
        </form>
    @endif

    <div class="mt-8">
        <flux:button href="{{ route('home') }}" variant="ghost" icon="arrow-left">
            Back to the site
        </flux:button>
    </div>
</div>
