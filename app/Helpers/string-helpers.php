<?php

if (! function_exists('kStripDomainProtocols')) {
    /**
     * Strip the protocol (http:// or https://) from a URL and return the domain.
     *
     * @param  string|null  $url  The URL to process. If null, uses the app URL from config.
     * @param  string|null  $prefix  Optional prefix to prepend to the domain (e.g., for subdomains).
     * @param  string  $character  Character to use between prefix and domain (default: '@').
     * @param  string|null  $protocol  Optional protocol to prepend to the domain (e.g., 'https://').
     * @return string The domain without protocol.
     */
    function kStripDomainProtocols(
        ?string $url = null,
        // Optional parameters for subdomain generation
        ?string $prefix = null,
        string $character = '@',
        ?string $protocol = null
    ): string {
        $url ??= config('app.url');
        $url = trim(substr($url, strpos($url, '://') + 3), '/');

        // Drop any port. A local APP_URL carries one, and "info@localhost:8000" is
        // not an address any validator will accept.
        $url = (string) preg_replace('/:\d+$/', '', $url);

        // If a prefix is provided, generate a subdomain
        if ($prefix) {
            $url = "{$protocol}{$prefix}{$character}{$url}";
        }

        return $url;
    }
}

if (! function_exists('kSlug')) {
    /**
     * Generate a URL-friendly "slug" from a string.
     *
     * Replaces & with "and" and @ with "at" by default.
     *
     * @param  string  $value  The input string to convert to a slug.
     * @param  string  $separator  The separator to use in the slug (default: '-').
     * @return string The slugified string.
     */
    function kSlug(string $value, string $separator = '-'): string
    {
        return str($value)->slug($separator, dictionary: ['&' => 'and', '@' => 'at']);
    }
}

if (! function_exists('kTextCompare')) {
    /**
     * Compare text or arrays of text in a case-insensitive manner.
     *
     * Can either compare a single value with a string, or ensure all array values match each other.
     *
     * @param  mixed  $firstPart  String or array to compare.
     * @param  string|null  $text  Optional string to compare against (for $compare = true).
     * @param  bool  $compare  If true, compare $firstPart with $text. If false, check array values for equality.
     * @return bool True if comparison passes, false otherwise.
     */
    function kTextCompare(mixed $firstPart, ?string $text = '', bool $compare = true): bool
    {
        if ($compare) {
            if (is_array($firstPart)) {
                // CASE-INSENSITIVE IN ARRAY
                return in_array(strtolower($text), array_map('strtolower', $firstPart));
            }

            // CASE INSENSITIVE STRING COMPARISON
            return ! strcasecmp($text, (string) $firstPart);
        }
        // COMPARE: FALSE => ENSURE ARRAY
        if (! is_array($firstPart) || empty($firstPart)) {
            return false;
        }
        // COMPARE ARRAY VALUES
        $firstValue = current($firstPart);
        foreach ($firstPart as $value) {
            if (strcasecmp($firstValue, $value)) {
                return false;
            }
        }

        return true;
    }
}

if (! function_exists('kBreakText')) {
    /**
     * Replace dashes and underscores with spaces and optionally convert to title case.
     *
     * @param  string|null  $text  Input string.
     * @param  bool  $titleCase  If true, convert to title case.
     * @return string|null Formatted string or null if input is empty.
     */
    function kBreakText(?string $text, bool $titleCase = true, bool $lowercase = false): ?string
    {
        if (! $text) {
            return null;
        }

        $text = str($text)->replace('-', ' ');
        $text = str($text)->replace('_', ' ');

        // Handle title case and ASCII conversion if requested
        if ($titleCase) {
            $text = str($text)->title();
            $text = str($text)->ascii();
        }

        // Handle lowercase conversion if requested
        if ($lowercase) {
            $text = str($text)->lower();
        }

        return $text;
    }
}

