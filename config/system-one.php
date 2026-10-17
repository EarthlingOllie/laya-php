<?php

declare(strict_types=1);

/*
 * System One: where decisions are sent. Publish with
 *     php artisan vendor:publish --tag=system-one-config
 *
 * Connections work like database connections: `SystemOne::decide()` uses the default,
 * `SystemOne::connection('name')->decide()` a named one. The default is the local server.
 * Remote hosts (hosted Laya, Jev) are only ever used when you select them by name and give
 * them a key: a connection marked `remote` refuses to send anything without an `api_key`,
 * and to send it anywhere but over https (or to this machine).
 *
 * Every connection takes the same settings:
 *   url          the base URL; requests go to {url}/v1/systemone
 *   api_key      sent as a bearer token, never logged or put in an exception; empty means none
 *   timeout      seconds per attempt; empty means 30
 *   profile      the model behind the URL: 'laya' or 'jev'. Every decision is checked against
 *                its limits before it is sent, and it tags each answer's raw `confidence`,
 *                which is not comparable across models. Unset or empty means 'laya'.
 *   tournament_grouping
 *                Laya only (jev ignores it, but it must still be true or false): true when the
 *                server runs with LAYA_TOURNAMENT_GROUP set to a group size from 2 to 100 (any
 *                other value leaves it off), which raises the limit on options per choice
 *                question from 100 to 255.
 *   models       `model` values Decision::withModel() may pin beyond the profile's own: for
 *                Laya the server's LAYA_EXTRA_MODELS (its checkpoints and their aliases are
 *                built in), for Jev all of them. A list, or a comma-separated string.
 *   model        the model to ask when a decision pins none, such as 'english'; it is accepted
 *                as one of the connection's models. Empty lets the server choose.
 *   retries      how often (at most 10) to try again when the host cannot be reached, times
 *                out or answers with a 5xx (a 4xx is never retried); empty means none. A
 *                timed-out request may still have been answered, and billed, by the host, and
 *                a host that never answers holds a decision for (retries + 1) x timeout seconds
 *                plus the waits: keep both small where that matters.
 *   retry_delay  milliseconds to wait before the first retry, doubled before each further one
 *                (at most 30 s; a longer Retry-After from a 5xx wins); empty means 200
 *   remote       true for a host off this machine: then an api_key is required and the url
 *                must be https (http is allowed only to localhost)
 */

return [
    'default' => env('SYSTEM_ONE_CONNECTION', 'local'),

    'connections' => [
        // laya-serve in Docker (`composer laya:up`, or `system-one:install` in an app).
        'local' => [
            'url' => env('SYSTEM_ONE_URL', 'http://localhost:8000'),
            // Only needed when the server runs with LAYA_API_KEY set.
            'api_key' => env('SYSTEM_ONE_API_KEY'),
            // CPU inference takes ~0.3-0.7 s per call; the first call after a start can take longer.
            'timeout' => env('SYSTEM_ONE_TIMEOUT', 30),
            'profile' => env('SYSTEM_ONE_PROFILE', 'laya'),
            'tournament_grouping' => env('SYSTEM_ONE_TOURNAMENT_GROUPING', false),
            'models' => env('SYSTEM_ONE_MODELS', ''),
            'model' => env('SYSTEM_ONE_MODEL'),
            // laya-serve answers 503 "server busy" (Retry-After: 1) while it is saturated.
            'retries' => env('SYSTEM_ONE_RETRIES', 2),
            'retry_delay' => env('SYSTEM_ONE_RETRY_DELAY', 200),
        ],

        // The hosted Laya API run by laya-ai.com (a third party): same protocol, billed per call.
        'laya-hosted' => [
            'remote' => true,
            'url' => env('SYSTEM_ONE_LAYA_HOSTED_URL', 'https://api.laya-ai.com'),
            'api_key' => env('SYSTEM_ONE_LAYA_HOSTED_API_KEY'),
            'timeout' => env('SYSTEM_ONE_LAYA_HOSTED_TIMEOUT', 30),
            'profile' => 'laya',
            'tournament_grouping' => env('SYSTEM_ONE_LAYA_HOSTED_TOURNAMENT_GROUPING', false),
            'models' => env('SYSTEM_ONE_LAYA_HOSTED_MODELS', ''),
            'model' => env('SYSTEM_ONE_LAYA_HOSTED_MODEL'),
            'retries' => env('SYSTEM_ONE_LAYA_HOSTED_RETRIES', 2),
            'retry_delay' => env('SYSTEM_ONE_LAYA_HOSTED_RETRY_DELAY', 500),
        ],

        // TypeSafe's hosted Jev. Its model names are not built in: list them in models.
        'jev' => [
            'remote' => true,
            'url' => env('SYSTEM_ONE_JEV_URL', 'https://api.typesafe.ai'),
            'api_key' => env('SYSTEM_ONE_JEV_API_KEY'),
            'timeout' => env('SYSTEM_ONE_JEV_TIMEOUT', 30),
            'profile' => 'jev',
            'models' => env('SYSTEM_ONE_JEV_MODELS', ''),
            'model' => env('SYSTEM_ONE_JEV_MODEL'),
            'retries' => env('SYSTEM_ONE_JEV_RETRIES', 2),
            'retry_delay' => env('SYSTEM_ONE_JEV_RETRY_DELAY', 500),
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
