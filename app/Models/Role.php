<?php

namespace App\Models;

use App\Enums\GateAccessEnum;
use App\Enums\StatusDefault;
use App\Services\GateService;
use App\Traits\WithDynamicModelFormatting;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\AsArrayObject;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * An admin role — "Administrator", "Media", "Support".
 *
 * Rows, not enum cases: an administrator adds them from the roles screen, and the
 * starter ships no opinion about which ones a project needs beyond the one protected
 * role that keeps the install administrable.
 *
 * users never have one. What kind of account this is lives on users.user_type, and
 * a role only ever hangs off an admin — see UserTypeEnum.
 *
 * An admin carries any number of them. Two roles that both speak about a screen are
 * merged by GateService, highest access winning, so adding a role only ever widens
 * what somebody reaches.
 */
#[Unguarded]
class Role extends Model
{
    use WithDynamicModelFormatting;

    protected function casts(): array
    {
        return [
            'status' => StatusDefault::class,
            'is_protected' => 'boolean',
            'gates' => AsArrayObject::class,
        ];
    }

    // Getters

    /**
     * The stored gate map as a plain array, for the callers that merge or count it.
     *
     * The cast hands back an ArrayObject so a nested key can be written in place.
     * Anything spreading, counting or `array_key_exists`-ing the map wants an array,
     * and this is the one place that conversion is spelled out.
     */
    public function gatesArray(): array
    {
        return $this->gates?->toArray() ?? [];
    }

    /**
     * A deactivated role grants nothing, whatever its map says. Access is suspended
     * wholesale this way rather than by unpicking who held what.
     */
    public function grantsAccess(): bool
    {
        return $this->status->isActive();
    }

    // Methods

    /**
     * The access this role grants over one navigation key, e.g. 'users' or
     * 'users.roles'. An unconfigured role grants nothing.
     *
     * Delegated rather than read here directly: child-inherits-parent and the
     * explicit-NONE cascade are one rule, and a second copy of it on the model is a
     * second copy to get wrong. Note the map is *flat with dotted keys*, so this is
     * not a data_get() lookup — see gates.md.
     *
     * This answers for the *role* only. To ask what a given administrator may do,
     * go through GateService — an admin can carry other roles, and an override,
     * that this role knows nothing about.
     */
    public function gateFor(string $resource): GateAccessEnum
    {
        return app(GateService::class)->roleAccessFor($this, $resource);
    }

    // Relationships

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class);
    }

    // Scopes

    #[Scope]
    protected function isActive(Builder $builder): void
    {
        $builder->where('status', StatusDefault::ACTIVE);
    }

    #[Scope]
    protected function isProtected(Builder $builder): void
    {
        $builder->where('is_protected', true);
    }
}
