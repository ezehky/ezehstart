<?php

namespace App\Services;

use App\Enums\EmailSenderEnum;
use App\Enums\LogChannelEnum;
use App\Enums\PaymentVendorEnum;
use App\Enums\SocialProviderEnum;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

#[Singleton]
class SiteConfigurationService
{
    // |||||||||||||||||||||||||||||||||||||||||||||||||||||||||||
    // PRIVATE

    /**
     * The shape of site-configuration.json, and the value every key falls back to
     * before an administrator has saved anything.
     *
     * Add a key here first; the admin screen and kSiteConfig() both read this.
     */
    private function initials(): array
    {
        return [
            'name' => config('app.name'),
            'phone' => '',
            'email' => kStripDomainProtocols(prefix: 'info'),
            'contact-email' => kStripDomainProtocols(prefix: 'support'),
            'address' => '',

            // Offices, branches, shops — each a label, an address, any number of
            // phones and emails, and an optional map. The single phone, email and
            // address above stay the site's main contact, the one emails and
            // templates read; these are what the public contact section lists.
            // Empty means no section. See contactLocations().
            //
            // Placeholders, so a fresh install shows what the section looks like.
            // Replace or delete them from Configuration › Contact locations. The
            // maps use Google's output=embed form, which needs no API key.
            'contact-locations' => [
                [
                    'label' => 'Head office',
                    'address' => "12 Allen Avenue\nIkeja, Lagos",
                    'phones' => ['+2348000000001', '+2348000000002'],
                    'emails' => ['hello@example.com', 'sales@example.com'],
                    'map-embed' => 'https://maps.google.com/maps?q=Allen+Avenue%2C+Ikeja%2C+Lagos&output=embed',
                ],
                [
                    'label' => 'Abuja branch',
                    'address' => "3 Aminu Kano Crescent\nWuse II, Abuja",
                    'phones' => ['+2349000000003'],
                    'emails' => ['abuja@example.com'],
                    'map-embed' => 'https://maps.google.com/maps?q=Aminu+Kano+Crescent%2C+Wuse+II%2C+Abuja&output=embed',
                ],
                [
                    'label' => 'Support desk',
                    'address' => '',
                    'phones' => ['+2347000000004'],
                    'emails' => ['support@example.com'],
                    'map-embed' => '',
                ],
            ],
            'timezone' => config('app.timezone'),
            'logo' => null,
            'logo-dark' => null,
            'favicon' => null,
            'whatsapp-support' => null,
            'telegram-support' => null,
            'social-handles' => [],

            // A processing fee added on top of a purchase paid through a gateway,
            // one per vendor because each prices its service differently. Percent
            // of the price, plus a flat amount, held under a cap (0 means no cap);
            // flat and cap are in the default currency, the one the ledger keeps.
            // All zero: what to pass on to a customer is the owner's decision,
            // so nothing is added until somebody sets it on Payments › Gateways.
            'payments' => [
                'fees' => collect(PaymentVendorEnum::cases())
                    ->reject(fn (PaymentVendorEnum $vendor) => $vendor->isManual())
                    ->mapWithKeys(fn (PaymentVendorEnum $vendor) => [
                        $vendor->value => ['percent' => 0, 'flat' => 0, 'cap' => 0],
                    ])
                    ->all(),
            ],

            'email-settings' => [
                'verification' => true,
                'verification-strict' => true,

                // Accounts that never verify. Warned once the notice days have
                // passed since sign-up, then removed once the delete days have —
                // counted from the warning, so nobody is removed unwarned. Only
                // ever runs while verification is on: with it off, every account
                // is unverified and the sweep would take all of them.
                'unverified-auto-delete' => true,
                'unverified-notice-days' => 2,
                'unverified-delete-days' => 3,
            ],
            'user' => [
                'account-deletion' => true,
                'account-deletion-days' => 30,

                // What the end of the grace period does to an account that has
                // history behind it. On keeps the row with its personal details
                // stripped; off removes the account and everything it owns. An
                // account with no history is removed outright either way.
                'anonymous-after-deletion' => true,

                // Whether an account holder may download a copy of what is held
                // about them. On by default, because the deletion right ships on
                // by default and half of a data right is a worse position than
                // neither — an install that must withhold it can turn it off.
                'allow-data-download' => true,

                // A "we missed you" email to a member who has not been seen for
                // this many days. Sent once per absence, not once per day of it.
                'inactivity-reminder' => true,
                'inactivity-reminder-days' => 30,
            ],

            // Every switch here turns a whole sign-in feature on or off, so the
            // screens that read them must treat a false as "this does not exist"
            // rather than "hide the button" — a route left reachable behind a
            // hidden button is not a disabled feature.
            'security' => [
                // Enforces the length, mixed-case, digit and symbol rules in
                // WithPasswordTools rather than Laravel's default minimum.
                'strong-password' => true,
                'password-min-length' => 5,

                // Refuse a password the user has already had. Depth is how many
                // previous hashes are kept and compared; every hash past it is
                // pruned, because holding them forever is a liability with no
                // matching benefit.
                'password-history' => true,
                'password-history-depth' => 5,

                'two-factor' => false,
                'passwordless-login' => true,

                // Passkeys. On because nothing about them needs a key in .env, and
                // off closes enrolment and sign-in together. A browser only offers
                // them over HTTPS or on localhost, so an install served over plain
                // http on a real hostname should switch this off until it is not.
                'passkeys' => true,

                // Social sign-in. The master switch, then one switch per provider
                // so a provider that has credentials can still be taken off the
                // sign-in page without anybody editing .env. Keyed by the enum case
                // value and built from the cases, so adding a provider adds its
                // switch here and on the admin screen at the same time.
                'socialite' => false,
                'social-providers' => collect(SocialProviderEnum::cases())
                    ->mapWithKeys(fn (SocialProviderEnum $provider) => [$provider->value => true])
                    ->all(),

                // The captcha on the guest forms. Off until somebody turns it on,
                // and it stays off regardless while the Turnstile keys are missing
                // from the environment — see CaptchaService::isAvailable().
                'captcha' => false,

                // The login throttle. Deliberately configurable so a site under a
                // live attack can be tightened without a deploy — but the defaults
                // are the values to keep unless there is a reason not to.
                'login-max-attempts' => 5,
                'login-decay-minutes' => 1,

                // How many days of audit trail to keep. Zero means forever, and is the
                // default: an install should not quietly start throwing away the log
                // somebody will one day need, so the window is opted into.
                'activity-log-retention-days' => 0,
            ],

            // Limits applied to users only. Administrators upload against the
            // filesystem, not against a quota.
            'uploads' => [
                'user-image-limit' => 50,
                'max-image-size' => 2048, // kilobytes
                'optimize-images' => true,

                // A video costs no disk, so the ceiling is lower for a different
                // reason: a library nobody can find anything in is not a library.
                'user-video-limit' => 25,

                // Telling a member when an administrator touched their files.
                // Both channels are separate switches because they cost the
                // recipient different amounts of attention.
                'modification' => [
                    'email' => true,
                    'in-app' => true,
                ],
            ],

            // The interface language. The default is what a visitor sees before
            // they pick, and what everybody sees with the switcher off. Only a
            // language with a lang/{code}.json can be either — see LocaleEnum.
            'localization' => [
                'default' => config('app.locale'),
                'switcher' => true,
            ],

            'preferences' => [
                'accept-cookies' => true,
                'newsletter' => [
                    'status' => true,
                    'popup' => true,
                    'popup-delay' => 5, // seconds
                    'footer' => true,
                ],

                // A bottom bar on phones in the member workspace, instead of
                // reaching for the drawer. Off by default: an install that wants
                // only the drawer should not have to turn a second navigation off.
                'mobile-floating-menu' => false,
            ],

            'email-senders' => collect(EmailSenderEnum::cases())
                ->mapWithKeys(fn (EmailSenderEnum $sender) => [
                    $sender->value => [
                        'from' => $sender->isCustom() ?
                            config('app.url') :
                            kStripDomainProtocols(prefix: $sender->value),
                        'from-name' => null,
                        'reply-to' => null,
                        'reply-to-name' => null,
                    ],
                ])
                ->toArray(),
        ];
    }

