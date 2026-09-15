<?php

namespace App\Services;

use App\Enums\ActivityActionEnum;
use App\Enums\EmailRecurrenceEnum;
use App\Enums\StatusDefault;
use App\Enums\StatusEmailCampaign;
use App\Enums\StatusEmailCampaignRecipient;
use App\Mail\CampaignEmail;
use App\Models\EmailCampaign;
use App\Models\EmailCampaignRecipient;
use App\Models\User;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Recipients, sending, and the state a campaign moves through on the way there.
 */
#[Singleton]
class EmailCampaignService
{
    /**
     * How many recipient rows are worked through per scheduled-command tick. Mirrors
     * NotificationSubscriberService::CHUNK — a mailing list is always assumed larger
     * than one request should hold in memory.
     */
    public const CHUNK = 200;

    // |||||||||||||||||||||||||||||||||||||||||||||||||||||||||||
    // RECIPIENTS

    /**
     * The account query a campaign's audience resolves to. SPECIFIC has no account
     * query at all — its addresses live in `recipient_config` and never touch `users`.
     */
    public function recipientQuery(EmailCampaign $campaign): ?Builder
    {
        return match (true) {
            $campaign->email_recipient_type->isAllUsers() => User::query()->users(),
            $campaign->email_recipient_type->isPreferences() => $this->preferenceQuery($campaign),
            default => null,
        };
    }

    /**
     * Notification types are rows an administrator can add without a deploy — see
     * NotificationType — so this reads the raw `notification_type` string rather
     * than restricting to NotificationTypeEnum's shipped cases.
     */
    private function preferenceQuery(EmailCampaign $campaign): Builder
    {
        $types = collect($campaign->recipient_config['notification_types'] ?? [])
            ->map(fn ($value) => (string) $value)
            ->filter();

        return User::query()
            ->users()
            ->when(
                $types->isNotEmpty(),
                fn (Builder $query) => $query->whereHas(
                    'notificationPreferences',
                    fn (Builder $preference) => $preference
                        ->where('status', StatusDefault::ACTIVE)
                        ->whereHas(
                            'notificationType',
                            fn (Builder $type) => $type
                                ->whereIn('notification_type', $types)
                                ->where('status', StatusDefault::ACTIVE),
                        ),
                ),
                // No category chosen yet — nothing is selected rather than everybody.
                fn (Builder $query) => $query->whereRaw('1 = 0'),
            );
    }

    /**
     * How many recipients a campaign would currently reach — the number the
     * Recipients step shows live as the audience is narrowed.
     */
    public function estimateRecipients(EmailCampaign $campaign): int
    {
        if ($campaign->email_recipient_type->isSpecific()) {
            return count($this->validEmails($campaign->recipient_config['emails'] ?? []));
        }

        return $this->recipientQuery($campaign)?->count() ?? 0;
    }

