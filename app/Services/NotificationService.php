<?php

namespace App\Services;

use App\Enums\NotificationTopicEnum;
use App\Enums\StatusUser;
use App\Enums\UserRoleEnum;
use App\Models\User;
use App\Notifications\GeneralNotification;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Notification;

/**
 * One way in and out of the database notifications behind the bell menu.
 */
#[Singleton]
class NotificationService
{
    // Senders

    /**
     * Notify the account an action belongs to.
     *
     * @param  array<string, mixed>  $data  Extra payload, e.g. a "url" to open.
     */
    public function notifyUser(User $user, NotificationTopicEnum $topic, string $message, array $data = []): void
    {
        $user->notify(new GeneralNotification($topic->value, $message, $data ?: null));
    }

    /**
     * Notify every active admin about something that needs their attention —
     * an account needing a role, a sign-up to review.
     * The admin who performed the action is left out, since they already know;
     * pass $except explicitly to skip somebody else instead.
     *
     * @param  array<string, mixed>  $data  Extra payload, e.g. a "url" to open.
     */
    public function notifyAdmins(NotificationTopicEnum $topic, string $message, array $data = [], ?User $except = null): void
    {
        $recipients = $this->admins($except ?? auth()->user());

        if ($recipients->isEmpty()) {
            return;
        }

        Notification::send($recipients, new GeneralNotification($topic->value, $message, $data ?: null));
    }

    /**
     * Active admin accounts, optionally without one of their own.
     *
     * @return Collection<int, User>
     */
    public function admins(?User $except = null): Collection
    {
        return User::query()
            ->carriesRole(UserRoleEnum::ADMIN)
            ->where('status', StatusUser::ACTIVE)
            ->when($except, fn ($query) => $query->whereKeyNot($except->getKey()))
            ->get();
    }

    // Getters

    public function unreadCountFor(User $user): int
    {
        return $user->unreadNotifications()->count();
    }

    /**
     * The most recent entries for the bell menu, newest first.
     *
     * @return Collection<int, DatabaseNotification>
     */
    public function recentFor(User $user, int $limit = 10): Collection
    {
        return $user->notifications()->latest()->limit($limit)->get();
    }

    // Actions

    public function markAsRead(User $user, string $notificationId): ?DatabaseNotification
    {
        $notification = $user->notifications()->whereKey($notificationId)->first();

        $notification?->markAsRead();

        return $notification;
    }

    public function markAllAsRead(User $user): void
    {
        $user->unreadNotifications()->update(['read_at' => now()]);
    }

    public function clearFor(User $user): void
    {
        $user->notifications()->delete();
    }
}