    /**
     * Lay the stored values over the defaults, recursing only into keyed groups.
     *
     * A list is taken whole from whichever side has it. array_replace_recursive()
     * merges lists by position, so a stored list shorter than its default would
     * get the default's tail back — two contact locations deleted down to one
     * would return as three on the next save, and none of them could be removed.
     */
    private function mergeInitials(array $initials, array $stored): array
    {
        foreach ($stored as $key => $value) {
            $initial = $initials[$key] ?? null;

            $initials[$key] = \is_array($value) && \is_array($initial) && ! array_is_list($value) && ! array_is_list($initial)
                ? $this->mergeInitials($initial, $value)
                : $value;
        }

        return $initials;
    }

    private function setFile(): string
    {
        $file = 'site-configuration.json';
        // CHECK IF THE FILE EXITS. CREATE ONE IF NOT.
        if (! Storage::exists($file)) {
            Storage::put($file, '[]');
        }

        return $file;
    }

    private function getUploadFile(?string $key = null, ?string $real_path = null, array $data = [], bool $image = true): ?string
    {
        $file_path = null;

        // GET FILE PATH BY KEY
        if ($key) {
            $file_path = $data ? data_get($data, $key) : $this->getConfigs($key, default: null, raw: true);
        }

        // USE REAL PATH
        if ($real_path) {
            $file_path = $real_path;
        }

        // FIND FILE
        if ($file_path && Storage::disk('public')->exists($file_path)) {
            return $image ? kSafeImage($file_path, useStorage: true) : Storage::url($file_path);
        }

        // CHECK IF ITS IMAGE
        return $image ? kSafeImage(altImage: $key) : null;
    }

