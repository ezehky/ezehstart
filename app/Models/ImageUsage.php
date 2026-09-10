<?php

namespace App\Models;

use App\Traits\WithDynamicModelFormatting;
use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * A record that something is using an image.
 *
 * The row is made when an image is attached and unmade when it is detached; it is
 * never edited in between, which is what makes "is this image in use" a question
 * the database can answer on its own.
 */
#[Unguarded]
class ImageUsage extends Model
{
    use WithDynamicModelFormatting;

    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
        ];
    }

    // Relationships

    public function image(): BelongsTo
    {
        return $this->belongsTo(Image::class);
    }

    public function usable(): MorphTo
    {
        return $this->morphTo();
    }
}
