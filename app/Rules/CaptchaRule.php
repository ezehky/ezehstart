<?php

namespace App\Rules;

use App\Services\CaptchaService;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

/**
 * The server half of the captcha.
 *
 * The widget on the page is a courtesy; this is the boundary. A form that renders
 * the widget but never reaches this rule is not protected, which is why
 * WithCaptcha adds the two together and never one without the other.
 */
class CaptchaRule implements ValidationRule
{
    /**
     * Run the validation rule.
     *
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (blank($value)) {
            $fail('Please complete the verification challenge.');

            return;
        }

        if (! app(CaptchaService::class)->verify(\is_string($value) ? $value : null)) {
            $fail('That verification could not be confirmed. Please try again.');
        }
    }
}
