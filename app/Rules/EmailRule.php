<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\Storage;
use Illuminate\Translation\PotentiallyTranslatedString;

class EmailRule implements ValidationRule
{
    /**
     * List of known disposable / fake email domains.
     */
    protected array $disposableDomains = [
        'tempmail.com',
        '10minutemail.com',
        'guerrillamail.com',
        'mailinator.com',
        'throwawaymail.com',
        'yopmail.com',
        'trashmail.com',
        'fakeinbox.com',
        'maildrop.cc',
        'getnada.com',
        'sharklasers.com',
    ];

    /**
     * @param  bool  $required  default true
     * @param  int  $max  Maximum length
     * @param  bool|null  $verifyMailServer  Whether to ask DNS if the domain actually
     *                                       accepts mail. It is a network call inside
     *                                       validation, so it is off under test — where
     *                                       fixture domains have no MX record and the
     *                                       suite would need a working resolver to pass.
     *                                       Pass true to exercise it deliberately.
     */
    public function __construct(
        private readonly bool $required = true,
        private readonly int $max = 190, //
        private readonly ?bool $verifyMailServer = null
    ) {
        $this->loadDisposableList();
    }

    protected function loadDisposableList(): void
    {
        $path = 'disposable_domains.txt';
        if (Storage::exists($path)) {
            $content = Storage::get($path);
            $this->disposableDomains = array_filter(
                array_map(
                    'trim',
                    explode("\n", strtolower($content))
                )
            );
        }
    }

    /**
     * Run the validation rule.
     *
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        // Check if the field is required and the value is null
        if ($this->required && $value === null) {
            $fail('The :attribute field is required.');

            return;
        }

        // Check if the value exceeds the maximum length
        if ($value !== null && \strlen($value) > $this->max) {
            $fail("The :attribute should not be greater than {$this->max} characters.");

            return;
        }

        // Basic syntax check
        if ($value !== null && ! filter_var($value, FILTER_VALIDATE_EMAIL)) {
            $fail('The :attribute must be a valid email address.');
        }

        // Extract domain
        $domain = strtolower(substr(strrchr($value, '@'), 1));

        // Check disposable domains
        if (\in_array($domain, $this->disposableDomains, true)) {
            $fail('Use a valid email provider.');
        }

        // Check DNS MX record (mail server exists)
        if (app()->isProduction() && $this->shouldVerifyMailServer() && ! checkdnsrr($domain, 'MX')) {
            $fail('The email does not appear to accept emails.');
        }
    }

    protected function shouldVerifyMailServer(): bool
    {
        return $this->verifyMailServer ?? ! app()->runningUnitTests();
    }
}
