<?php

namespace App\Models;

use App\Enums\StatusEmailCampaignRecipient;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One recipient's copy of a campaign, and what happened to it. Created in a batch
 * when the campaign starts sending — see EmailCampaignService::createRecipientRows().
 */
#[Unguarded]
class EmailCampaignRecipient extends Model
{
    protected function casts(): array
    {
        return [
            'status' => StatusEmailCampaignRecipient::class,
            'sent_at' => 'datetime',
        ];
    }

    // Relationships

    public function emailCampaign(): BelongsTo
    {
        return $this->belongsTo(EmailCampaign::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    // Scopes

    #[Scope]
    protected function pending(Builder $query): void
    {
        $query->where('status', StatusEmailCampaignRecipient::PENDING);
    }
}
