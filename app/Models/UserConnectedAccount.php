<?php

namespace App\Models;

use App\Enums\SocialProviderEnum;
use App\Enums\StatusDefault;
use App\Traits\WithDynamicModelFormatting;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Unguarded]
#[Hidden(['provider_token', 'provider_refresh_token'])]
class UserConnectedAccount extends Model
{
    use WithDynamicModelFormatting;

    protected function casts(): array
    {
        return [
            'provider' => SocialProviderEnum::class,
            // Encrypted at rest: these are live credentials for somebody's account
            // on another service, and a database dump should not hand them over.
            'provider_token' => 'encrypted',
            'provider_refresh_token' => 'encrypted',
            'token_expires_at' => 'datetime',
            'status' => StatusDefault::class,
        ];
    }

    // Relationships

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    // Scopes

    #[Scope]
    protected function active(Builder $query): void
    {
        $query->where('status', StatusDefault::ACTIVE);
    }

    #[Scope]
    protected function forProvider(Builder $query, SocialProviderEnum $provider): void
    {
        $query->where('provider', $provider);
    }
}
