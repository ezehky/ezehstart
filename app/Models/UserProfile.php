<?php

namespace App\Models;

use App\Enums\GenderEnum;
use App\Enums\SocialHandleEnum;
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
            'socials' => AsArrayObject::class,
        ];
    }

    // Getters

    /**
     * The stored handles as a plain array, for the callers that merge or count them.
     *
     * The cast hands back an ArrayObject, so spread, count() and array_key_exists()
     * all misread it — the same trap as the gate maps.
     *
     * @return array<string, string>
     */
    public function socialsArray(): array
    {
        return $this->socials?->toArray() ?? [];
    }

    /**
     * Every handle that is actually filled in, paired with where it points.
     *
     * Resolved on the way out rather than stored: the handle is kept exactly as it was
     * typed, and the address is rebuilt from the platform on every render — the same
     * bargain the video library strikes with provider and id.
     *
     * @return array<int, array{platform: SocialHandleEnum, handle: string, url: string}>
     */
    public function socialLinks(): array
    {
        $stored = $this->socialsArray();
        $links = [];

        foreach (SocialHandleEnum::profiles() as $platform) {
            $handle = trim((string) ($stored[$platform->value] ?? ''));

            if ($handle === '') {
                continue;
            }

            $links[] = [
                'platform' => $platform,
                'handle' => $handle,
                'url' => $platform->urlFor($handle),
            ];
        }

        return $links;
    }

    // Relationships

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
