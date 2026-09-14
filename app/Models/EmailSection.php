<?php

namespace App\Models;

use App\Enums\EmailSectionTypeEnum;
use App\Enums\StatusYes;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A reusable block (or small group of blocks) an admin designs once and drops into
 * any template or campaign — "Default Footer", "Product CTA", "Newsletter Header".
 */
#[Unguarded]
class EmailSection extends Model
{
    protected function casts(): array
    {
        return [
            'content' => 'array',
            'email_section_type' => EmailSectionTypeEnum::class,
            'is_default' => StatusYes::class,
        ];
    }

    // Getters

    /**
     * Whether any template or campaign currently points at this section as its
     * footer. Read live rather than off `used_count` — that column is display-only
     * (the "N uses" badge on the section card) and a delete guard has to answer off
     * what is actually attached right now.
     */
    public function isAttached(): bool
    {
        return $this->templates()->exists() || $this->campaigns()->exists();
    }

    // Relationships

    public function templates(): HasMany
    {
        return $this->hasMany(EmailTemplate::class, 'footer_section_id');
    }

    public function campaigns(): HasMany
    {
        return $this->hasMany(EmailCampaign::class, 'footer_section_id');
    }

    // Scopes

    #[Scope]
    protected function ofType(Builder $query, EmailSectionTypeEnum $type): void
    {
        $query->where('email_section_type', $type);
    }

    #[Scope]
    protected function defaults(Builder $query): void
    {
        $query->where('is_default', StatusYes::YES);
    }
}
