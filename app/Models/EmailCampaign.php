<?php

namespace App\Models;

use App\Enums\EmailRecipientTypeEnum;
use App\Enums\EmailRecurrenceEnum;
use App\Enums\StatusEmailCampaign;
use App\Enums\StatusEmailCampaignRecipient;
use App\Traits\WithDynamicModelFormatting;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A specific email created from a template (or from scratch) — "September
 * Newsletter". Everything it renders from — `content`, `design`, `subject` — is its
 * own copy; nothing here is read back from the template it may have started from.
 */
#[Unguarded]
class EmailCampaign extends Model
{
    use WithDynamicModelFormatting;

    protected function casts(): array
    {
        return [
            'content' => 'array',
            'design' => 'array',
            'recipient_config' => 'array',
            'email_recipient_type' => EmailRecipientTypeEnum::class,
            'email_recurrence' => EmailRecurrenceEnum::class,
            'status' => StatusEmailCampaign::class,
            'scheduled_at' => 'datetime',
            'recurrence_ends_at' => 'datetime',
            'sent_at' => 'datetime',
        ];
    }

    // Getters

    /**
     * One grouped query for the Sent-detail stat tiles: recipient count per status.
     *
     * @return array<string, int>
     */
    public function deliveryCounts(): array
    {
        $counts = $this->recipients()
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        return collect(StatusEmailCampaignRecipient::cases())
            ->mapWithKeys(fn (StatusEmailCampaignRecipient $case) => [
                $case->name => (int) ($counts[$case->value] ?? 0),
            ])
            ->all();
    }

    // Relationships

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function emailTemplate(): BelongsTo
    {
        return $this->belongsTo(EmailTemplate::class);
    }

    public function footerSection(): BelongsTo
    {
        return $this->belongsTo(EmailSection::class, 'footer_section_id');
    }

    public function recipients(): HasMany
    {
        return $this->hasMany(EmailCampaignRecipient::class);
    }

    /**
     * The occurrence this one was copied from, and the ones copied from it.
     */
    public function recursFrom(): BelongsTo
    {
        return $this->belongsTo(self::class, 'recurs_from_id');
    }

    public function occurrences(): HasMany
    {
        return $this->hasMany(self::class, 'recurs_from_id');
    }

    // Scopes

    /**
     * Scheduled campaigns whose moment has come. Unbounded at the far end, like
     * Post::dueForPublishing() — a campaign that should have gone out during an
     * outage still should go out.
     */
    #[Scope]
    protected function dueForSending(Builder $query): void
    {
        $query->where('status', StatusEmailCampaign::SCHEDULED)
            ->whereNotNull('scheduled_at')
            ->where('scheduled_at', '<=', now());
    }

    #[Scope]
    protected function sent(Builder $query): void
    {
        $query->where('status', StatusEmailCampaign::SENT);
    }
}
