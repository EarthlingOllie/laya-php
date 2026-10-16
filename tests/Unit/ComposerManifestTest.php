<?php

declare(strict_types=1);

use EarthlingOllie\SystemOneClassification\Tests\Support\ComposerManifest;

it('is published under the planned package name', function (): void {
    expect(ComposerManifest::read()['name'])->toBe('earthlingollie/laravel-system-one-classification');
});

it('requires PHP 8.4 and Laravel 13', function (): void {
    expect(ComposerManifest::read()['require'])->toMatchArray([
        'php' => '^8.4',
        'illuminate/http' => '^13.0',
        'illuminate/support' => '^13.0',
    ]);
});

it('autoloads the package namespace from src/ with PSR-4', function (): void {
    expect(ComposerManifest::read()['autoload'])->toBe([
        'psr-4' => ['EarthlingOllie\\SystemOneClassification\\' => 'src/'],
    ]);
});

it('rebuilds the Testbench package manifest so `testbench serve` auto-discovers the provider', function (): void {
    $discover = '@php vendor/bin/testbench package:discover --ansi';

    expect(ComposerManifest::read()['scripts'])
        ->toHaveKey('post-autoload-dump')
        ->and((array) ComposerManifest::read()['scripts']['post-autoload-dump'])->toContain($discover)
        ->and((array) ComposerManifest::read()['scripts']['serve'])->toBe([$discover, '@php vendor/bin/testbench serve --ansi']);
});

it('exposes a composer script for every gate plus one that runs them all', function (): void {
    expect(ComposerManifest::read()['scripts'])->toHaveKeys([
        'test', 'test:integration', 'test:coverage', 'analyse', 'format', 'format:check', 'mutate', 'check',
    ]);
});

it('declares the same licence as the LICENSE file', function (): void {
    $licence = (string) file_get_contents(dirname(ComposerManifest::path()).'/LICENSE');

    expect($licence)->toStartWith('MIT License')
        ->and(ComposerManifest::read()['license'])->toBe('MIT');
});
