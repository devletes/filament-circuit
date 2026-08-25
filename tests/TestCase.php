<?php

namespace Devletes\Circuit\Tests;

use BladeUI\Heroicons\BladeHeroiconsServiceProvider;
use BladeUI\Icons\BladeIconsServiceProvider;
use Devletes\Circuit\CircuitServiceProvider;
use Filament\Actions\ActionsServiceProvider;
use Filament\FilamentServiceProvider;
use Filament\Forms\FormsServiceProvider;
use Filament\Infolists\InfolistsServiceProvider;
use Filament\Notifications\NotificationsServiceProvider;
use Filament\Schemas\SchemasServiceProvider;
use Filament\Support\SupportServiceProvider;
use Filament\Tables\TablesServiceProvider;
use Filament\Widgets\WidgetsServiceProvider;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ViewErrorBag;
use Livewire\LivewireServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

/**
 * Boots Filament inside Testbench so the canvas can be exercised as what it
 * actually is — a schema component in a Livewire form — rather than as a bag of
 * methods called by hand.
 */
abstract class TestCase extends Orchestra
{
    protected function setUp(): void
    {
        parent::setUp();

        // A real request gets `errors` from ShareErrorsFromSession; a package
        // test app has no session middleware on Livewire's test route, and
        // Livewire hands the resulting null straight to ViewErrorBag::put().
        View::share('errors', new ViewErrorBag);
    }

    /**
     * The test app needs an encryption key to boot. It is generated per run
     * rather than written into phpunit.xml: a base64 key checked into a public
     * repository is what secret scanners are for, and nothing here depends on
     * the value being stable between runs.
     */
    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
    }

    protected function getPackageProviders($app): array
    {
        // Order matters, and mirrors what package discovery produces in a real
        // app: Filament rebinds Livewire's DataStore to its own subclass with a
        // plain bind(), so Livewire has to register afterwards for its
        // instance() to win. Registered the other way round, every resolve
        // builds a fresh store, nothing Livewire writes to it survives, and
        // validation blows up somewhere far from the cause.
        return [
            BladeIconsServiceProvider::class,
            BladeHeroiconsServiceProvider::class,
            SupportServiceProvider::class,
            ActionsServiceProvider::class,
            SchemasServiceProvider::class,
            FormsServiceProvider::class,
            InfolistsServiceProvider::class,
            TablesServiceProvider::class,
            NotificationsServiceProvider::class,
            WidgetsServiceProvider::class,
            FilamentServiceProvider::class,
            LivewireServiceProvider::class,
            CircuitServiceProvider::class,
        ];
    }
}
