<?php

namespace App\Rules;

use App\Enums\VideoProviderEnum;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

/**
 * A video URL one of the allowed providers actually serves.
 *
 * The check is the same VideoProviderEnum::resolve() the library and the
 * sanitiser use, so a URL that passes here is one the rest of the stack will
 * accept, and a URL that fails here fails everywhere else too. This rule exists
 * to say *why* on the form; it is not the security boundary, because a rule can
 * only speak for the requests that go through the form.
 */
class VideoUrlRule implements ValidationRule
{
    public function __construct(
        private readonly bool $required = true,
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
                $fail('The :attribute field is required!');
            }

            return;
        }

        if (! \is_string($value)) {
            $fail('The :attribute must be a video link.');

            return;
        }

        if (VideoProviderEnum::resolve($value) === null) {
            // Naming the hosts is the whole point of the message: "invalid URL" on
            // a link somebody copied out of a browser bar tells them nothing about
            // what to do next.
            $hosts = collect(VideoProviderEnum::cases())
                ->map(fn (VideoProviderEnum $provider) => $provider->domainHint())
                ->implode(', ');

            $fail("The :attribute must be a link from {$hosts}.");
        }
    }
}
