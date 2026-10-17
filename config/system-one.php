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
            // The checkpoint to ask, such as 'english' or 'multilingual'; empty lets the
            // server route each decision by its language.
            'model' => env('SYSTEM_ONE_MODEL'),
        ],
    ],

    // DecisionMade and DecisionFailed, for metrics and alerting. They never carry the API
    // key, and carry the context only with include_context on: it may hold personal data.
    'events' => [
        'enabled' => env('SYSTEM_ONE_EVENTS', true),
        'include_context' => env('SYSTEM_ONE_EVENTS_INCLUDE_CONTEXT', false),
    ],

    // Identical decisions answered from a Laravel cache store instead of the server. The key
    // covers the context, the questions, the request controls, the connection, the model and
    // the checkpoint revisions the server reports on /health, which is read before each
    // cached decision. A failing store is reported and skipped; it never fails a decision.
    'cache' => [
        'enabled' => env('SYSTEM_ONE_CACHE', false),
        // A store from config/cache.php; empty uses the default store.
        'store' => env('SYSTEM_ONE_CACHE_STORE'),
        // Seconds; empty means an hour.
        'ttl' => env('SYSTEM_ONE_CACHE_TTL', 3600),
    ],
];
