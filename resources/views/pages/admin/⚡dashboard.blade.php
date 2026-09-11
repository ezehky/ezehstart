<?php

use App\Enums\NotificationTopicEnum;
use App\Enums\StatusUser;
use App\Enums\UserTypeEnum;
use App\Models\User;
use App\Services\ActivityLogService;
use App\Services\AdminActionService;
use App\Traits\WithGateProps;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component
{
    use WithGateProps;

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
        $this->setPageGate('dashboard');

        $this->monthExpression = match (DB::connection()->getDriverName()) {
            'sqlite' => "strftime('%Y-%m', created_at)",
            'pgsql' => "to_char(created_at, 'YYYY-MM')",
            default => "date_format(created_at, '%Y-%m')",
        };
    }

    #[Computed]
    public function metrics(): array
    {
        $accountsCount = User::query()->count();
        $adminsCount = User::query()->admins()->count();
        $membersCount = User::query()->users()->count();
        $suspendedCount = User::query()->where('status', StatusUser::SUSPENDED)->count();
        $unverifiedCount = User::query()->whereNull('email_verified_at')->count();
        $strandedCount = User::query()->withoutLiveRole()->count();

        $trends = $this->signupTrends;

        return [
            'accounts' => [
                'label' => 'Total accounts',
                'value' => number_format($accountsCount),
                'icon' => 'users',
                'change' => 'Every registered account',
                'tone' => 'sky',
                'trend' => $trends['all'],
            ],
            'members' => [
                'label' => 'Members',
                'value' => number_format($membersCount),
                'icon' => 'user-group',
                'change' => 'Accounts in the member workspace',
                'tone' => 'emerald',
                'trend' => $trends['member'],
            ],
            'admins' => [
                'label' => 'Admins',
                'value' => number_format($adminsCount),
                'icon' => 'shield-check',
                'change' => 'Accounts with workspace access',
                'tone' => 'slate',
                'trend' => $trends['admin'],
            ],
            'stranded' => [
                'label' => 'Admins without a role',
                'value' => number_format($strandedCount),
                'icon' => 'user-plus',
                'change' => 'No live role, so nothing is reachable',
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
     * Sign-ups a month for the last six, split by which workspace the account
     * belongs to — the series the tiles draw under their figures.
     *
     * Every month in the window is present whether anybody signed up in it or not. A
     * gap is a zero, and a series with holes in it draws a line that climbs through
     * months that never happened.
     *
     * @return array<string, array<int, object>>
     */
    #[Computed]
    public function signupTrends(): array
    {
        $rows = User::query()
            ->selectRaw("{$this->monthExpression} as month, user_type, count(*) as total")
            ->where('created_at', '>=', now()->subMonths(5)->startOfMonth())
            ->groupBy('month', 'user_type')
            ->get();

        $months = collect(range(5, 0))->map(fn (int $back) => now()->subMonths($back)->format('Y-m'));

        $series = fn (?UserTypeEnum $type) => $months
            ->map(fn (string $month) => (object) [
                'month' => $month,
                'total' => (int) $rows
                    ->where('month', $month)
                    ->when($type, fn ($matched) => $matched->where('user_type', $type->value))
                    ->sum('total'),
            ])
            ->all();

        return [
            'all' => $series(null),
            'member' => $series(UserTypeEnum::USER),
            'admin' => $series(UserTypeEnum::ADMIN),
        ];
    }

    /**
     * Sign-ups over the last six months, handed to <x-chart> as rows.
     *
     * The scaling lives in the chart engine now, so this only has to produce the
     * numbers and the two labels the axis and tooltip read. Swap the model for
     * whatever your project actually counts — the shape of the query stays.
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

        return $result->map(fn ($item) => (object) [
            'month' => $item->month,
            'label' => Carbon::createFromFormat('Y-m', $item->month)->format('F Y'),
            'monthShort' => Carbon::createFromFormat('Y-m', $item->month)->format('M'),
            'total' => (int) $item->total,
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
                :trend="$metric['trend'] ?? null"
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
            @if ($this->signupsByMonth->isEmpty())
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

                        <x-chart.axis axis="x" field="monthShort">
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
