<?php

namespace App\Models;

use App\Enums\NotificationTypeEnum;
use App\Enums\StatusDefault;
use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Unguarded]
class NotificationSubscription extends Model
{
    protected function casts(): array
    {
        return [
            'notification_type' => NotificationTypeEnum::class,
            'status' => StatusDefault::class,
        ];
    }

    // Relationship

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
