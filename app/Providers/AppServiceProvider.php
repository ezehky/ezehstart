<?php

namespace App\Providers;

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
    }
}
