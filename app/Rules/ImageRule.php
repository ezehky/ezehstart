<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\UploadedFile;
use Illuminate\Translation\PotentiallyTranslatedString;

class ImageRule implements ValidationRule
{
    public function __construct(
        private readonly bool $required = true,
        private readonly int $size = 300,       // KB
        private readonly array|string|null $addMimes = null,  // extra mime types (comma-separated)
        private readonly array $freshMimes = [],  // extra mime types (comma-separated)
    ) {}

    /**
     * Run the validation rule.
     *
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        // Handle required
        if ($this->required && $value === null) {
            $fail('The :attribute field is required!');

            return;
        }

        // Skip checks if nullable and no value given
        if ($value === null) {
            return;
        }

        // Ensure it's an uploaded file
        if (! $value instanceof UploadedFile) {
            $fail('The :attribute must be a valid file.');

            return;
        }

        // Validate mime types
        $allowedMimes = $this->freshMimes ?: ['jpeg', 'png', 'jpg', 'webp'];
        if ($this->addMimes) {
            $addMimes = \is_array($this->addMimes) ? $this->addMimes : explode(',', $this->addMimes);
            if ($addMimes) {
                $allowedMimes = [...$allowedMimes, ...$addMimes];
            }
        }

        if (! \in_array(strtolower($value->extension()), $allowedMimes, true)) {
            $fail('The :attribute must be an image of type: '.implode(', ', $allowedMimes).'.');
        }

        // Validate size (Laravel's UploadedFile size is in KB)
        if ($value->getSize() / 1024 > $this->size) {
            $fail("The :attribute must not be greater than {$this->size} KB.");
        }
    }
}
