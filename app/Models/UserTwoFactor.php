<?php

namespace App\Models;

use App\Enums\StatusDefault;
use App\Traits\WithDynamicModelFormatting;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Unguarded]
#[Hidden(['secret', 'recovery_codes', 'remember_token'])]
class UserTwoFactor extends Model
{
    use WithDynamicModelFormatting;

    protected function casts(): array
    {
        return [
            // The TOTP secret is the second factor. Encrypted at rest so a leaked
            // database is not the same thing as a leaked authenticator.
            'secret' => 'encrypted',

            // Encrypted rather than hashed, because unused codes have to be
            // displayable again — people ask to see the list they never saved.
            'recovery_codes' => 'encrypted:array',

            'remember_expires_at' => 'datetime',
            'confirmed_at' => 'datetime',
            'last_used_at' => 'datetime',
            'status' => StatusDefault::class,
        ];
    }

    // Getters

    /**
     * Enrolment is only finished once a generated code has actually verified.
     * A row with a secret but no confirmation is somebody who scanned the QR and
     * closed the tab, and must not be treated as protected.
     */
    public function isEnabled(): bool
    {
        return $this->status->isActive() && $this->confirmed_at !== null;
    }

    /**
     * Whether this browser still holds a valid "remember this device" token.
     */
    public function remembersDevice(?string $token): bool
    {
        if (! $token || ! $this->remember_token || ! $this->remember_expires_at) {
            return false;
        }

        if ($this->remember_expires_at->isPast()) {
            return false;
        }

        return hash_equals($this->remember_token, hash('sha256', $token));
    }

    /**
     * How many recovery codes are still unspent. Used codes are removed from the
     * array rather than flagged, so this is just the count.
     */
    public function remainingRecoveryCodes(): int
    {
        return \count((array) ($this->recovery_codes ?? []));
    }

    // Relationships

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
