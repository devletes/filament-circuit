<?php

namespace Devletes\Circuit;

use Devletes\Circuit\Assets\HashedCss;
use Devletes\Circuit\Assets\HashedJs;
use Filament\Support\Facades\FilamentAsset;
use Illuminate\Support\ServiceProvider;

class CircuitServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/circuit.php', 'circuit');
    }

    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'circuit');

        $this->publishes([
            __DIR__.'/../config/circuit.php' => config_path('circuit.php'),
        ], 'circuit-config');

        $this->publishes([
            __DIR__.'/../resources/views' => resource_path('views/vendor/circuit'),
        ], 'circuit-views');

        FilamentAsset::register([
            HashedCss::make('circuit', __DIR__.'/../resources/css/circuit.css'),
            HashedJs::make('circuit', __DIR__.'/../resources/js/circuit.js'),
        ], package: 'devletes/filament-circuit');
    }
}
