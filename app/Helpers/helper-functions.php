<?php

use App\Models\User;
use App\Services\SiteConfigurationService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

// |||||||||||||||||||||||||||||||||||||||||||||||||||||
// IMAGES & FILES
if (! function_exists('kSafeImage')) {
    /**
     * Get a safe image URL with fallback and optional storage or site URL prefix.
     *
     * @param  string|null  $name  Image path or URL from database.
     * @param  string|null  $altImage  Optional fallback type (e.g. 'user').
     * @param  bool  $useStorage  Whether to resolve via Storage::url().
     * @param  bool  $prependSiteAddress  Whether to prepend the app URL to the image.
     * @param  string  $disk  Storage disk to use (default: 'public').
     * @return string A valid image URL (never broken).
     */
    function kSafeImage(
        ?string $name = null,
        ?string $altImage = null,
        bool $useStorage = true,
        bool $prependSiteAddress = false,
        string $disk = 'public'
    ): string {
        // Base site address if needed
        $prependAddress = $prependSiteAddress ? (rtrim(config('app.url'), '/').'/') : '';

        // Default "no image" placeholder
        $noImage = asset('images/image.png');

        // Initialize output
        $output = '';

        // If a name was provided, try to resolve it
        if (! empty($name)) {
            $output = $useStorage ? Storage::disk($disk)->url($name) : $name;

            if ($prependSiteAddress) {
                $output = $prependAddress.ltrim($output, '/');
            }
        }

        // If still empty or invalid, use fallback
        if (empty($output)) {
            $output = $noImage;
        }

        return $output;
    }
}
if (! function_exists('kStoreFile')) {
    /**
     * Store a given uploaded file to the specified public path.
     *
     * @param  UploadedFile  $file  Uploaded file instance.
     * @param  string|null  $filename  Custom filename (optional).
     * @param  string  $path  Storage path (default "/").
     * @param  string  $disk  Storage disk (default "public").
     * @return string Path where the file was stored.
     *
     * @throws RuntimeException When the disk refuses the write.
     */
    function kStoreFile(
        $file,
        ?string $filename = null,
        string $path = '/',
        string $disk = 'public'
    ): string {
        // GENERATE NEW NAME
        if ($filename) {
            $filename = kSlug($filename)
                .'_'.now()->format('Ymdhi')
                .'.'.$file->getClientOriginalExtension();
        }

        // STORE FILE. A disk that refuses the write hands back false, which would
        // otherwise be cast to '' and saved as a valid-looking empty path.
        $stored = $filename ? $file->storeAs($path, $filename, $disk) : $file->store($path, $disk);

        if ($stored === false) {
            throw new RuntimeException("Could not store the uploaded file on the [{$disk}] disk.");
        }

        return $stored;
    }
}
if (! function_exists('kDeleteFile')) {
    /**
     * Delete a stored file from the public disk.
     *
     * @param  string|null  $file  File path relative to the storage disk.
     * @param  string  $disk  Storage disk to use (default: 'public').
     * @return bool True if deleted, false otherwise.
     */
    function kDeleteFile(?string $file = null, string $disk = 'public'): bool
    {
        return $file ? Storage::disk($disk)->delete($file) : false;
    }
}

// ||||||||||||||||||||||||||||||||||||||||||||||||||||||||||||||||||
// DATETIME
if (! function_exists('kDatetimeConverter')) {
    /**
     * Convert and format a datetime value for display or comparison.
     *
     * @param  Carbon|string|null  $datetime  Datetime string or Carbon instance.
     * @param  User|null  $user  Optional user with timezone property.
     * @param  bool  $dateFormat  Return formatted date string.
     * @param  bool  $dtFormat  Return formatted date + time string.
     * @param  bool  $compareGT  Compare if greater than now.
     * @param  bool  $compareLT  Compare if less than now.
     * @param  bool  $showTZ  Append timezone abbreviation to output.
     * @param  string|null  $timezone  Force a specific timezone.
     * @param  string|null  $format  Return date format string.
     * @return bool|Carbon|string Formatted datetime, boolean result, or Carbon instance.
     */
    function kDatetimeConverter(
        Carbon|string|null $datetime,
        ?User $user = null,
        bool $dateFormat = false,
        bool $dtFormat = false,
        bool $diffForHumans = false,
        bool $compareGT = false,
        bool $compareLT = false,
        bool $showTZ = false,
        ?string $timezone = null,
        ?string $format = null,
        bool $addDaySymbol = false
    ): bool|Carbon|string {
        if (! $datetime) {
            return '-';
        }
        // ENSURE TIMEZONE
        $timezone ??= ($user?->timezone ?? kSiteConfig('timezone', default: 'UTC'));

        // ALWAYS CONVERT TO CARBON OBJECT
        $datetime = $datetime instanceof Carbon ? $datetime : Carbon::parse($datetime);
        $datetime = $datetime->timezone($timezone);

        // COMPARISON
        if ($compareGT) {
            return $datetime->greaterThan(now($timezone));
        }
        if ($compareLT) {
            return $datetime->lessThan(now($timezone));
        }

        // SHOW TIMEZONE
        $withTimeZone = $showTZ ? " ({$timezone})" : '';

        // RETURN DIFF FOR HUMANS
        if ($diffForHumans) {
            return $datetime->diffForHumans();
        }

        // RETURN TO DEFAULT FORMAT
        if ($format) {
            return $datetime->format($format).$withTimeZone;
        }

        // RETURN FORMATTED DATE
        if ($dateFormat) {
            $format = $addDaySymbol ? 'D, jS M, Y' : 'M jS, Y';

            return $datetime->format($format).$withTimeZone;
        }

        // RETURN FORMATTED DATETIME
        if ($dtFormat) {
            $format = $addDaySymbol ? 'D, jS M, Y • h:ia' : 'M jS, Y • h:ia';

            return $datetime->format($format).$withTimeZone;
        }

        // RETURN CARBON OBJECT
        return $datetime;
    }
}

// ||||||||||||||||||||||||||||||||||||||||||||||||||||||||||||||||||
// EXTRAS
if (! function_exists('kSiteConfig')) {
    /**
     * Fetch a site configuration value via the SiteConfigurationService.
     *
     * @param  string  $key  Configuration key.
     * @param  array  $keys  Optional array of keys for nested configurations.
     * @param  mixed  $default  Default value if not found.
     * @return mixed Configured value or default.
     */
    function kSiteConfig(string $key = '', array $keys = [], mixed $default = [])
    {
        return app(SiteConfigurationService::class)->getConfigs($key, $keys, $default);
    }
}

// COMPARE PRICE
if (! function_exists('kStoreComparePrice')) {
    /**
     * Return the higher of two prices.
     */
    function kStoreComparePrice(float $price, ?float $compare_price = 0): float
    {
        $compare_price ??= 0;

        return ($compare_price > $price) ? $compare_price : $price;
    }
}
