<?php

declare(strict_types=1);

namespace EarthlingOllie\SystemOneClassification\Laravel;

use Illuminate\Support\ServiceProvider;

use function dirname;

/**
 * Laravel entry point for the package, auto-discovered through composer.json's
 * `extra.laravel.providers`: merges and publishes config/system-one.php and binds the
 * manager behind the SystemOne facade.
 */
final class SystemOneServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(self::configFile(), 'system-one');

        $this->app->singleton(SystemOneManager::class);
        // The facade's accessor. SystemOne::fake() rebinds only this to the fake, so code that
        // injects the final SystemOneManager itself keeps the real one instead of a TypeError.
        $this->app->alias(SystemOneManager::class, ConnectionResolver::class);
    }

    public function boot(): void
    {
        $this->publishes([self::configFile() => $this->app->configPath('system-one.php')], 'system-one-config');
    }

    private static function configFile(): string
    {
        return dirname(__DIR__, 2).'/config/system-one.php';
    }
}
