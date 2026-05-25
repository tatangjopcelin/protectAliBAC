<?php

namespace App\Providers;

use App\Models\Store;
use App\Observers\StoreObserver;
use Carbon\Carbon;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
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

        // ----------------------------------------------------------------
        // Rate Limiting — protection contre brute force et surcharge
        // ----------------------------------------------------------------

        // Routes sensibles (login, register, mot de passe) : max 5 tentatives/minute par IP
        RateLimiter::for('auth', function (Request $request) {
            return Limit::perMinute(5)
                ->by($request->ip())
                ->response(function () {
                    return response()->json([
                        'message' => 'Trop de tentatives. Veuillez patienter une minute avant de réessayer.',
                    ], 429);
                });
        });

        // Routes API normales (utilisateur connecté) : max 60 requêtes/minute
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(60)
                ->by($request->user()?->id ?: $request->ip());
        });

        // Routes IA (lourdes, coûteuses) : max 10 requêtes/minute par utilisateur
        RateLimiter::for('ai', function (Request $request) {
            return Limit::perMinute(10)
                ->by($request->user()?->id ?: $request->ip())
                ->response(function () {
                    return response()->json([
                        'message' => 'Limite de requêtes IA atteinte. Veuillez patienter une minute.',
                    ], 429);
                });
        });

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
