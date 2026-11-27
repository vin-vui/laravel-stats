<?php

namespace VinVui\Stats;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\ServiceProvider;
use VinVui\Stats\Console\AggregateCommand;
use VinVui\Stats\Console\ImportPlausibleCommand;
use VinVui\Stats\Http\Middleware\TrackVisits;

class StatsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/stats.php', 'stats');
        $this->app->singleton(Recorder::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'stats');
        Blade::anonymousComponentPath(__DIR__.'/../resources/views/components', 'stats');

        $this->publishes([__DIR__.'/../config/stats.php' => config_path('stats.php')], 'stats-config');

        // Comptage automatique de toutes les pages du groupe « web »
        if (config('stats.enabled', true)) {
            $this->app->make(Router::class)->pushMiddlewareToGroup('web', TrackVisits::class);
        }

        if ($this->app->runningInConsole()) {
            $this->commands([AggregateCommand::class, ImportPlausibleCommand::class]);
        }

        // Regroupement chaque nuit (nécessite le planificateur Laravel du site)
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
            $schedule->command('stats:aggregate')->dailyAt('00:20')->withoutOverlapping();
        });
    }
}
