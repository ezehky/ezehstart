<?php

use App\Enums\NotificationTopicEnum;
use App\Enums\StatusUser;
use App\Enums\UserRoleEnum;
use App\Models\User;
use App\Services\ActivityLogService;
use App\Services\AdminActionService;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component
{
    public User $user;

    /**
     * Grouping by month is the one place raw SQL is unavoidable, and every driver
     * spells it differently. Resolved once here rather than in each query.
     */
    public string $monthExpression;

    public function mount(): void
    {
        $this->user = auth()->user();
        kSetSiteTitle('dashboard');

        $this->monthExpression = match (DB::connection()->getDriverName()) {
            'sqlite' => "strftime('%Y-%m', created_at)",
            'pgsql' => "to_char(created_at, 'YYYY-MM')",
            default => "date_format(created_at, '%Y-%m')",
        };
    }

    #[Computed]
    public function metrics(): array
    {
        $usersCount = User::query()->count();
        $adminsCount = User::query()->carriesRole(UserRoleEnum::ADMIN)->count();
        $membersCount = User::query()->carriesRole(UserRoleEnum::USER)->count();
        $suspendedCount = User::query()->where('status', StatusUser::SUSPENDED)->count();
        $unverifiedCount = User::query()->whereNull('email_verified_at')->count();
        $unassignedCount = User::query()->carriesNoRole()->count();

        return [
            'users' => [
                'label' => 'Total users',
                'value' => number_format($usersCount),
                'icon' => 'users',
                'change' => 'All registered accounts',
                'tone' => 'sky',
            ],
            'members' => [
                'label' => 'Members',
                'value' => number_format($membersCount),
                'icon' => 'user-group',
                'change' => 'Accounts carrying the member role',
                'tone' => 'emerald',
            ],
            'admins' => [
                'label' => 'Admins',
                'value' => number_format($adminsCount),
                'icon' => 'shield-check',
                'change' => 'Accounts with workspace access',
                'tone' => 'slate',
            ],
            'unassigned' => [
                'label' => 'Unassigned',
                'value' => number_format($unassignedCount),
                'icon' => 'user-plus',
                'change' => 'Holding no role, so blocked everywhere',
                'tone' => 'amber',
            ],
            'unverified' => [
                'label' => 'Unverified email',
                'value' => number_format($unverifiedCount),
                'icon' => 'envelope',
                'change' => 'Never confirmed their address',
                'tone' => 'amber',
            ],
            'suspended' => [
                'label' => 'Suspended',
                'value' => number_format($suspendedCount),
                'icon' => 'lock-closed',
                'change' => 'Blocked from signing in',
                'tone' => 'slate',
            ],
        ];
    }

    /**
     * Sign-ups over the last six months, scaled so the tallest bar fills the chart.
     *
     * Swap the model for whatever your project actually counts — the shape of the
     * query and the bar markup stay as they are.
     */
    #[Computed]
    public function signupsByMonth(): Collection
    {
        $result = User::query()
            ->selectRaw("{$this->monthExpression} as month, count(*) as total")
            ->where('created_at', '>=', now()->subMonths(5)->startOfMonth())
            ->groupBy('month')
            ->orderBy('month')
            ->get();

        $highest = max(1, (int) $result->max('total'));

        return $result->map(fn ($item) => (object) [
            'month' => $item->month,
            'label' => Carbon::createFromFormat('Y-m', $item->month)->format('F Y'),
            'monthShort' => Carbon::createFromFormat('Y-m', $item->month)->format('M'),
            'total' => (int) $item->total,
            'formattedTotal' => number_format($item->total),
            'heightPercentage' => max(4, round(($item->total / $highest) * 100)),
        ]);
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
            <x-dashboard.stat-card
                :label="$metric['label']"
                :value="$metric['value']"
                :icon="$metric['icon']"
                :change="$metric['change']"
                :tone="$metric['tone']"
            />
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
            <div class="mt-7 grid h-52 grid-cols-6 items-end gap-3" role="img" aria-label="Monthly sign-up bar chart">
                @forelse ($this->signupsByMonth as $month)
                    <div class="flex h-full min-w-0 flex-col justify-end gap-3">
                        <span class="sr-only">
                            {{ $month->label }}: {{ $month->formattedTotal }}
                        </span>
                        <div
                            class="min-h-1 rounded-t-md bg-emerald-500/85 transition-[height] duration-200 ease-out-strong dark:bg-lime-400"
                            style="height: {{ $month->heightPercentage }}%"
                        ></div>
                        <span class="truncate text-center text-xs font-medium text-slate-500 dark:text-slate-400">
                            {{ $month->monthShort }}
                        </span>
                    </div>
                @empty
                    <div class="col-span-6 grid h-full place-items-center rounded-lg border border-dashed border-slate-200 text-sm text-slate-500 dark:border-slate-700 dark:text-slate-400">
                        Sign-ups will appear as accounts are created.
                    </div>
                @endforelse
            </div>
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
