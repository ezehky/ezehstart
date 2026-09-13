<?php

namespace App\Models;

use App\Enums\DeletionReminderEnum;
use App\Traits\WithDynamicModelFormatting;
use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A warning already sent about a pending account deletion.
 *
 * Write-once: the row is the claim, and the unique index on
 * (user_id, reminder) is what stops a second scheduler tick sending the same
 * warning again.
 */
#[Unguarded]
class UserDeletionReminder extends Model
{
    use WithDynamicModelFormatting;

    public const UPDATED_AT = null;

    public const CREATED_AT = null;

    protected function casts(): array
    {
        return [
            'reminder' => DeletionReminderEnum::class,
            'sent_at' => 'datetime',
        ];
    }

    // Relationships

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
