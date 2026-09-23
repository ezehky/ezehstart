<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

/**
 * A phone number that could be dialled.
 *
 * Deliberately a length check and not a numbering-plan check. The picker in the
 * browser already runs libphonenumber and shows the person when a number looks
 * wrong for the country; the server's job is to refuse what is plainly not a
 * phone number — letters, a two-digit fragment, a paragraph — without shipping a
 * second copy of every country's numbering plan to agree with it.
 *
 * E.164 caps a full number at fifteen digits, dialling code included, which is
 * why the code is counted when it is given.
 */
class PhoneRule implements ValidationRule
{
    public function __construct(
        private readonly ?string $dialCode = null,
        private readonly bool $required = false,
    ) {}

    /**
     * Run the validation rule.
     *
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (blank($value)) {
            if ($this->required) {
                $fail('The :attribute field is required.');
            }

            return;
        }

        if (! \is_string($value) || ! preg_match('/^\+?[\d\s\-().]+$/', $value)) {
            $fail('The :attribute may only contain digits.');

            return;
        }

        $international = kPhoneInternational($value, $this->dialCode) ?? '';
        $digits = mb_strlen(preg_replace('/\D/', '', $international) ?? '');

        if ($digits < 7 || $digits > 15) {
            $fail('The :attribute does not look like a complete phone number.');
        }
    }
}
