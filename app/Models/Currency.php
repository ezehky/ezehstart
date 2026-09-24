<?php

namespace App\Models;

use App\Casts\MoneyCast;
use App\Enums\CurrencySymbolPositionEnum;
use App\Enums\StatusDefault;
use App\Enums\StatusYes;
use App\Traits\WithDynamicModelFormatting;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Unguarded]
class Currency extends Model
{
    use WithDynamicModelFormatting;

    /**
     * How many stored units make one of the rate: eight decimal places. Rates
     * against the naira run as small as 0.00049, so the two places money is kept
     * at would store most of them as nothing.
     */
    public const RATE_SCALE = 100_000_000;

    protected function casts(): array
    {
        return [
            'rate' => MoneyCast::class.':'.self::RATE_SCALE,
            'symbol_position' => CurrencySymbolPositionEnum::class,
            'is_default' => StatusYes::class,
            'status' => StatusDefault::class,
        ];
    }

    // Getters

    public function isDefault(): bool
    {
        return $this->is_default === StatusYes::YES;
    }

    /**
     * The symbol as text, for anywhere HTML is not being rendered — a select
     * option, a CSV, a validation message.
     */
    public function symbolText(): string
    {
        return html_entity_decode($this->symbol);
    }

    /**
     * The rate with its trailing zeros dropped — 0.00065 rather than 0.00065000.
     * Not kMoney(): a rate is not an amount, and two places would round most of
     * them to nothing.
     */
    public function rateText(): string
    {
        return rtrim(rtrim(number_format((float) $this->rate, 8, '.', ','), '0'), '.');
    }

    /**
     * "₦ Naira (NGN)", for a picker.
     */
    public function label(): string
    {
        return "{$this->symbolText()} {$this->name} ({$this->code})";
    }

    /**
     * The row as the plain array kMoneyFormat() and the session carry. An array
     * rather than the model so it can sit in the cache and the session without
     * dragging a serialised Eloquent object along with it.
     *
     * @return array{id: int, name: string, code: string, symbol: string, symbol_position: string, rate: float, is_default: bool}
     */
    public function toCurrencyArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'code' => $this->code,
            'symbol' => $this->symbol,
            // The case's value, not the case: this array is cached and put in the
            // session, and a plain string survives both unchanged.
            'symbol_position' => ($this->symbol_position ?? CurrencySymbolPositionEnum::BEFORE)->value,
            'rate' => (float) $this->rate,
            'is_default' => $this->isDefault(),
        ];
    }

    // Relationships

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    // Scopes

    #[Scope]
    protected function active(Builder $query): void
    {
        $query->where('status', StatusDefault::ACTIVE);
    }

    /**
     * Named for what it finds rather than isDefault(), which would collide with
     * the getter above and stop the class loading.
     */
    #[Scope]
    protected function siteDefault(Builder $query): void
    {
        $query->where('is_default', StatusYes::YES);
    }
}
