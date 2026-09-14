<?php

use App\Enums\NotificationTopicEnum;
use App\Enums\StatusUser;
use App\Enums\UserTypeEnum;
use App\Models\User;
use App\Services\ActivityLogService;
use App\Services\AdminActionService;
use App\Services\TrendService;
use App\Traits\WithGateProps;
use App\Traits\WithMetrics;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component
{
    use WithGateProps, WithMetrics;

    public User $user;

    public function mount(): void
    {
        $this->user = auth()->user();
        kSetSiteTitle('dashboard');
        $this->setPageGate('dashboard');
    }

    #[Computed]
    public function metrics(): array
    {
        // registered() throughout: a newsletter address is a users row with no
        // account behind it, and counting one as an account would make every
        // number here a count of the mailing list instead.
        $accountsCount = User::query()->registered()->count();
        $adminsCount = User::query()->admins()->count();
        $membersCount = User::query()->users()->count();
        $suspendedCount = User::query()->where('status', StatusUser::SUSPENDED)->count();
        $unverifiedCount = User::query()->registered()->whereNull('email_verified_at')->count();
        $strandedCount = User::query()->withoutLiveRole()->count();

        $trends = $this->signupTrends;

        return [
            'accounts' => $this->metricMaker(
                'Total accounts',
                $accountsCount,
                'users',
                tone: 'sky',
                change: 'Every registered account',
                trend: $trends['all'],
            ),
            'members' => $this->metricMaker(
                'Members',
                $membersCount,
                'user-group',
                tone: 'emerald',
                change: 'Accounts in the member workspace',
                trend: $trends['member'],
            ),
            'admins' => $this->metricMaker(
                'Admins',
                $adminsCount,
                'shield-check',
                change: 'Accounts with workspace access',
                trend: $trends['admin'],
            ),
            'stranded' => $this->metricMaker(
                'Admins without a role',
                $strandedCount,
                'user-plus',
                tone: 'amber',
                change: 'No live role, so nothing is reachable',
            ),
            'unverified' => $this->metricMaker(
                'Unverified email',
                $unverifiedCount,
                'envelope',
                tone: 'amber',
                change: 'Never confirmed their address',
            ),
            'suspended' => $this->metricMaker(
                'Suspended',
                $suspendedCount,
                'lock-closed',
                change: 'Blocked from signing in',
            ),
        ];
    }

    /**
     * Sign-ups a month for the last six, split by which workspace the account
     * belongs to — the series the tiles draw under their figures.
     *
     * One grouped read covers all three, and every month in the window is present
     * whether anybody signed up in it or not — see TrendService.
     *
     * @return array<string, array<int, object>>
     */
    #[Computed]
    public function signupTrends(): array
    {
        return app(TrendService::class)->trends(
            // Sign-ups, not sign-ups plus newsletter addresses — the same
            // exclusion the counts above make.
            User::query()->registered(),
            splitBy: ['user_type'],
            series: [
                'all' => [],
                'member' => ['match' => ['user_type' => UserTypeEnum::USER]],
                'admin' => ['match' => ['user_type' => UserTypeEnum::ADMIN]],
            ],
        );
    }

    /**
     * Sign-ups over the last six months, handed to <x-chart> as rows.
     *
     * The same series the tiles draw, at chart size. Each point already carries the
     * two labels the axis and the tooltip read, so this only has to name the model.
     * Swap it for whatever your project actually counts.
     *
     * @return array<int, object>
     */
    #[Computed]
    public function signupsByMonth(): array
    {
        return app(TrendService::class)->trend(User::query());
    }

    /**
     * Work still waiting on an admin, read from the records rather than from
     * notifications — an item stays until it is actually dealt with.
     */
    #[Computed]
    public function pendingItems(): Collection
    {
        return app(AdminActionService::class)->pendingItems();
    }

    #[Computed]
    public function recentActivities(): Collection
    {
        return app(ActivityLogService::class)->getActivityLogsForUser(auth()->user());
    }

    /**
     * A topic names its colour in the bell menu's vocabulary; x-dashboard.icon-box
     * speaks the Tailwind palette. Translate rather than let it fall back silently.
     */
    public function tone(NotificationTopicEnum $topic): string
    {
        return match ($topic->color()) {
            'green' => 'emerald',
            'red' => 'rose',
            'blue' => 'sky',
            'amber' => 'amber',
            default => 'slate',
        };
    }
};
?>