    private function getRawData()
    {
        // READ THE CONTENT OF THE JSON FILE
        $jsonData = Storage::get($this->setFile());
        // DECODE THE JSON DATA TO AN ASSOCIATIVE ARRAY
        $data = json_decode($jsonData, true);
        // CHECK JSON ERROR
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \Exception('Invalid JSON in site-configuration.json');
        }

        return $data ?: [];
    }

    private function setSiteConfigForCache(array $data): array
    {
        // A site that uploaded its own logo but no dark one would otherwise show
        // the kit's placeholder in dark mode, which is somebody else's mark. Its
        // own logo on a dark ground is the nearer answer.
        if (filled(data_get($data, 'logo')) && blank(data_get($data, 'logo-dark'))) {
            data_set($data, 'logo-dark', data_get($data, 'logo'));
        }

        // Favicon & Logo
        foreach ([
            'logo',
            'logo-dark',
            'favicon',
        ] as $key) {
            data_set($data, $key, $this->getUploadFile($key, data_get($data, $key), $data));
        }

        foreach ([
            'whatsapp-support' => true,
            'telegram-support' => false,
        ] as $key => $isWhatsApp) {
            data_set($data, $key, $this->normalizeSupportUrl(data_get($data, $key), $isWhatsApp));
        }

        return $data;
    }

    // |||||||||||||||||||||||||||||||||||||||||||||||||||||||||||
    // PUBLIC

    public function cacheSiteConfig(bool $updateMeta = false, bool $injectConfig = false, array $data = []): array
    {
        // FORGET: site_configuration
        // cache()->forget('site_configuration');

        if ($data || $injectConfig) {
            $configs = cache()->rememberForever('site_configuration', function () use ($data) {
                $siteConfig = $data ?: $this->getRawData();

                $siteConfig = $this->setSiteConfigForCache($siteConfig);

                // Logging the site configuration for debugging purposes
                Log::channel(LogChannelEnum::SITE_CONFIG->value)
                    ->info('site config re-cached.', [
                        'user' => auth()->user()?->name,
                        'user_id' => auth()->id(),
                        'siteConfig' => $siteConfig,
                    ]);

                return $siteConfig;
            });

            // Inject into Laravel's config system
            if ($injectConfig) {
                config(['_site-config' => $configs]);
            }
        }
        //
        else {
            $configs = config('_site-config', []);

            if (! $configs) {
                $configs = $this->cacheSiteConfig(injectConfig: true);
            }
        }

        return $configs;
    }

    public function getConfigs(
        string|array $key = '',
        string|array $keys = [],
        mixed $default = null,
        bool $mergeInitial = false,
        bool $raw = false,
    ) {
        $siteConfig = $raw ? $this->getRawData() : $this->cacheSiteConfig();
        // CHECK IF A KEY WAS SENT
        if ($key) {
            // CHECK IF IT EXISTS
            if (Arr::exists($siteConfig, $key)) {
                // CHECK KEYS
                $output = $keys ?
                    data_get($siteConfig, $key.'.'.collect($keys)->implode('.')) :
                    data_get($siteConfig, $key);
            } elseif ($found = data_get($siteConfig, $key)) {
                $output = $found;
            }
            // OUTPUT ASSIGN NULL
            else {
                $output = null;
            }
        } elseif ($keys && \is_array($keys)) {
            $store = [];
            foreach ($keys as $value) {
                $store[$value] = data_get($siteConfig, $value);
            }
            $output = $store;
        } else {
            $output = $siteConfig;
        }
        // MERGE
        if ($output && $mergeInitial) {
            $output = $this->mergeInitials($this->initials(), $output);
        }

        // |||||||||||||||||||||||||||||||||||||||||||||||||
        // |||||||||||||||||||||||||||||||||||||||||||||||||
        // RETURN
        return $output ?: ($default ?? $output);
    }

    public function update(array $data = [], bool $initials = false): bool
    {
        if (! $data && $initials) {
            $data = $this->initials();
        }
        // Encode the data back to JSON format
        $updatedJsonData = json_encode($data, JSON_PRETTY_PRINT);

        // Write the updated JSON data back to the file
        Storage::put($this->setFile(), $updatedJsonData);

        // FORGET CACHED DATA
        cache()->forget('site_configuration');
        // RECACHE DATA, and re-inject it: config('_site-config') was resolved when
        // the request booted, so without this the rest of the request — including the
        // page that just saved — would keep reading the values it replaced.
        $this->cacheSiteConfig(data: $data, injectConfig: true);

        return true;
    }

    public function pushUpdate(array $siteConfig, mixed $data, string $key, ?int $target_key = null): array
    {
        if (\is_int($target_key)) {
            $siteConfig[$key][$target_key] = $data;
        } else {
            $siteConfig[$key][] = $data;
        }

        // SAVE
        $this->update($siteConfig);

        return $siteConfig;
    }

    public function deleteData(string $key, array $config = [], string|array|null $fileKeys = null): array|string
    {
        // CONFIG: an empty argument means "read the stored file yourself".
        $config = $config ?: $this->getConfigs(raw: true);

        // GET DATA
        $data = data_get($config, $key);
        if (! $data) {
            return 'Item not available!';
        }

        // CREATE LOG
        $text = substr($key, 0, strpos("{$key}.", '.'));
        $dirty = ['before' => $data, 'after' => []];

        // PREPARE FILES
        $deleteFiles = [];
        if ($fileKeys) {
            $fKeys = \is_string($fileKeys) ? [$fileKeys] : $fileKeys;
            foreach ($fKeys as $value) {
                if ($file_path = data_get($data, $value)) {
                    $deleteFiles[] = $file_path;
                }
            }
        }
        // DELETE
        $output = Arr::except($config, $key);
        // UPDATE
        $this->update($output);

        // DELETE FILES
        foreach ($deleteFiles as $file) {
            kDeleteFile($file);
        }

        return $output;
    }

    /**
     * The embed link a map frame may point at, or null.
     *
     * Accepts what an administrator is most likely to paste: the whole <iframe>
     * from Google Maps' "Embed a map" dialog, or just its src. Only an https link to
     * a Google Maps or OpenStreetMap embed passes — the result lands in the src of
     * an iframe on the public site, so anything else is refused rather than framed.
     *
     * The admin form stores what this returns, and the public page asks again at
     * render time, so a value edited by hand in the JSON file is held to the same
     * rule as one saved through the form.
     */
    public function mapEmbedUrl(?string $value = null): ?string
    {
        $value = trim((string) $value);

        // A pasted iframe: keep only its src.
        if (preg_match('/<iframe\b[^>]*\bsrc\s*=\s*["\']([^"\']+)["\']/i', $value, $matches)) {
            $value = $matches[1];
        }

        $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5);

        if ($value === '' || ! filter_var($value, FILTER_VALIDATE_URL)) {
            return null;
        }

        $parts = parse_url($value);
        $scheme = strtolower($parts['scheme'] ?? '');
        $host = strtolower($parts['host'] ?? '');
        $path = $parts['path'] ?? '';
        parse_str($parts['query'] ?? '', $query);

        if ($scheme !== 'https') {
            return null;
        }

        $isGoogle = \in_array($host, ['www.google.com', 'google.com', 'maps.google.com'], true) &&
            (str_starts_with($path, '/maps/embed') || (str_starts_with($path, '/maps') && ($query['output'] ?? null) === 'embed'));

        $isOpenStreetMap = \in_array($host, ['www.openstreetmap.org', 'openstreetmap.org'], true) &&
            $path === '/export/embed.html';

        return $isGoogle || $isOpenStreetMap ? $value : null;
    }

    /**
     * The contact locations the public site shows, ready to render.
     *
     * Blank phones and emails are dropped and every map is asked again through
     * mapEmbedUrl(), so a location edited by hand in the JSON file cannot put
     * anything into the page that the admin form would have refused.
     *
     * A config saved before this key existed has no list at all, and then the
     * defaults stand in — the same ones the admin screen merges in, so the site
     * shows what that screen shows. Presence is what is checked, not emptiness:
     * an administrator who deleted every location saved an empty list, and that
     * means no section, not the placeholders back.
     *
     * @return list<array{label: string, address: ?string, phones: list<string>, emails: list<string>, map: ?string}>
     */
    public function contactLocations(): array
    {
        $locations = Arr::exists($this->cacheSiteConfig(), 'contact-locations')
            ? $this->getConfigs('contact-locations', default: [])
            : $this->initials()['contact-locations'];

        return collect((array) $locations)
            ->filter(fn ($location) => \is_array($location))
            ->map(fn (array $location) => [
                'label' => (string) data_get($location, 'label', ''),
                'address' => data_get($location, 'address') ?: null,
                'phones' => array_values(array_filter((array) data_get($location, 'phones', []), 'filled')),
                'emails' => array_values(array_filter((array) data_get($location, 'emails', []), 'filled')),
                'map' => $this->mapEmbedUrl(data_get($location, 'map-embed')),
            ])
            ->values()
            ->all();
    }

    public function normalizeSupportUrl(?string $value = null, bool $isWhatsApp = false): ?string
    {
        // Trim whitespace
        $value = trim($value);

        // If the value is empty after trimming, return null
        if ($value === '') {
            return null;
        }

        // Check if the value already starts with http:// or https://
        if (str_starts_with(strtolower($value), 'http://') || str_starts_with(strtolower($value), 'https://')) {
            return $value;
        }

        // If it's a WhatsApp number, format it as a wa.me link
        if ($isWhatsApp) {
            // Remove all non-digit characters
            $digits = preg_replace('/\D+/', '', $value);

            // Check if we have digits to form a valid WhatsApp link
            if ($digits) {
                return "https://wa.me/{$digits}";
            }
        }

        // If the value starts with '@' and it's not WhatsApp, treat it as a Telegram handle
        if (! $isWhatsApp && str_starts_with($value, '@')) {
            $value = ltrim($value, '@');
        }

        // If the value does not contain a dot or a slash, assume it's a Telegram handle and format it as a t.me link
        if (! $isWhatsApp && ! str_contains($value, '.') && ! str_contains($value, '/')) {
            return "https://t.me/{$value}";
        }

        // For any other case, assume it's a URL and prepend https:// if it doesn't already have a scheme
        return 'https://'.ltrim($value, '/');
    }
}
