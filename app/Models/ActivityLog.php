<?php

namespace App\Models;

use App\Enums\ActivityActionEnum;
use App\Enums\ActivityPlatformEnum;
use App\Traits\WithDynamicModelFormatting;
use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Casts\AsArrayObject;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

#[Unguarded]
class ActivityLog extends Model
{
    use WithDynamicModelFormatting;

    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'action' => ActivityActionEnum::class,
            'original' => AsArrayObject::class,
            'changes' => AsArrayObject::class,
            'platform' => ActivityPlatformEnum::class,
        ];
    }

    // Relationships

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function loggable(): MorphTo
    {
        return $this->morphTo();
    }
}
