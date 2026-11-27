<?php

namespace VinVui\Stats;

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\ServiceProvider;

class StatsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/stats.php', 'stats');
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'stats');
        Blade::anonymousComponentPath(__DIR__.'/../resources/views/components', 'stats');

        $this->publishes([__DIR__.'/../config/stats.php' => config_path('stats.php')], 'stats-config');
    }
}
