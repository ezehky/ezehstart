<?php

namespace App\Models;

use App\Enums\PaymentVendorEnum;
use App\Enums\StatusDefault;
use App\Traits\WithDynamicModelFormatting;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A payee a checkout can be split with — a vendor's subaccount, settled to a
 * bank account of its own. The site keeps platform_percentage of each payment
 * split with it; the vendor pays the rest out to the subaccount.
 *
 * One row is one vendor's copy. A checkout split with it can only go through
 * that vendor.
 */
#[Unguarded]
class PaymentSubaccount extends Model
{
    use WithDynamicModelFormatting;

    protected $attributes = [
        'platform_percentage' => 0,
        'status' => StatusDefault::ACTIVE->value,
    ];

    protected function casts(): array
    {
        return [
            'vendor' => PaymentVendorEnum::class,
            'platform_percentage' => 'float',
            'status' => StatusDefault::class,
        ];
    }

    // Getters

    /**
     * What the subaccount receives of each payment, as a percentage.
     */
    public function subaccountPercentage(): float
    {
        return round(100 - (float) $this->platform_percentage, 2);
    }

    /**
     * The account number with all but its last four digits hidden, for a
     * listing that has no need to show the whole of it.
     */
    public function maskedAccountNumber(): string
    {
        $number = (string) $this->account_number;

        return \strlen($number) > 4 ? str_repeat('•', 4).substr($number, -4) : $number;
    }

    public function label(): string
    {
        return "{$this->business_name} · {$this->vendor->label()}";
    }

    // Scopes

    #[Scope]
    protected function active(Builder $query): void
    {
        $query->where('status', StatusDefault::ACTIVE);
    }

    #[Scope]
    protected function forVendor(Builder $query, PaymentVendorEnum $vendor): void
    {
        $query->where('vendor', $vendor);
    }

    #[Scope]
    protected function alphabetical(Builder $query): void
    {
        $query->orderBy('business_name');
    }

    // Relationships

    public function gateways(): HasMany
    {
        return $this->hasMany(TransactionGateway::class);
    }
}