<div class="space-y-7">
    <section class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
        <div>
            <p class="text-sm font-semibold text-emerald-700 dark:text-emerald-300">Administration</p>
            <h1 class="mt-1 font-heading text-3xl font-bold tracking-tight text-slate-950 dark:text-white">
                {{ kGreeting(str($user->name)->before(' '), false) }}.
            </h1>
            <p class="mt-2 text-sm leading-6 text-slate-600 dark:text-slate-400">Here is the state of your workspace today.</p>
        </div>
        <flux:button variant="primary" icon="user-plus" :href="route('admin.admins')" wire:navigate class="press w-full sm:w-auto">
            Add user
        </flux:button>
    </section>

    <section class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3" aria-label="Platform metrics">
        @foreach ($this->metrics as $metric)
            <x-dashboard.stat-card :metric="$metric" />
        @endforeach
    </section>

    <section class="grid gap-6 xl:grid-cols-[minmax(0,1.5fr)_minmax(340px,0.85fr)]">
        <flux:card class="space-y-6">
            <div class="flex items-start justify-between gap-4">
                <div>
                    <flux:heading level="2" size="lg" class="font-heading font-bold">Sign-ups</flux:heading>
                    <flux:text class="mt-1 text-sm">
                        New accounts over the last six months.
                    </flux:text>
                </div>
                <flux:badge color="lime" size="sm">Live data</flux:badge>
            </div>
            {{-- The window is padded, so the series is never empty — it is flat. Six
                 zeroed bars say less than a sentence does. --}}
            @if (collect($this->signupsByMonth)->sum('total') === 0)
                <div class="mt-7 grid h-52 place-items-center rounded-lg border border-dashed border-slate-200 text-sm text-slate-500 dark:border-slate-700 dark:text-slate-400">
                    Sign-ups will appear as accounts are created.
                </div>
            @else
                {{-- The gutter reserves the room the axis ticks are drawn into: 28px under the
                     chart for the month labels, 40px to the left for the counts. --}}
                <x-chart :value="$this->signupsByMonth" gutter="8 8 28 40" class="mt-7 h-52">
                    <x-chart.svg>
                        <x-chart.axis axis="y" :tick-count="4">
                            <x-chart.axis.grid />
                            <x-chart.axis.tick />
                        </x-chart.axis>

                        <x-chart.axis axis="x" field="short">
                            <x-chart.axis.line />
                            <x-chart.axis.tick />
                        </x-chart.axis>

                        <x-chart.cursor type="area" />
                        <x-chart.bar field="total" />
                    </x-chart.svg>

                    <x-chart.tooltip>
                        <x-chart.tooltip.heading field="label" />
                        <x-chart.tooltip.value field="total" label="Sign-ups" />
                    </x-chart.tooltip>
                </x-chart>
            @endif
        </flux:card>

        <flux:card>
            <div class="flex items-start justify-between gap-4">
                <div>
                    <flux:heading level="2" size="lg" class="font-heading font-bold">Recent activity</flux:heading>
                    <flux:text class="mt-1 text-sm">Latest audit events across the workspace.</flux:text>
                </div>
                <flux:button
                    variant="ghost"
                    size="sm"
                    icon="arrow-top-right-on-square"
                    :href="route('admin.activity-logs')"
                    wire:navigate
                    aria-label="View activity logs"
                />
            </div>
            <div class="mt-5 divide-y divide-slate-100 dark:divide-slate-800">
                @forelse ($this->recentActivities as $activity)
                    <div class="flex gap-3 py-3 first:pt-0 last:pb-0" wire:key="activity-{{ $activity->id }}">
                        <x-dashboard.avatar :user="$activity->user" class="size-8 text-[10px]" />
                        <div class="min-w-0">
                            <p class="text-sm leading-5 text-slate-700 dark:text-slate-200"><span class="font-semibold">
                                {{ $activity->user->name }}</span> {{ $activity->description }}
                            </p>
                            <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">{{ $activity->createdAtDiffForHumans() }}</p>
                        </div>
                    </div>
                @empty
                    <div class="py-10 text-center text-sm text-slate-500 dark:text-slate-400">No activity has been recorded yet.</div>
                @endforelse
            </div>
        </flux:card>
    </section>

    <flux:card class="space-y-6">
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
            <div>
                <flux:heading level="2" size="xl">Waiting on you</flux:heading>
                <flux:text class="mt-2">Records that stay here until somebody deals with them.</flux:text>
            </div>
        </div>

        <div class="divide-y divide-slate-100 dark:divide-slate-800">
            @forelse ($this->pendingItems as $item)
                <div class="flex items-start justify-between gap-4 py-4 first:pt-0 last:pb-0" wire:key="pending-{{ $loop->index }}">
                    <div class="flex items-start gap-3">
                        <x-dashboard.icon-box size="sm" :icon="$item['topic']->icon()" :tone="$this->tone($item['topic'])" />
                        <div class="min-w-0">
                            <p class="font-medium text-slate-900 dark:text-white">{{ $item['topic']->label() }}</p>
                            <p class="mt-0.5 text-sm text-slate-600 dark:text-slate-400">{{ $item['message'] }}</p>
                            @if ($item['since'])
                                <p class="mt-1 text-xs text-slate-500 dark:text-slate-500">
                                    Oldest {{ $item['since']->diffForHumans() }}
                                </p>
                            @endif
                        </div>
                    </div>

                    <flux:button variant="ghost" size="sm" icon="arrow-right" :href="$item['url']" wire:navigate>
                        Open
                    </flux:button>
                </div>
            @empty
                <div class="py-10 text-center text-sm text-slate-500 dark:text-slate-400">
                    Nothing is waiting. Everything has been dealt with.
                </div>
            @endforelse
        </div>
    </flux:card>
</div>
