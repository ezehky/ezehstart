<?php

namespace App\Providers;

use App\Services\AnalyticsService;
use App\Services\CloudStorageService;
use App\Services\CookieConsentService;
use App\Services\Mail\BrevoTransport;
use App\Services\Mail\MailDeliveryService;
use App\Services\Mail\ResendTransport;
use App\Services\SiteConfigurationService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Email over the Resend and Brevo HTTP APIs — our own transports, so no
        // SDK; Resend's replaces Laravel's, which needs one. The provider used is
        // the admin's pick (MailDeliveryService), applied now and again before
        // each queued job, so a running worker follows a change.
        Mail::extend('resend', fn () => new ResendTransport((string) config('services.resend.key')));
        Mail::extend('brevo', fn () => new BrevoTransport((string) config('services.brevo.key')));
        app(MailDeliveryService::class)->apply();
        Queue::before(fn () => app(MailDeliveryService::class)->apply());

        Builder::macro('searchMacro', function ($columns, $search) {
            if ($search) {
                if (\is_array($columns)) {
                    $this->where(function ($query) use ($columns, $search) {
                        foreach ($columns as $column) {
                            $query->orWhere($column, 'like', "%{$search}%");
                        }
                    });

                    return $this;
                } else {
                    return $this->where($columns, 'like', '%'.$search.'%');
                }
            } else {
                return $this;
            }
        });

        // The R2 disk's public address, from File storage. Before the config is
        // cached below, because that cache resolves the logo's URL on this disk.
        app(CloudStorageService::class)->apply();

        // Site Configuration Service
        $serviceInstance = app(SiteConfigurationService::class);

        // INITIATE SITE CONFIG
        if (! app()->runningInConsole() || app()->runningUnitTests()) {
            $serviceInstance->cacheSiteConfig(true);

            // ===================================For View

            $configs = kSiteConfig(keys: ['logo', 'logo-dark', 'name', 'favicon', 'email', 'phone', 'address', 'social-handles']);

            // $configs['socials'] = $serviceInstance->getSocialHandles(data: $configs['social-handles']);

            View::share(['_configs' => $configs]);
        }

        // The cookie banner, the Google Analytics tag and the footer's "Cookie
        // settings" link, each handed what it shows rather than asking a service
        // from the view. The tag needs a route to tell a workspace from the public
        // site; without one (an error page) it prints nothing.
        View::composer('components.site.cookie-banner', fn ($view) => $view->with(
            'banner', app(CookieConsentService::class)->banner(),
        ));
        View::composer('components.site.analytics', fn ($view) => $view->with(
            'analytics', request()->route() ? app(AnalyticsService::class)->tag() : null,
        ));
        View::composer('components.site.footer', fn ($view) => $view->with(
            'cookieSettings', app(CookieConsentService::class)->asksForConsent(),
        ));
    }
}
