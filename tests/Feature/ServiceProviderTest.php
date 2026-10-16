<?php

declare(strict_types=1);

use EarthlingOllie\SystemOneClassification\Laravel\SystemOneServiceProvider;
use EarthlingOllie\SystemOneClassification\Tests\Support\ComposerManifest;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\PackageManifest;

it('is declared for Laravel package auto-discovery', function (): void {
    expect(ComposerManifest::providers())->toBe([SystemOneServiceProvider::class]);
});

it('is picked up by Laravel package discovery from the installed package metadata', function (): void {
    $composer = ComposerManifest::read();

    $basePath = sys_get_temp_dir().'/system-one-discovery-'.bin2hex(random_bytes(6));
    $files = new Filesystem;
    $files->ensureDirectoryExists($basePath.'/vendor/composer');
    $files->put($basePath.'/vendor/composer/installed.json', json_encode(['packages' => [$composer]], JSON_THROW_ON_ERROR));

    try {
        $manifest = new PackageManifest($files, $basePath, $basePath.'/packages.php');
        $manifest->vendorPath = $basePath.'/vendor';

        expect($manifest->providers())->toBe([SystemOneServiceProvider::class]);
    } finally {
        $files->deleteDirectory($basePath);
    }
});

it('boots inside a Laravel application', function (): void {
    expect($this->app)->not->toBeNull()
        ->and($this->app?->providerIsLoaded(SystemOneServiceProvider::class))->toBeTrue()
        ->and($this->app?->getProvider(SystemOneServiceProvider::class))->toBeInstanceOf(SystemOneServiceProvider::class);
});

it('does not register anything yet', function (): void {
    $app = $this->app;
    assert($app !== null);

    $before = array_keys($app->getBindings());

    $provider = new SystemOneServiceProvider($app);
    $provider->register();

    expect(array_keys($app->getBindings()))->toBe($before);
});
