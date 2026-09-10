<?php

namespace App\Models;

use App\Traits\WithDynamicModelFormatting;
use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Unguarded]
class TransactionEvidence extends Model
{
    use WithDynamicModelFormatting;

    /**
     * Laravel would pluralise this to transaction_evidences. The table is named
     * for the word as it is actually written.
     */
    protected $table = 'transaction_evidence';

    // Relationships

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }
}
