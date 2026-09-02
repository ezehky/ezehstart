<?php

namespace App\Services;

use App\Enums\NotificationTopicEnum;
use App\Models\User;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * The work waiting on an admin, read live from the records themselves.
 *
 * A database notification is an announcement: it is read once and gone, and it
 * says nothing about whether the thing it announced was ever dealt with. These
 * items are the opposite — they exist for exactly as long as the underlying
 * record is unresolved, so a request nobody approved keeps showing up.
 *
 * The starter ships one queue. Add a private query per queue your project grows,
 * list it in pendingItems() and count it in pendingCount().
 */
#[Singleton]
class AdminActionService
{
    /**
     * The pending items, longest waiting first.
     *
     * @return Collection<int, array{topic: NotificationTopicEnum, message: string, url: string, since: Carbon|null}>
     */
    public function pendingItems(int $limit = 6): Collection
    {
        return collect([
            ...$this->accountsWithoutRole(),
        ])
            ->sortBy('since')
            ->values()
            ->take($limit);
    }

    /**
     * How many items are outstanding in total, counted without loading them.
     */
    public function pendingCount(): int
    {
        return $this->accountsWithoutRoleQuery()->exists() ? 1 : 0;
    }

    // Queues

    /**
     * Accounts that registered but hold no active role, so they can sign in and
     * reach nothing. One entry covers all of them — the listing does the detail.
     *
     * @return array<int, array<string, mixed>>
     */
    private function accountsWithoutRole(): array
    {
        $count = $this->accountsWithoutRoleQuery()->count();

        if ($count === 0) {
            return [];
        }

        $oldest = $this->accountsWithoutRoleQuery()->oldest()->value('created_at');

        return [[
            'topic' => NotificationTopicEnum::ADMIN_UNASSIGNED_ACCOUNT,
            'message' => kPluralize('account', $count).' cannot reach a workspace until a role is granted.',
            'url' => route('admin.unassigned'),
            'since' => $oldest,
        ]];
    }

    // Queries

    private function accountsWithoutRoleQuery(): Builder
    {
        return User::query()->carriesNoRole();
    }

    /**
     * Money is formatted as an HTML entity, which has no business in a string
     * the view is going to escape.
     */
    private function plain(string $message): string
    {
        return html_entity_decode($message);
    }
}
