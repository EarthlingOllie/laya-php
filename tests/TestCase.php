<?php

declare(strict_types=1);

namespace EarthlingOllie\SystemOneClassification\Tests;

use EarthlingOllie\SystemOneClassification\Tests\Support\ComposerManifest;
use Illuminate\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Sleep;
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
     * Retries wait through Illuminate\Support\Sleep (Laravel\LaravelSleeper), faked here so no
     * Feature test really waits and any can assert the backoff with Sleep::assertSequence().
     */
    protected function setUp(): void
    {
        parent::setUp();

        Sleep::fake();
    }

    /**
     * @param  Application  $app
     * @return list<class-string<ServiceProvider>>
     */
    protected function getPackageProviders($app): array
    {
        return ComposerManifest::providers();
    }
}
