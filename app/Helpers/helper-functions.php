<?php

use App\Enums\GateAccessEnum;
use App\Models\User;
use App\Services\GateService;
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
if (! function_exists('kFileSize')) {
    /**
     * A byte count as somebody would say it out loud.
     *
     * Bytes are shown whole; everything above them gets one decimal, because
     * "2.9 MB" tells a person what they need and "2.9138 MB" does not.
     *
     * @param  int|null  $bytes  Size in bytes.
     */
    function kFileSize(?int $bytes = 0): string
    {
        $bytes = max(0, (int) $bytes);

        foreach (['B', 'KB', 'MB', 'GB'] as $unit) {
            if ($bytes < 1024) {
                return round($bytes, $unit === 'B' ? 0 : 1).' '.$unit;
            }

            $bytes /= 1024;
        }

        return round($bytes, 1).' TB';
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

if (! function_exists('kSiteFlag')) {
    /**
     * Read an on/off switch out of a site-configuration group.
     *
     * kSiteConfig() cannot do this correctly. Its `$default` is applied whenever
     * the value is falsy, so a switch an administrator deliberately turned *off*
     * comes back as the default — which for a security feature means "off" reads
     * as "on". This checks for the key's presence instead, so an unconfigured
     * install falls back and a configured `false` is honoured.
     *
     * @param  string  $group  The configuration group, e.g. 'security' or 'uploads'.
     * @param  string  $key  The key within that group.
     * @param  mixed  $default  What an install that has never saved this uses.
     */
    function kSiteFlag(string $group, string $key, mixed $default = false): mixed
    {
        $values = kSiteConfig($group);

        return \is_array($values) && \array_key_exists($key, $values)
            ? $values[$key]
            : $default;
    }
}

if (! function_exists('kGate')) {
    /**
     * May this account reach $resource, at least as far as $level?
     *
     * $resource is a navigation key — 'content', or 'users.roles' for a child. A
     * child with no gate of its own inherits its parent's, so asking for the child
     * is always safe.
     *
     * Ask for the *lowest* level the caller needs: a listing asks for VIEW, the
     * delete button asks for FULL. An account with more than it was asked for passes.
     *
     * @param  string  $resource  The navigation key being guarded.
     * @param  GateAccessEnum|string  $level  The minimum access the caller needs.
     * @param  User|null  $user  Defaults to the signed-in account.
     */
    function kGate(string $resource, GateAccessEnum|string $level = GateAccessEnum::VIEW, ?User $user = null): bool
    {
        $user ??= auth()->user();

        if (! $user instanceof User) {
            return false;
        }

        $level = $level instanceof GateAccessEnum
            ? $level
            : (GateAccessEnum::tryFrom($level) ?? GateAccessEnum::VIEW);

        return app(GateService::class)->allows($user, $resource, $level);
    }
}

if (! function_exists('kGateAccess')) {
    /**
     * How far this account may go inside $resource, rather than a yes/no.
     *
     * For a screen that renders the same page differently at each level — read-only
     * at VIEW, with an Add button at CREATE — one call here beats three kGate() calls.
     */
    function kGateAccess(string $resource, ?User $user = null): GateAccessEnum
    {
        $user ??= auth()->user();

        return $user instanceof User
            ? app(GateService::class)->accessFor($user, $resource)
            : GateAccessEnum::NONE;
    }
}

if (! function_exists('kPageGate')) {
    /**
     * Guard an admin screen. Call it in mount(), next to kSetSiteTitle().
     *
     * A 404 rather than a 403, for the same reason the workspace middleware returns
     * one: the shape of the admin surface is not something a refused account gets to
     * map. The sidebar hides what it hides as a courtesy — this is the boundary.
     *
     * Non-admin routes pass straight through. The image and video libraries are the
     * same screen in both workspaces, so the decision has to come from the route that
     * reached it, not from the roles the account happens to carry.
     *
     * @param  string  $resource  The navigation key this screen sits under.
     * @param  GateAccessEnum|string  $required  The minimum access needed to open it.
     */
    function kPageGate(string $resource, GateAccessEnum|string $required = GateAccessEnum::VIEW): void
    {
        if (! request()->routeIs('admin.*')) {
            return;
        }

        abort_unless(kGate($resource, $required), 404);
    }
}

if (! function_exists('kGateAction')) {
    /**
     * May this account use one control on the screen it is looking at?
     *
     * kGate() with the escape hatch the shared screens need: an account in an ungated
     * workspace passes straight through. The image and video libraries are one screen
     * in both workspaces, and the member workspace has no gate keys at all — asking
     * kGate() there answers no and would take the upload button away from every member.
     *
     * The question is asked of the *account*, not of the route, which is the one
     * difference from kPageGate(). kPageGate() runs in mount(), on the request that
     * opened the page, so `admin.*` is a fair test there. A control is re-rendered on
     * every Livewire update as well, and those arrive on the livewire.update route —
     * a route test would answer "not an admin route" and hand every hidden button
     * back the first time somebody typed in a search box.
     *
     * This is what the gated button and menu-item components call, so a control is
     * safe to write once and drop into either workspace.
     *
     * @param  string  $resource  The navigation key the control sits under.
     * @param  GateAccessEnum|string  $level  The minimum access it needs.
     */
    function kGateAction(string $resource, GateAccessEnum|string $level = GateAccessEnum::VIEW, ?User $user = null): bool
    {
        $user ??= auth()->user();

        // Nobody signed in reaches a gated control. A guest is not "an ungated
        // workspace", it is no workspace at all.
        if (! $user instanceof User) {
            return false;
        }

        if (! $user->user_type->carriesRole()) {
            return true;
        }

        return kGate($resource, $level, $user);
    }
}

if (! function_exists('kFluxIcons')) {
    /**
     * Every icon name `<flux:icon>` can render, read from the registered component paths.
     *
     * Flux registers two anonymous component paths under the `flux` prefix — the app's own
     * `resources/views/flux` first, then the package's bundled Heroicons — so reading the
     * registration rather than a hard-coded vendor path keeps the list correct if either
     * side moves, and keeps a published icon ahead of the bundled one of the same name.
     *
     * @param  bool  $grouped  Key the names by their source ("Site icons" / "Heroicons").
     * @return array<int|string, mixed> A sorted list of icon names, or two groups of them.
     */
    function kFluxIcons(bool $grouped = false): array
    {
        // The directory scan is the expensive part, so it is memoized for the request
        // rather than per argument — both shapes are built from the same groups.
        $groups = once(function (): array {
            $groups = [];

            foreach (Blade::getAnonymousComponentPaths() as $registered) {
                if (($registered['prefix'] ?? null) !== 'flux') {
                    continue;
                }

                // Icons published into the app shadow the bundled set, so they are listed first.
                $group = str_starts_with($registered['path'], resource_path()) ? 'Site icons' : 'Heroicons';

                foreach (glob($registered['path'].'/icon/*.blade.php') ?: [] as $file) {
                    $name = basename($file, '.blade.php');

                    // `index` is the `<flux:icon name="…">` dispatcher itself, not an icon.
                    if ($name !== 'index') {
                        $groups[$group][] = $name;
                    }
                }
            }

            return collect($groups)
                ->map(fn (array $names): array => collect($names)->unique()->sort()->values()->all())
                ->all();
        });

        return $grouped
            ? $groups
            : collect($groups)->flatten()->unique()->values()->all();
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
