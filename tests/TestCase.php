<?php

declare(strict_types=1);

namespace EarthlingOllie\SystemOneClassification\Tests;

use EarthlingOllie\SystemOneClassification\Tests\Support\ComposerManifest;
use Illuminate\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

/**
 * Base Testbench case for the Feature suite.
 *
 * The providers are read from composer.json's `extra.laravel.providers`, which is exactly
 * what Laravel package auto-discovery registers in a real application.
 */
abstract class TestCase extends Orchestra
{
    /**
     * @param  Application  $app
     * @return list<class-string<ServiceProvider>>
     */
    protected function getPackageProviders($app): array
    {
        return ComposerManifest::providers();
    }
}
