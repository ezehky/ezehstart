<?php

namespace App\Models;

use App\Traits\WithDynamicModelFormatting;
use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Casts\AsArrayObject;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The payload a gateway sent back, kept as it arrived.
 */
#[Unguarded]
class TransactionMeta extends Model
{
    use WithDynamicModelFormatting;

    protected function casts(): array
    {
        return [
            'content' => AsArrayObject::class,
        ];
    }

    // Relationships

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }
}
