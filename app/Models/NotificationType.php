<?php

namespace App\Models;

use App\Enums\NotificationTypeEnum;
use App\Enums\StatusDefault;
use App\Traits\WithDynamicModelFormatting;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Unguarded]
class NotificationType extends Model
{
    use WithDynamicModelFormatting;

    protected function casts(): array
    {
        return [
            // Deliberately not cast to NotificationTypeEnum. The whole reason
            // these are rows is that an administrator can add a type without a
            // deploy, and a backed-enum cast would throw on the first one they
            // added. The enum stays the seed source and the name code refers to;
            // use knownType() when you need it back as a case.
            'status' => StatusDefault::class,
        ];
    }

    // Getters

    /**
     * The enum case this row corresponds to, or null for a type an administrator
     * created that no code knows about by name.
     */
    public function knownType(): ?NotificationTypeEnum
    {
        return NotificationTypeEnum::tryFrom((string) $this->notification_type);
    }

    /**
     * Whether this is the given shipped type. Named isType() rather than is()
     * because Eloquent already defines is() for comparing two model instances.
     */
    public function isType(NotificationTypeEnum $type): bool
    {
        return $this->knownType() === $type;
    }

    // Relationships

    public function notificationPreferences(): HasMany
    {
        return $this->hasMany(NotificationPreference::class);
    }

    // Scopes

    #[Scope]
    protected function active(Builder $query): void
    {
        $query->where('status', StatusDefault::ACTIVE);
    }

    /**
     * The order the settings page renders these in. Falls back to id so a batch of
     * types all left at flow_order 0 still comes out in a stable sequence rather
     * than whatever the database felt like that day.
     */
    #[Scope]
    protected function inFlowOrder(Builder $query): void
    {
        $query->orderBy('flow_order')->orderBy('id');
    }
}
