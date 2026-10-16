<?php

declare(strict_types=1);

use EarthlingOllie\SystemOneClassification\Laravel\SystemOneServiceProvider;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Route;

/*
 * Status page of the workbench app: shows whether the package's service provider was
 * registered by the booted application.
 */
Route::get('/', function (Application $app): string {
    $loaded = $app->providerIsLoaded(SystemOneServiceProvider::class);
    $provider = e(SystemOneServiceProvider::class);
    $state = $loaded ? 'yes' : 'no';
    $label = $loaded ? 'registered and booted' : 'NOT registered';
    $laravel = e($app->version());
    $php = e(PHP_VERSION);

    return <<<HTML
        <!doctype html>
        <html lang="en">
        <head><meta charset="utf-8"><title>System One Classification</title></head>
        <body style="font-family: system-ui, sans-serif; margin: 3rem;">
            <h1>System One Classification</h1>
            <p>Laravel {$laravel} on PHP {$php}</p>
            <p id="provider" data-provider-loaded="{$state}"><code>{$provider}</code>: <strong>{$label}</strong></p>
        </body>
        </html>
        HTML;
});
