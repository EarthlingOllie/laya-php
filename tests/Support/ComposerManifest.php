<?php

declare(strict_types=1);

namespace EarthlingOllie\SystemOneClassification\Tests\Support;

use Illuminate\Support\ServiceProvider;
use UnexpectedValueException;

use function array_filter;
use function array_is_list;
use function array_map;
use function dirname;
use function file_get_contents;
use function is_array;
use function is_string;
use function is_subclass_of;
use function json_decode;
use function var_export;

/**
 * The package's composer.json, decoded once per test run.
 */
final class ComposerManifest
{
    /** @var array<string, mixed>|null */
    private static ?array $manifest = null;

    public static function path(): string
    {
        return dirname(__DIR__, 2).'/composer.json';
    }

    /**
     * @return array<string, mixed>
     */
    public static function read(): array
    {
        if (self::$manifest === null) {
            $manifest = json_decode((string) file_get_contents(self::path()), true, flags: JSON_THROW_ON_ERROR);

            if (! is_array($manifest)) {
                throw new UnexpectedValueException('composer.json must contain an object.');
            }

            self::$manifest = array_filter($manifest, is_string(...), ARRAY_FILTER_USE_KEY);
        }

        return self::$manifest;
    }

    /**
     * The providers Laravel package auto-discovery registers (`extra.laravel.providers`).
     *
     * @return list<class-string<ServiceProvider>>
     */
    public static function providers(): array
    {
        $extra = self::read()['extra'] ?? null;
        $laravel = is_array($extra) ? $extra['laravel'] ?? null : null;
        $providers = is_array($laravel) ? $laravel['providers'] ?? [] : [];

        if (! is_array($providers) || ! array_is_list($providers)) {
            throw new UnexpectedValueException('composer.json extra.laravel.providers must be a list.');
        }

        return array_map(static function (mixed $provider): string {
            if (! is_string($provider) || ! is_subclass_of($provider, ServiceProvider::class)) {
                throw new UnexpectedValueException('Not a service provider: '.var_export($provider, true));
            }

            return $provider;
        }, $providers);
    }
}