if (! function_exists('kReferenceId')) {
    /**
     * Generate a reference ID string with optional alphanumeric and mixed characters.
     *
     * @param  string  $prefix  Prefix for the reference (default: 'TXN-').
     * @param  bool  $alphanumeric  Include uppercase letters if true.
     * @param  bool  $mixed  Include lowercase letters if true (only if $alphanumeric is true).
     * @return string Generated reference ID with date.
     */
    function kReferenceId(string $prefix = 'TXN-', bool $alphanumeric = false, bool $mixed = false): string
    {
        $string = '123456789';
        if ($alphanumeric) {
            $string .= 'ABCDEFGHIJKLMNPQRSTUVWXYZ';
            if ($mixed) {
                $string .= 'abcdefghijklmnpqrstuvwxyz';
            }
        }

        // GENERATE RANDOM STRING
        $firstShuffle = str()->substr(str_shuffle($string), 0, 3);
        $secondShuffle = str()->substr(str_shuffle($string), 0, 3);

        // RETURN REFERENCE ID
        return "{$prefix}{$firstShuffle}{$secondShuffle}".date('Ymd');
    }
}

if (! function_exists('kGreeting')) {
    /**
     * Return a greeting based on the current hour of the day.
     *
     * @param  string  $name  Name to greet (default: 'Guest').
     * @return string Greeting string, e.g., "Good Morning, John!".
     */
    function kGreeting(string $name = 'Guest', bool $exclamation = true): string
    {
        $hour = (int) date('H');

        if ($hour < 12) {
            $greet = 'Good Morning';
        } elseif ($hour < 18) {
            $greet = 'Good Afternoon';
        } else {
            $greet = 'Good Evening';
        }

        // Append the name to the greeting
        $greet = "{$greet}, {$name}";

        // Return the greeting with or without an exclamation mark based on the $exclamation parameter
        return $exclamation ? "$greet!" : $greet;
    }
}

if (! function_exists('kConvertToString')) {
    /**
     * Convert any value (null, int, float, bool, array, object) to a string.
     *
     * @param  mixed  $value  The value to convert.
     * @return string String representation of the value.
     */
    function kConvertToString(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        if (is_string($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value) || is_bool($value)) {
            return (string) $value;
        }

        if (is_array($value)) {
            return json_encode($value, JSON_UNESCAPED_UNICODE);
        }

        if (is_object($value)) {
            // If object can be casted
            if (method_exists($value, '__toString')) {
                return (string) $value;
            }

            return json_encode($value, JSON_UNESCAPED_UNICODE);
        }

        return '';
    }

}

if (! function_exists('kPluralize')) {
    /**
     * Return a pluralized string with optional count prepended.
     *
     * @param  string  $string  Word to pluralize.
     * @param  int  $count  Count to determine pluralization.
     * @param  string|bool  $prepend  Prepend the count or a custom string (default: true = number prepended).
     * @param  bool  $format  Format the number if prepending (default: true).
     * @return string Pluralized string with optional prepended count.
     */
    function kPluralize(string $string, int $count, string|bool $prepend = true, bool $format = true): string
    {
        $addPrepend = '';
        if ($prepend) {
            $addPrepend = (
                is_bool($prepend) ? (
                    $format ? number_format($count) : $count
                ) : $prepend
            ).' ';
        }

        return $addPrepend.($count ? str($string)->plural($count) : $string);
    }
}

if (! function_exists('kRemoveUnicode')) {
    /**
     * Remove non-ASCII characters and Unicode symbols from a string.
     *
     * @param  string  $text  Input string.
     * @return string Cleaned string with only ASCII characters.
     */
    function kRemoveUnicode(string $text): string
    {
        // Decode escaped Unicode (optional, if you want to see the emoji)
        $text = json_decode('"'.$text.'"');

        // Remove non-printable / emoji characters
        $clean = preg_replace('/[^\x00-\x7F]+/u', '', $text);

        return trim($clean);
    }
}

// |||||||||||||||||||||||||||||||||||||||||||||||||||||||||||||||||||||||
// MARKDOWN
if (! function_exists('kMarkdownFormat')) {
    /**
     * Get markdown syntax reference guide.
     *
     * @return array List of markdown patterns and their meanings.
     */
    function kMarkdownFormat(): array
    {
        return [
            ['#text', 'Heading 1. (No. of # determines the Heading)'],
            ['**text** or __text__', 'Bold Text'],
            ['_text_ or *text*', 'Italic Text'],
            ['<ins>text</ins>', 'Underlined text'],
            ['~~text~~', 'Strikethrough Text'],
            ['<br>', 'Break line'],
            ['double-space-down', 'Create a paragraph text'],
            ['***', 'Horizontal Rule(Line)'],
            ['[Title](url)', 'Will create a plain link'],
        ];
    }
}
