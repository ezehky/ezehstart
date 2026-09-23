<?php

namespace App\Models;

use App\Enums\SystemEmailEnum;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A reusable design structure — "Newsletter Template". Creating a campaign from a
 * template deep-copies `content`/`design` onto the campaign; editing the campaign
 * afterward never touches the template it started from.
 */
#[Unguarded]
class EmailTemplate extends Model
{
    protected function casts(): array
    {
        return [
            'content' => 'array',
            'design' => 'array',
            'system_email' => SystemEmailEnum::class,
        ];
    }

    // Scopes

    #[Scope]
    protected function forSystemEmail(Builder $query, SystemEmailEnum $email): void
    {
        $query->where('system_email', $email);
    }

    // Relationships

    public function thumbnailImage(): BelongsTo
    {
        return $this->belongsTo(Image::class, 'thumbnail_image_id');
    }

    public function footerSection(): BelongsTo
    {
        return $this->belongsTo(EmailSection::class, 'footer_section_id');
    }

    public function campaigns(): HasMany
    {
        return $this->hasMany(EmailCampaign::class);
    }
}
