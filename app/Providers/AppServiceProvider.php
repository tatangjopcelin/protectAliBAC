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
    }
}
