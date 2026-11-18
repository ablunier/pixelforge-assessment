<?php

namespace App\Casts;

use App\Money\Money;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * A bigint column of nanodollars as Money (ADR-0001).
 *
 * @implements CastsAttributes<Money|null, Money|null>
 */
final class AsMoney implements CastsAttributes
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?Money
    {
        return $value === null ? null : Money::ofNanodollars((int) $value);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?int
    {
        return $value?->nanodollars();
    }
}
