<?php

use App\Models\User;
use App\Services\ActivityLogService;
use App\Services\UserService;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component
{
    public User $user;

    public function mount(): void
    {
        $this->user = auth()->user();

        // Backfill any preference switch this account never had written, so the
        // settings pages always have a complete set to render.
        $service = app(UserService::class, ['user' => $this->user]);
        $service->runProfileSettingsUpdate();
        $service->runNotificationPreferencesUpdate();

        kSetSiteTitle('dashboard');
    }

    /**
     * The prompts an account still owes us, shown until each one is satisfied.
     *
     * @return array<int, array{label: string, description: string, icon: string, href: string, cta: string}>
     */
    #[Computed]
    public function todo(): array
    {
        $items = [];

        if (! $this->user->hasVerifiedEmail()) {
            $items[] = [
                'label' => 'Verify your email address',
                'description' => 'We sent a code to '.$this->user->email.'. Confirm it to secure your account.',
                'icon' => 'envelope',
                'href' => route('email.verification', ['user' => $this->user->email, 'send' => true]),
                'cta' => 'Verify',
            ];
        }

        if ($this->user->profileCompletion() < 100) {
            $items[] = [
                'label' => 'Finish your profile',
                'description' => 'Add a photo, a phone number and a short bio.',
                'icon' => 'user-circle',
                'href' => route('user.profile'),
                'cta' => 'Complete',
            ];
        }

        if (! $this->user->password) {
            $items[] = [
                'label' => 'Set a password',
                'description' => 'You sign in with an emailed code. A password gives you a second way in.',
                'icon' => 'key',
                'href' => route('user.security-settings'),
                'cta' => 'Set one',
            ];
        }

        return $items;
    }

    #[Computed]
    public function recentActivities(): Collection
    {
        return app(ActivityLogService::class)->getActivityLogsForUser($this->user);
    }
};
?>

<div class="space-y-7">
    <section class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
        <div>
            <p class="text-sm font-semibold text-emerald-700 dark:text-emerald-300">Your workspace</p>
            <h1 class="mt-1 font-heading text-3xl font-bold tracking-tight text-slate-950 dark:text-white">
                {{ kGreeting($user->firstName(), false) }}.
            </h1>
            <p class="mt-2 text-sm leading-6 text-slate-600 dark:text-slate-400">
                This is your starting point. Build the rest of it here.
            </p>
        </div>
        <flux:button variant="primary" icon="user-circle" :href="route('user.profile')" wire:navigate class="press w-full sm:w-auto">
            View profile
        </flux:button>
    </section>

    <section class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4" aria-label="Account overview">
        <x-dashboard.stat-card
            label="Profile completion"
            :value="$user->profileCompletion().'%'"
            icon="chart-pie"
            tone="emerald"
            change="Fill in the gaps on your profile"
        />
        <x-dashboard.stat-card
            label="Email"
            :value="$user->hasVerifiedEmail() ? 'Verified' : 'Unverified'"
            icon="envelope"
            :tone="$user->hasVerifiedEmail() ? 'emerald' : 'amber'"
            :change="$user->email"
        />
        <x-dashboard.stat-card
            label="Member since"
            :value="$user->createdAtHuman()"
            icon="calendar-days"
            tone="sky"
            change="The day you signed up"
        />
        <x-dashboard.stat-card
            label="Timezone"
            :value="$user->timezone"
            icon="globe-alt"
            tone="slate"
            change="Every time on this site is shown in it"
        />
    </section>

    <section class="grid gap-6 xl:grid-cols-[minmax(0,1.1fr)_minmax(340px,0.9fr)]">
        <flux:card class="space-y-5">
            <div>
                <flux:heading level="2" size="lg" class="font-heading font-bold">Next steps</flux:heading>
                <flux:text class="mt-1 text-sm">Things worth doing before you go any further.</flux:text>
            </div>

            <div class="divide-y divide-slate-100 dark:divide-slate-800">
                @forelse ($this->todo as $item)
                    <div class="flex items-start justify-between gap-4 py-4 first:pt-0 last:pb-0" wire:key="todo-{{ $loop->index }}">
                        <div class="flex items-start gap-3">
                            <x-dashboard.icon-box size="sm" :icon="$item['icon']" tone="amber" />
                            <div class="min-w-0">
                                <p class="font-medium text-slate-900 dark:text-white">{{ $item['label'] }}</p>
                                <p class="mt-0.5 text-sm text-slate-600 dark:text-slate-400">{{ $item['description'] }}</p>
                            </div>
                        </div>

                        <flux:button variant="ghost" size="sm" icon="arrow-right" :href="$item['href']" wire:navigate>
                            {{ $item['cta'] }}
                        </flux:button>
                    </div>
                @empty
                    <div class="py-10 text-center text-sm text-slate-500 dark:text-slate-400">
                        Your account is fully set up. Nothing to do here.
                    </div>
                @endforelse
            </div>
        </flux:card>

        <flux:card>
            <div class="flex items-start justify-between gap-4">
                <div>
                    <flux:heading level="2" size="lg" class="font-heading font-bold">Your activity</flux:heading>
                    <flux:text class="mt-1 text-sm">A record of what has happened on your account.</flux:text>
                </div>
            </div>
            <div class="mt-5 divide-y divide-slate-100 dark:divide-slate-800">
                @forelse ($this->recentActivities as $activity)
                    <div class="py-3 first:pt-0 last:pb-0" wire:key="activity-{{ $activity->id }}">
                        <p class="text-sm leading-5 text-slate-700 dark:text-slate-200">{{ $activity->description }}</p>
                        <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">{{ $activity->createdAtDiffForHumans() }}</p>
                    </div>
                @empty
                    <div class="py-10 text-center text-sm text-slate-500 dark:text-slate-400">
                        Nothing has been recorded yet.
                    </div>
                @endforelse
            </div>
        </flux:card>
    </section>
</div>
