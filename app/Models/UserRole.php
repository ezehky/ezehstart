<?php

namespace App\Models;

use App\Enums\StatusDefault;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\AsArrayObject;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Unguarded]
class UserRole extends Model
{
    protected function casts(): array
    {
        return [
            'status' => StatusDefault::class,
            'gates' => AsArrayObject::class,
        ];
    }

    // Getters

    /**
     * This administrator's override as a plain array, for merging and counting.
     *
     * Null still means "inherit the role" and empty still means "everything was
     * deliberately taken away" — this flattens both to `[]`, so only call it where
     * that difference has already been decided.
     */
    public function gatesArray(): array
    {
        return $this->gates?->toArray() ?? [];
    }

    // Relationships

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function role(): BelongsTo
    {
        // `gates` is in the select because GateService resolves an administrator's
        // access straight off the eager-loaded assignment. Leave it out and every
        // gate check silently reads null and denies.
        return $this->belongsTo(Role::class)
            ->select('id', 'name', 'gates');
    }

    // Scopes

    #[Scope]
    public function isActive(Builder $builder): void
    {
        $builder->where('status', StatusDefault::ACTIVE);
    }
}
