<?php

namespace App\Models;

use App\Enums\StatusDefault;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Unguarded]
class UserRole extends Model
{
    protected function casts(): array
    {
        return [
            'status' => StatusDefault::class,
        ];
    }

    // Relationships

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class)
            ->select('id', 'name');
    }

    // Scopes

    #[Scope]
    public function isActive(Builder $builder): void
    {
        $builder->where('status', StatusDefault::ACTIVE);
    }
}
