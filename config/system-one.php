<?php

declare(strict_types=1);

/*
 * System One: where decisions are sent. Publish with
 *     php artisan vendor:publish --tag=system-one-config
 *
 * Connections work like database connections: `SystemOne::decide()` uses the default,
 * `SystemOne::connection('name')->decide()` a named one. Remote hosts (hosted Laya, Jev)
 * are only ever used when you add and select them explicitly.
 */

return [
    'default' => env('SYSTEM_ONE_CONNECTION', 'local'),

    'connections' => [
        // laya-serve in Docker (`composer laya:up`, or `system-one:install` in an app).
        'local' => [
            'url' => env('SYSTEM_ONE_URL', 'http://localhost:8000'),
            // Only needed when the server runs with LAYA_API_KEY set.
            'api_key' => env('SYSTEM_ONE_API_KEY'),
            // Seconds; empty means 30. CPU inference takes ~0.3-0.7 s per call; the first
            // call after a start can take longer.
            'timeout' => env('SYSTEM_ONE_TIMEOUT', 30),
        ],
    ],
];
