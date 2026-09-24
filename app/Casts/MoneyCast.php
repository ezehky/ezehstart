<?php

namespace App\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * A decimal held as a whole number of its smallest unit, so the database never
 * rounds it. Money is in hundredths — kobo, cents — which is the default.
 *
 * A column that needs finer grain names its own scale: `MoneyCast::class.':100000000'`
 * keeps eight places, which is what an exchange rate like 0.00065 needs to
 * survive the trip — at a hundred it would be stored as nothing.
 */
class MoneyCast implements CastsAttributes
{
    public function __construct(protected int|string $scale = 100)
    {
        $this->scale = (int) $scale;
    }

    /**
     * Cast the given value.
     *
     * Always a float. PHP hands back an int when the division comes out even, so
     * a rate of exactly one would otherwise read as 1 on one row and 0.5 on the next.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): mixed
    {
        return (float) ($value / $this->scale);
    }

    /**
     * Prepare the given value for storage.
     *
     * Rounded rather than truncated: 0.00065 times a hundred million is
     * 65000.000000000001 in floating point, and 19.99 times a hundred is
     * 1998.9999999999998. The column is an integer either way.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): mixed
    {
        return (int) round($value * $this->scale);
    }
}
