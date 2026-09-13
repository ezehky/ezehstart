<?php

namespace App\Services;

use App\Enums\NotificationTopicEnum;
use App\Enums\NotificationTypeEnum;
use App\Enums\StatusDefault;
use App\Enums\StatusUser;
use App\Mail\UploadModifiedEmail;
use App\Models\NotificationType;
use App\Models\User;
use App\Notifications\GeneralNotification;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Fan one announcement out to everybody subscribed to a notification type.
 *
 * Deliberately knows nothing about posts. A post going live is the first thing
 * that needed this, but "tell the people who asked to be told" is not a blog
 * feature — a release note, a price change and a maintenance window all want the
 * same machinery, and each of them should be a caller rather than a copy.
 *
 * Subscription is the notification_preferences row an account already owns, so
 * there is no second list to keep in step with the settings screen.
 */
#[Singleton]
class NotificationSubscriberService
{
    /**
     * How many recipients are loaded at once.
     *
     * The list is every subscribed member, which on a real site is larger than
     * anything worth holding in memory at once.
     */
    public const CHUNK = 200;

    /**
     * The accounts subscribed to a type.
     *
     * Suspended and deleted accounts are left out: a notification is a message to
     * somebody who can act on it, and neither of those can sign in to do so.
     *
     * @return Builder<User>
     */
    public function subscriberQuery(NotificationTypeEnum $type): Builder
    {
        return User::query()
            ->where('status', StatusUser::ACTIVE)
            ->whereHas(
                'notificationPreferences',
                fn (Builder $preference) => $preference
                    ->where('status', StatusDefault::ACTIVE)
                    ->whereHas(
                        'notificationType',
                        fn (Builder $row) => $row
                            ->where('notification_type', $type->value)
                            ->where('status', StatusDefault::ACTIVE),
                    ),
            );
    }

    /**
     * How many accounts a broadcast would reach, for a confirmation dialog.
     */
    public function subscriberCount(NotificationTypeEnum $type): int
    {
        return $this->typeExists($type) ? $this->subscriberQuery($type)->count() : 0;
    }

    /**
     * Send an announcement to everybody subscribed to $type.
     *
     * $mailFor is handed each recipient and returns the Mailable to queue for
     * them, or null to send them nothing by email — a closure rather than one
     * Mailable because a mail addressed to somebody usually needs to know who
     * they are. Pass null to skip email entirely and leave only the bell.
     *
     * Returns how many accounts were reached. Failures are logged and skipped:
     * one bad address must not cost everybody else their notification.
     *
     * @param  array<string, mixed>  $data  Extra payload, e.g. a "url" to open.
     * @param  ?\Closure(User): ?object  $mailFor
     */
    public function broadcast(
        NotificationTypeEnum $type,
        NotificationTopicEnum $topic,
        string $message,
        array $data = [],
        ?\Closure $mailFor = null,
        bool $inApp = true,
    ): int {
        // A type an administrator retired is a type nobody is subscribed to any
        // more. Asking first keeps the caller from having to know that.
        if (! $this->typeExists($type)) {
            return 0;
        }

        $reached = 0;

        $this->subscriberQuery($type)
            ->select(['id', 'name', 'email'])
            ->chunkById(self::CHUNK, function (Collection $recipients) use ($topic, $message, $data, $mailFor, $inApp, &$reached) {
                foreach ($recipients as $recipient) {
                    if ($this->deliver($recipient, $topic, $message, $data, $mailFor, $inApp)) {
                        $reached++;
                    }
                }
            });

        return $reached;
    }

    /**
     * Tell one account that an administrator changed something of theirs.
     *
     * Not a broadcast: there is exactly one person this concerns, and it is not
     * something they subscribed to — it is something that happened to them. The
     * two channels are separate site switches because an email and a bell entry
     * cost the recipient different amounts of attention.
     *
     * Silent when the actor is the owner: somebody does not need telling about
     * what they just did themselves.
     *
     * @param  string  $summary  What happened, in a sentence.
     */
    public function notifyOwnerOfChange(User $owner, string $summary, ?User $actor = null, array $data = []): bool
    {
        $actor ??= auth()->user();

        if ($actor && $actor->id === $owner->id) {
            return false;
        }

        $email = (bool) data_get(kSiteConfig('uploads'), 'modification.email', true);
        $inApp = (bool) data_get(kSiteConfig('uploads'), 'modification.in-app', true);

        if (! $email && ! $inApp) {
            return false;
        }

        return $this->deliver(
            $owner,
            NotificationTopicEnum::UPLOAD_MODIFIED,
            $summary,
            $data,
            $email ? fn (User $recipient) => new UploadModifiedEmail($recipient, $summary) : null,
            $inApp,
        );
    }

    // |||||||||||||||||||||||||||||||||||||||||||||||||||||||||||
    // PRIVATE

    /**
     * One recipient's copy. Both channels are attempted independently, so a mail
     * server that is down still leaves the bell entry behind.
     *
     * @param  array<string, mixed>  $data
     * @param  ?\Closure(User): ?object  $mailFor
     */
    private function deliver(
        User $recipient,
        NotificationTopicEnum $topic,
        string $message,
        array $data,
        ?\Closure $mailFor,
        bool $inApp,
    ): bool {
        $delivered = false;

        if ($inApp) {
            try {
                $recipient->notify(new GeneralNotification($topic->value, $message, $data ?: null));

                $delivered = true;
            } catch (\Throwable $exception) {
                Log::channel('ezeh')->error('Subscriber notification failed', [
                    'user_id' => $recipient->id,
                    'topic' => $topic->value,
                    'message' => $exception->getMessage(),
                ]);
            }
        }

        if ($mailFor === null) {
            return $delivered;
        }

        try {
            $mailable = $mailFor($recipient);

            if ($mailable !== null) {
                Mail::to($recipient->email)->queue($mailable);

                $delivered = true;
            }
        } catch (\Throwable $exception) {
            // One bad address must not stop the rest of the list.
            Log::channel('ezeh')->error('Subscriber email failed to queue', [
                'user_id' => $recipient->id,
                'topic' => $topic->value,
                'message' => $exception->getMessage(),
            ]);
        }

        return $delivered;
    }

    /**
     * Whether the site still offers this type at all. Types are rows an
     * administrator can retire, so the enum case existing is not the answer.
     */
    private function typeExists(NotificationTypeEnum $type): bool
    {
        return NotificationType::query()
            ->active()
            ->where('notification_type', $type->value)
            ->exists();
    }
}
