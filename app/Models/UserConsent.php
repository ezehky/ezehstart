<?php

namespace App\Models;

use App\Traits\WithDynamicModelFormatting;
use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A record that one person accepted one version of one policy.
 *
 * Deliberately thin and deliberately immutable. It exists to answer a single
 * question later — what exactly did this account agree to, and when — so the
 * interesting part is the row it points at rather than anything stored here.
 */
#[Unguarded]
class UserConsent extends Model
{
    use WithDynamicModelFormatting;

    /**
     * Consent is a point-in-time fact. There is nothing to update, so there is no
     * updated_at to carry — and a table with one that never moved would invite
     * somebody to move it.
     */
    public $timestamps = false;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'accepted_at' => 'datetime',
        ];
    }

    // Relationships

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function policy(): BelongsTo
    {
        return $this->belongsTo(Policy::class);
    }
}
