<?php

namespace App\Rules;

use App\Services\SiteConfigurationService;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

/**
 * A map link the public site will agree to frame.
 *
 * The check is SiteConfigurationService::mapEmbedUrl(), the same one the contact
 * section runs at render time, so a link that passes here is one that will show.
 * This rule exists to say *why* on the form; the render-time check is the
 * boundary, because a rule only speaks for requests that come through the form.
 */
class MapEmbedRule implements ValidationRule
{
    public function __construct(
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
                $fail('The :attribute field is required!');
            }

            return;
        }

        if (! \is_string($value) || app(SiteConfigurationService::class)->mapEmbedUrl($value) === null) {
            // Telling somebody where the right link lives is the useful part: the
            // address-bar link from Google Maps looks right and is the wrong one.
            $fail('The :attribute must be an embed link from Google Maps (Share › Embed a map) or OpenStreetMap (Share › HTML).');
        }
    }
}
