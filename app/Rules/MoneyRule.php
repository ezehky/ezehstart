<?php

namespace App\Rules;

use App\Models\User;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

class MoneyRule implements ValidationRule
{
    public function __construct(
        private readonly ?User $user = null,
        private readonly float|int $min = 0,
        private readonly float|int $max = 0,
        private float $fee = 0,
        private readonly float $percentFee = 0,
        private readonly bool $isRequired = true,
        private readonly ?string $currency = null,
        private readonly string $walletColumn = 'balance',
    ) {}

    protected function resolvePercentFees(float $value): void
    {
        if ($this->percentFee) {
            $this->fee = $this->percentFee / 100 * $value;
        }
    }

    /**
     * Run the validation rule.
     *
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        // Convert the value to a float for comparison
        $value = (float) $value;

        $attribute = kBreakText($attribute, lowercase: true);

        // Resolve the percent fees based on the value
        $this->resolvePercentFees($value);

        // Check if the value is less than the minimum allowed
        if ($this->min && $value < $this->min) {
            $fail("The {$attribute} must be at least ".kMoneyFormat($this->min, $this->currency, true).'.');

            return;
        }

        // Check if the value is greater than the maximum allowed
        if ($this->max && $value > $this->max) {
            $fail("The {$attribute} must not exceed ".kMoneyFormat($this->max, $this->currency, true).'.');

            return;
        }

        // Check if user exist for sufficient balance validation. $walletColumn names
        // the money column on the profile; a project that ships wallets swaps in its
        // own enum here.
        if ($this->user && $profile = $this->user->userProfile) {
            $totalAmount = $value + $this->fee;
            if ($profile->{$this->walletColumn} < $totalAmount) {
                $fail("The {$attribute} exceeds your available ".kBreakText($this->walletColumn, lowercase: true).'.');

                return;
            }
        }
    }
}
