<?php

namespace App\Providers;

use App\Models\Store;
use App\Observers\StoreObserver;
use Carbon\Carbon;
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
        // Définir le fuseau horaire pour l'application
        $timezone = config('app.timezone', 'Europe/Paris');
        date_default_timezone_set($timezone);
        Carbon::setLocale('fr');

        Store::observe(StoreObserver::class);

        // Redirige / route() en https derrière un reverse-proxy (X-Forwarded-Proto) ou si APP_URL est déjà https.
        if (! $this->app->runningInConsole()) {
            try {
                $req = request();
                if ($req && ($req->secure() || str_starts_with((string) config('app.url'), 'https://'))) {
                    \Illuminate\Support\Facades\URL::forceScheme('https');
                }
            } catch (\Throwable) {
                // Pas de requête HTTP (tests, artisan).
            }
        }
    }
}
