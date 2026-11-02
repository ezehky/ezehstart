<?php

namespace App\Models;

use App\Enums\StatusDefault;
use App\Traits\WithDynamicModelFormatting;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A state or region inside a country — reference data seeded beside the
 * countries, for whatever a project hangs on a place: an address, a price.
 */
#[Unguarded]
class Region extends Model
{
    use WithDynamicModelFormatting;

    protected function casts(): array
    {
        return [
            'status' => StatusDefault::class,
        ];
    }

    // Scopes

    #[Scope]
    protected function active(Builder $query): void
    {
        $query->where('status', StatusDefault::ACTIVE);
    }

    // Relationships

    public function country(): BelongsTo
    {
        return $this->belongsTo(Country::class);
    }
}
