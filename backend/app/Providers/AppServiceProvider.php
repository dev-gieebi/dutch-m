<?php

namespace App\Providers;

use App\Services\SettingsService;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Paramètres mis en cache pour la durée de la requête.
        $this->app->scoped(SettingsService::class);
    }

    public function boot(): void
    {
        // Limite globale de l'API (THROTTLE_LIMIT requêtes / minute / IP).
        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(config('amoria.throttle_limit'))->by($request->ip()));
    }
}
