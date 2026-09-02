<?php

use App\Enums\NotificationTopicEnum;
use App\Models\User;
use App\Services\AdminActionService;
use App\Services\NotificationService;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component
{
    /**
     * How many entries the bell menu holds before older ones drop off.
     */
    public int $limit = 10;

    /**
     * How many outstanding action items the menu lists before summarising the rest.
     */
    public int $actionLimit = 6;

    #[Computed]
    public function user(): ?User
    {
        return auth()->user();
    }

    #[Computed]
    public function isAdmin(): bool
    {
        return (bool) $this->user?->isAdmin();
    }

    /**
     * Work waiting on an admin, read from the records rather than the
     * notification log, so it stays put until somebody deals with it.
     *
     * @return Collection<int, array<string, mixed>>
     */
    #[Computed]
    public function pendingActions(): Collection
    {
        // The counts are cheap and usually zero, so they decide whether the
        // heavier listing queries are worth running at all.
        return $this->pendingCount
            ? app(AdminActionService::class)->pendingItems($this->actionLimit)
            : collect();
    }

    #[Computed]
    public function pendingCount(): int
    {
        return $this->isAdmin
            ? app(AdminActionService::class)->pendingCount()
            : 0;
    }

    /**
     * What the bell badge reports: everything unseen or unresolved.
     */
    #[Computed]
    public function badgeCount(): int
    {
        return $this->unreadCount + $this->pendingCount;
    }

    /**
     * @return Collection<int, DatabaseNotification>
     */
    #[Computed]
    public function notifications(): Collection
    {
        return $this->user
            ? app(NotificationService::class)->recentFor($this->user, $this->limit)
            : collect();
    }

    #[Computed]
    public function unreadCount(): int
    {
        return $this->user
            ? app(NotificationService::class)->unreadCountFor($this->user)
            : 0;
    }

    /**
     * Open an entry: it is read from here on, and it takes the reader to
     * whatever it is about when it carries a link.
     */
    public function openNotification(string $notificationId): void
    {
        if (! $this->user) {
            return;
        }

        $notification = app(NotificationService::class)->markAsRead($this->user, $notificationId);

        unset($this->notifications, $this->unreadCount);

        if ($url = data_get($notification?->data, 'data.url')) {
            $this->redirect($url, navigate: true);
        }
    }

    public function markAllAsRead(): void
    {
        if (! $this->user) {
            return;
        }

        app(NotificationService::class)->markAllAsRead($this->user);

        unset($this->notifications, $this->unreadCount);
    }

    public function clearAll(): void
    {
        if (! $this->user) {
            return;
        }

        app(NotificationService::class)->clearFor($this->user);

        unset($this->notifications, $this->unreadCount);
    }

    /**
     * The topic an entry was raised under, or null when it predates the topics.
     */
    public function topic(DatabaseNotification $notification): ?NotificationTopicEnum
    {
        return NotificationTopicEnum::fromTitle(data_get($notification->data, 'title'));
    }

    /**
     * Money is stored as an HTML entity, so decode it before the view escapes
     * the message for output.
     */
    public function message(DatabaseNotification $notification): string
    {
        return html_entity_decode((string) data_get($notification->data, 'message'));
    }

    /**
     * The icon tint for a topic. Written out in full so Tailwind sees the classes.
     */
    public function tint(?NotificationTopicEnum $topic): string
    {
        return match ($topic?->color()) {
            'green' => 'bg-emerald-50 text-emerald-600 dark:bg-emerald-500/10 dark:text-emerald-400',
            'red' => 'bg-rose-50 text-rose-600 dark:bg-rose-500/10 dark:text-rose-400',
            'amber' => 'bg-amber-50 text-amber-600 dark:bg-amber-500/10 dark:text-amber-400',
            'blue' => 'bg-blue-50 text-blue-600 dark:bg-blue-500/10 dark:text-blue-400',
            default => 'bg-slate-100 text-slate-500 dark:bg-slate-800 dark:text-slate-400',
        };
    }
};
?>
{{-- wire:poll.60s.visible --}}
<div class="relative" x-data="{ open: false }" >
    <button
        type="button"
        class="press relative rounded-md p-2 text-slate-600 transition-colors duration-150 ease-out hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-slate-900"
        aria-label="Notifications"
        :aria-expanded="open"
        @click="open = ! open"
    >
        <flux:icon name="bell" class="size-5" />

        @if ($this->badgeCount)
            <span
                class="absolute -right-0.5 -top-0.5 inline-flex min-w-4 items-center justify-center rounded-full px-1 text-[10px] font-semibold leading-4 text-white {{ $this->pendingCount ? 'bg-amber-500' : 'bg-rose-500' }}"
                aria-hidden="true"
            >
                {{ $this->badgeCount > 9 ? '9+' : $this->badgeCount }}
            </span>
            <span class="sr-only">
                {{ $this->unreadCount }} unread{{ $this->pendingCount ? ", {$this->pendingCount} awaiting action" : '' }}
            </span>
        @endif
    </button>

    <div
        x-cloak
        x-show="open"
        x-transition.origin.top.right
        @click.outside="open = false"
        @keydown.escape.window="open = false"
        class="absolute right-0 z-50 mt-2 w-[min(22rem,calc(100vw-2rem))] overflow-hidden rounded-xl border border-slate-200 bg-white shadow-xl dark:border-slate-800 dark:bg-slate-900"
        role="dialog"
        aria-label="Notifications"
    >
        <div class="flex items-center justify-between gap-3 border-b border-slate-200 px-4 py-3 dark:border-slate-800">
            <div class="flex items-center gap-2">
                <flux:heading size="sm">Notifications</flux:heading>
                @if ($this->pendingCount)
                    <flux:badge size="sm" color="amber">{{ $this->pendingCount }} to action</flux:badge>
                @endif
                @if ($this->unreadCount)
                    <flux:badge size="sm" color="rose">{{ $this->unreadCount }} new</flux:badge>
                @endif
            </div>

            @if ($this->unreadCount)
                <flux:button variant="ghost" size="xs" wire:click="markAllAsRead">Mark all read</flux:button>
            @endif
        </div>

        <div class="max-h-96 overflow-y-auto">
            {{-- Outstanding work, which stays here until the record itself is dealt with --}}
            @if ($this->pendingActions->isNotEmpty())
                <div class="bg-amber-50/40 dark:bg-amber-500/5">
                    <p class="px-4 pt-3 text-[11px] font-semibold uppercase tracking-wide text-amber-700 dark:text-amber-400">
                        Needs your action
                    </p>

                    <div class="divide-y divide-slate-100 dark:divide-slate-800">
                        @foreach ($this->pendingActions as $action)
                            <a
                                href="{{ $action['url'] }}"
                                wire:navigate
                                wire:key="action-{{ $loop->index }}"
                                class="flex items-start gap-3 px-4 py-3 transition-colors duration-150 ease-out hover:bg-amber-100/50 dark:hover:bg-amber-500/10"
                            >
                                <span class="mt-0.5 flex size-8 shrink-0 items-center justify-center rounded-full {{ $this->tint($action['topic']) }}">
                                    <flux:icon name="{{ $action['topic']->icon() }}" class="size-4" />
                                </span>

                                <span class="min-w-0 flex-1">
                                    <span class="block truncate text-sm font-medium text-slate-950 dark:text-white">
                                        {{ $action['topic']->label() }}
                                    </span>
                                    <span class="mt-0.5 block text-xs text-slate-600 dark:text-slate-300">
                                        {{ $action['message'] }}
                                    </span>
                                    <span class="mt-1 block text-[11px] text-amber-700 dark:text-amber-400">
                                        Waiting {{ kDatetimeConverter($action['since'], auth()->user(), diffForHumans: true) }}
                                    </span>
                                </span>

                                <flux:icon name="chevron-right" class="mt-2 size-4 shrink-0 text-slate-400" />
                            </a>
                        @endforeach
                    </div>

                    @if ($this->pendingCount > $this->pendingActions->count())
                        <p class="px-4 py-2 text-[11px] text-amber-700 dark:text-amber-400">
                            and {{ $this->pendingCount - $this->pendingActions->count() }} more waiting.
                        </p>
                    @endif
                </div>
            @endif

            <div class="divide-y divide-slate-100 dark:divide-slate-800">
                @if ($this->pendingActions->isNotEmpty() && $this->notifications->isNotEmpty())
                    <p class="px-4 pt-3 text-[11px] font-semibold uppercase tracking-wide text-slate-400">Recent</p>
                @endif

                @forelse ($this->notifications as $item)
                    @php($topic = $this->topic($item))

                    <button
                        type="button"
                        wire:key="notification-{{ $item->id }}"
                        wire:click="openNotification('{{ $item->id }}')"
                        class="flex w-full items-start gap-3 px-4 py-3 text-left transition-colors duration-150 ease-out hover:bg-slate-50 dark:hover:bg-slate-800/60 {{ $item->read_at ? '' : 'bg-slate-50/70 dark:bg-slate-800/40' }}"
                    >
                        <span class="mt-0.5 flex size-8 shrink-0 items-center justify-center rounded-full {{ $this->tint($topic) }}">
                            <flux:icon name="{{ $topic?->icon() ?? 'bell' }}" class="size-4" />
                        </span>

                        <span class="min-w-0 flex-1">
                            <span class="flex items-center gap-2">
                                <span class="truncate text-sm font-medium text-slate-950 dark:text-white">
                                    {{ $topic?->label() ?? kBreakText(data_get($item->data, 'title')) ?? 'Notification' }}
                                </span>
                                @unless ($item->read_at)
                                    <span class="size-1.5 shrink-0 rounded-full bg-rose-500" aria-hidden="true"></span>
                                @endunless
                            </span>
                            <span class="mt-0.5 block text-xs text-slate-500 dark:text-slate-400">
                                {{ $this->message($item) }}
                            </span>
                            <span class="mt-1 block text-[11px] text-slate-400">
                                {{ kDatetimeConverter($item->created_at, auth()->user(), diffForHumans: true) }}
                            </span>
                        </span>
                    </button>
                @empty
                    @if ($this->pendingActions->isEmpty())
                        <x-dashboard.workspace-no-record
                            label="No notifications"
                            icon="bell-slash"
                            text="Updates on your payments, enrollments and refunds land here."
                            class="px-4 py-8"
                        />
                    @endif
                @endforelse
            </div>
        </div>

        @if ($this->notifications->isNotEmpty())
            <div class="flex justify-end border-t border-slate-200 px-4 py-2 dark:border-slate-800">
                <flux:button variant="ghost" size="xs" icon="trash" wire:click="clearAll">Clear all</flux:button>
            </div>
        @endif
    </div>
</div>