    /**
     * The specific-address list, validated the same way every other email field in
     * this app is (EmailRule, without the DNS lookup — a bulk audience list is not
     * the place to make one lookup per address on every keystroke).
     *
     * @return array<int, string>
     */
    public function validEmails(array $emails): array
    {
        return collect($emails)
            ->map(fn ($email) => trim((string) $email))
            ->filter(fn (string $email) => $email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL))
            ->unique()
            ->values()
            ->all();
    }

    // |||||||||||||||||||||||||||||||||||||||||||||||||||||||||||
    // SENDING

    /**
     * Render once and send synchronously to a short, explicit list — the same
     * `sendNow()` pattern the OTP mailables use, not the bulk queue.
     *
     * @param  array<int, string>  $emails
     */
    public function sendTest(EmailCampaign $campaign, array $emails, ?User $previewAs = null): int
    {
        $emails = $this->validEmails($emails);

        if ($emails === []) {
            return 0;
        }

        $rendered = app(EmailRenderService::class)->renderCampaign($campaign, $previewAs);
        $sent = 0;

        foreach ($emails as $email) {
            try {
                Mail::to($email)->sendNow(new CampaignEmail($rendered['subject'], $rendered['html'], $campaign));
                $sent++;
            } catch (\Throwable $exception) {
                Log::channel('ezeh')->error('Campaign test send failed', [
                    'email_campaign_id' => $campaign->id,
                    'email' => $email,
                    'message' => $exception->getMessage(),
                ]);
            }
        }

        app(ActivityLogService::class)->logActivity(
            ActivityActionEnum::EMAIL_CAMPAIGN_TEST_SEND,
            " test of \"{$campaign->name}\" to {$sent} address(es)",
            model: $campaign,
        );

        return $sent;
    }

    public function schedule(EmailCampaign $campaign, Carbon $at, string $timezone): void
    {
        $campaign->fill([
            'status' => StatusEmailCampaign::SCHEDULED,
            'scheduled_at' => $at,
            'timezone' => $timezone,
        ]);

        $affected = app(ActivityLogService::class)->affectedColumns($campaign);
        $campaign->save();

        app(ActivityLogService::class)->logActivity(
            ActivityActionEnum::EMAIL_CAMPAIGN_SCHEDULE,
            " campaign: {$campaign->name} for {$at->toDayDateTimeString()}",
            $affected,
            model: $campaign,
        );
    }

    /**
     * Move a campaign from draft/scheduled into SENDING and create its recipient
     * rows. Idempotent — a campaign already SENDING is left alone, so the scheduled
     * command's dueForSending() pass and its SENDING pass can never double-create rows.
     */
    public function startSending(EmailCampaign $campaign): void
    {
        if (! $campaign->status->isEditable()) {
            return;
        }

        $campaign->status = StatusEmailCampaign::SENDING;
        $campaign->save();

        $this->createRecipientRows($campaign);

        app(ActivityLogService::class)->logActivity(
            ActivityActionEnum::EMAIL_CAMPAIGN_SEND,
            " campaign: {$campaign->name}",
            model: $campaign,
        );
    }

    private function createRecipientRows(EmailCampaign $campaign): void
    {
        if ($campaign->email_recipient_type->isSpecific()) {
            foreach ($this->validEmails($campaign->recipient_config['emails'] ?? []) as $email) {
                EmailCampaignRecipient::create([
                    'email_campaign_id' => $campaign->id,
                    'user_id' => User::query()->where('email', $email)->value('id'),
                    'email' => $email,
                    'status' => StatusEmailCampaignRecipient::PENDING,
                ]);
            }

            return;
        }

        $this->recipientQuery($campaign)
            ?->select(['id', 'email'])
            ->chunkById(self::CHUNK, function ($users) use ($campaign) {
                foreach ($users as $user) {
                    EmailCampaignRecipient::create([
                        'email_campaign_id' => $campaign->id,
                        'user_id' => $user->id,
                        'email' => $user->email,
                        'status' => StatusEmailCampaignRecipient::PENDING,
                    ]);
                }
            });
    }

    /**
     * One tick of the scheduled command: send up to $chunkSize pending rows for
     * every campaign currently SENDING, then close out campaigns with nothing left
     * to send. One bad address marks that row FAILED and never halts the rest —
     * the same discipline NotificationSubscriberService::deliver() follows.
     */
    public function processDueBatch(int $chunkSize = self::CHUNK): void
    {
        EmailCampaign::query()
            ->where('status', StatusEmailCampaign::SENDING)
            ->each(function (EmailCampaign $campaign) use ($chunkSize) {
                $rows = $campaign->recipients()->pending()->limit($chunkSize)->get();

                if ($rows->isEmpty()) {
                    $this->finishIfDone($campaign);

                    return;
                }

                $render = app(EmailRenderService::class);

                foreach ($rows as $row) {
                    try {
                        $recipient = $row->user;
                        $rendered = $render->renderCampaign($campaign, $recipient);

                        Mail::to($row->email)->queue(new CampaignEmail($rendered['subject'], $rendered['html'], $campaign));

                        $row->status = StatusEmailCampaignRecipient::QUEUED;
                        $row->sent_at = now();
                        $row->save();
                    } catch (\Throwable $exception) {
                        $row->status = StatusEmailCampaignRecipient::FAILED;
                        $row->error = $exception->getMessage();
                        $row->save();

                        Log::channel('ezeh')->error('Campaign recipient failed to queue', [
                            'email_campaign_id' => $campaign->id,
                            'email_campaign_recipient_id' => $row->id,
                            'message' => $exception->getMessage(),
                        ]);
                    }
                }

                $this->finishIfDone($campaign);
            });
    }

    private function finishIfDone(EmailCampaign $campaign): void
    {
        // QUEUED is the terminal success state here, not an in-progress one:
        // Mail::queue() has already handed the message to the queue, and nothing
        // in this pass observes a queued job actually being delivered.
        $stillOutstanding = $campaign->recipients()->pending()->exists();

        if ($stillOutstanding) {
            return;
        }

        $reachedAnyone = $campaign->recipients()
            ->whereIn('status', [StatusEmailCampaignRecipient::QUEUED, StatusEmailCampaignRecipient::SENT])
            ->exists();

        $campaign->status = $reachedAnyone ? StatusEmailCampaign::SENT : StatusEmailCampaign::FAILED;
        $campaign->sent_at = now();
        $campaign->save();

        $this->spawnNextOccurrence($campaign);
    }

    // |||||||||||||||||||||||||||||||||||||||||||||||||||||||||||
    // RECURRENCE

    /**
     * Queue up the next run of a repeating campaign, as a scheduled copy.
     *
     * The campaign that just went out is left alone: it is the record of what was
     * sent, to whom, and when, and rewinding it to SCHEDULED would throw that away.
     * The copy carries the content, design, audience and sender forward and points
     * back at the first send of the series through `recurs_from_id`.
     *
     * Returns the new occurrence, or null when the series has run out — either it
     * never repeated, or its end date has passed.
     */
    public function spawnNextOccurrence(EmailCampaign $campaign): ?EmailCampaign
    {
        $recurrence = $campaign->email_recurrence ?? EmailRecurrenceEnum::NONE;

        if ($recurrence->isNone()) {
            return null;
        }

        // Measured from the moment this occurrence was meant to go out rather than
        // from when it actually finished, so a send held up by a backed-up queue
        // does not drag the whole series later and later.
        $from = $campaign->scheduled_at ?? $campaign->sent_at ?? now();
        $at = $recurrence->next(Carbon::parse($from));

        if ($at === null) {
            return null;
        }

        // A series scheduled during an outage can have fallen several occurrences
        // behind. Skip forward to the next one that is actually still ahead rather
        // than sending the backlog.
        while ($at->isPast()) {
            $next = $recurrence->next($at);

            if ($next === null || $next->lessThanOrEqualTo($at)) {
                return null;
            }

            $at = $next;
        }

        if ($campaign->recurrence_ends_at && $at->greaterThan($campaign->recurrence_ends_at)) {
            return null;
        }

        $occurrence = $campaign->replicate([
            'status',
            'scheduled_at',
            'sent_at',
            'estimated_recipients',
            'recurs_from_id',
        ]);

        $occurrence->status = StatusEmailCampaign::SCHEDULED;
        $occurrence->scheduled_at = $at;
        $occurrence->sent_at = null;
        $occurrence->estimated_recipients = null;
        $occurrence->recurs_from_id = $campaign->recurs_from_id ?? $campaign->id;
        $occurrence->save();

        app(ActivityLogService::class)->logActivity(
            ActivityActionEnum::EMAIL_CAMPAIGN_SCHEDULE,
            " the next {$recurrence->label(lowercase: true)} run of \"{$campaign->name}\" for {$at->toDayDateTimeString()}",
            model: $occurrence,
        );

        return $occurrence;
    }
}
