<?php

namespace App\Models;

use App\Enums\StatusDefault;
use App\Traits\WithDynamicModelFormatting;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Unguarded]
class Country extends Model
{
    use WithDynamicModelFormatting;

    protected function casts(): array
    {
        return [
            'status' => StatusDefault::class,
        ];
    }

    // Getters

    /**
     * The name with its flag in front, for a select option or a profile line.
     * The flag is an emoji, so this is safe to echo without {!! !!}.
     */
    public function labelWithFlag(): string
    {
        return trim("{$this->flag} {$this->name}");
    }

    /**
     * The dialling code as it is written, with the plus the column does not store.
     */
    public function dialCode(): ?string
    {
        return $this->phone_code ? '+'.ltrim($this->phone_code, '+') : null;
    }

    // Relationships

    public function userProfiles(): HasMany
    {
        return $this->hasMany(UserProfile::class);
    }

    // Scopes

    #[Scope]
    protected function active(Builder $query): void
    {
        $query->where('status', StatusDefault::ACTIVE);
    }

    /**
     * Alphabetical, which is the only order a country picker is ever wanted in.
     */
    #[Scope]
    protected function alphabetical(Builder $query): void
    {
        $query->orderBy('name');
    }
}
