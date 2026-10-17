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
            // The model behind the URL: 'laya' or 'jev'. Every decision is checked against its
            // limits before it is sent, and it tags each answer's raw `confidence`, which is
            // not comparable across models. Unset or empty means 'laya'.
            'profile' => env('SYSTEM_ONE_PROFILE', 'laya'),
            // Laya only (jev ignores it, but it must still be true or false): true when the
            // server runs with LAYA_TOURNAMENT_GROUP set to a group size from 2 to 100 (any other
            // value leaves it off), which raises the limit on options per choice question from
            // 100 to 255.
            'tournament_grouping' => env('SYSTEM_ONE_TOURNAMENT_GROUPING', false),
            // `model` values Decision::withModel() may pin beyond the profile's own: for Laya
            // the server's LAYA_EXTRA_MODELS (its checkpoints and their aliases are built in),
            // for Jev all of them. A list, or a comma-separated string.
            'models' => env('SYSTEM_ONE_MODELS', ''),
        ],
    ],
];
