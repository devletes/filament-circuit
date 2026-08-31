<?php

namespace Workbench\App\Providers;

use Devletes\Circuit\Support\NodeRegistry;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class WorkbenchServiceProvider extends ServiceProvider
{
    /**
     * What an application does: one registry, discovered from a folder, shared
     * by every canvas and by whatever reads the saved graph.
     */
    public function register(): void
    {
        $this->app->singleton(NodeRegistry::class, fn (): NodeRegistry => NodeRegistry::make()
            ->discoverIn(__DIR__.'/../Workflows/Nodes', 'Workbench\App\Workflows\Nodes'));
    }

    public function boot(): void
    {
        View::addLocation(__DIR__.'/../../resources/views');
    }
}
