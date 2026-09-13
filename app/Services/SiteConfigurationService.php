<?php

namespace App\Services;

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
            'timezone' => config('app.timezone'),
            'logo' => null,
            'logo-dark' => null,
            'favicon' => null,
            'whatsapp-support' => null,
            'telegram-support' => null,
            'social-handles' => [],
            'email-settings' => [
                'verification' => true,
                'verification-strict' => true,
            ],
            'user' => [
                'account-deletion' => true,
                'account-deletion-days' => 30,

                // What the end of the grace period does to an account that has
                // history behind it. On keeps the row with its personal details
                // stripped; off removes the account and everything it owns. An
                // account with no history is removed outright either way.
                'anonymous-after-deletion' => true,

                // A bottom bar on phones in the member workspace, instead of
                // reaching for the drawer. Off by default: an install that wants
                // only the drawer should not have to turn a second navigation off.
                'mobile-floating-menu' => false,

                // Whether an account holder may download a copy of what is held
                // about them. On by default, because the deletion right ships on
                // by default and half of a data right is a worse position than
                // neither — an install that must withhold it can turn it off.
                'allow-data-download' => true,
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
        ];
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
        return $image ? kSafeImage() : null;
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
        // Favicon & Logo
        foreach ([
            'logo',
            'logo-dark',
            'favicon',
        ] as $key) {
            data_set($data, $key, $this->getUploadFile(real_path: data_get($data, $key)));
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
                Log::channel('site-config')
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
            $output = array_replace_recursive($this->initials(), $output);
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
