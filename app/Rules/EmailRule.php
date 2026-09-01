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

    public function __construct()
    {
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
        // Basic syntax check
        if (! filter_var($value, FILTER_VALIDATE_EMAIL)) {
            $fail('The :attribute must be a valid email address.');
        }

        // Extract domain
        $domain = strtolower(substr(strrchr($value, '@'), 1));

        // Check disposable domains
        if (\in_array($domain, $this->disposableDomains, true)) {
            $fail('Use a valid email provider.');
        }

        // Check DNS MX record (mail server exists)
        if (! checkdnsrr($domain, 'MX')) {
            $fail('The email does not appear to accept emails.');
        }
    }
}
