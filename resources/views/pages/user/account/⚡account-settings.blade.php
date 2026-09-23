<?php

use App\Enums\LocaleEnum;
use App\Models\Currency;
use App\Models\NotificationType;
use App\Models\User;
use App\Services\CurrencyService;
use App\Services\ImpersonationService;
use App\Services\LocaleService;
use App\Services\UserService;
use App\Traits\WithFormResponseMessage;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component
{
    use WithFormResponseMessage;

    public User $user;

    public array $notifications = [];

    /** @var array<int, array{title: string, description: string|null}> keyed by notification type id */
    public array $notificationTypes = [];

    /** The currency amounts are read in, as an id. The site default's id stands for "default". */
    public ?int $currency_id = null;

    public string $locale = 'en';

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

        $this->currency_id = app(CurrencyService::class)->forUser($this->user)['id'];
        $this->locale = app(LocaleService::class)->current()->value;

        kSetSiteTitle('profile', 'settings');
    }

    /**
     * @return \Illuminate\Support\Collection<int, array>
     */
    #[Computed]
    public function currencies()
    {
        return app(CurrencyService::class)->active();
    }

    /**
     * @return array<int, LocaleEnum>
     */
    #[Computed]
    public function locales(): array
    {
        return app(LocaleService::class)->isSwitcherEnabled() ? LocaleEnum::available() : [];
    }

    protected function rules(): array
    {
        return [
            'notifications.*' => ['boolean'],
            // Only a currency that is switched on — the list offers nothing else,
            // and a posted id for one that is off must not slip through.
            'currency_id' => ['nullable', Rule::in($this->currencies->keys()->all())],
            'locale' => ['required', Rule::in(array_map(fn (LocaleEnum $locale) => $locale->value, LocaleEnum::available()))],
        ];
    }

    public function save()
    {
        $this->validate();

        // The currency and language are saved on the account, which an
        // administrator viewing it as somebody else has no business changing. The
        // notification switches were always open to them, and stay that way.
        $localeChanged = false;

        if (! app(ImpersonationService::class)->isImpersonating()) {
            $currency = $this->currency_id ? Currency::query()->find($this->currency_id) : null;
            app(CurrencyService::class)->setForUser($this->user, $currency);

            // A new language changes every string on the page, including the
            // layout's, so the screen is reloaded rather than re-rendered.
            $localeChanged = $this->locale !== app(LocaleService::class)->current()->value;

            if ($localeChanged && $this->locales !== []) {
                app(LocaleService::class)->choose(LocaleEnum::from($this->locale), $this->user);
            }
        }

        foreach ($this->notifications as $typeId => $status) {
            $this->user->notificationPreferences()
                ->where('notification_type_id', $typeId)
                ->update(['status' => $status]);
        }

        $this->respondSuccess(__('Your preferences have been saved.'));

        // Amounts on this page and every other are converted on render, so a
        // reload is what shows them in the new currency too.
        return $this->redirectRoute('user.account-settings', navigate: ! $localeChanged);
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

            <div class="grid gap-6 sm:grid-cols-2">
                @if ($this->locales !== [])
                    <flux:select wire:model="locale" :label="__('Language')" :description="__('Messages we send you are written in it too.')">
                        @foreach ($this->locales as $option)
                            <flux:select.option :value="$option->value">{{ $option->flag() }} {{ $option->label() }}</flux:select.option>
                        @endforeach
                    </flux:select>
                @endif

                @if ($this->currencies->count() > 1)
                    <flux:select wire:model="currency_id" :label="__('Currency')" :description="__('Amounts are converted at the site\'s current rates.')">
                        @foreach ($this->currencies as $currency)
                            <flux:select.option :value="$currency['id']">{{ html_entity_decode($currency['symbol']) }} {{ $currency['name'] }} ({{ $currency['code'] }})</flux:select.option>
                        @endforeach
                    </flux:select>
                @endif
            </div>

            <div class="flex justify-end">
                <flux:button type="submit" variant="primary" icon="check">Save preferences</flux:button>
            </div>
        </flux:card>
    </form>
</div>
