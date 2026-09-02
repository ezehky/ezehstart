<?php

namespace App\Providers;

use App\Services\SiteConfigurationService;
use Illuminate\Database\Eloquent\Builder;
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
