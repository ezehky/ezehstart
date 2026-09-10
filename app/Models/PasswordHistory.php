<?php

namespace App\Models;

use App\Traits\WithDynamicModelFormatting;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A hash this account used to be able to sign in with.
 *
 * Append-only and never updated: the row exists so a new password can be checked
 * against it, and rewriting history is the one thing it must not allow.
 */
#[Unguarded]
#[Hidden(['password'])]
class PasswordHistory extends Model
{
    use WithDynamicModelFormatting;

    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            // Not 'hashed'. That cast hashes on write, which is right for a
            // credential being set — but these rows are written by copying an
            // already-hashed value across, and hashing it twice would compare
            // against nothing.
            'created_at' => 'datetime',
        ];
    }

    // Relationships

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    // Scopes

    #[Scope]
    protected function newestFirst(Builder $query): void
    {
        $query->orderByDesc('created_at')->orderByDesc('id');
    }
}
