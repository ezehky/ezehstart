<?php

namespace App\Models;

use App\Enums\GenderEnum;
use App\Traits\WithDynamicModelFormatting;
use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Casts\AsArrayObject;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Unguarded]
class UserProfile extends Model
{
    use WithDynamicModelFormatting;

    protected function casts(): array
    {
        return [
            'gender' => GenderEnum::class,
            'settings' => AsArrayObject::class,
        ];
    }

    // Relationships

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
