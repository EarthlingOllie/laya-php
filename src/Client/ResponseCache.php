<?php

declare(strict_types=1);

namespace EarthlingOllie\SystemOneClassification\Client;

use EarthlingOllie\SystemOneClassification\Exceptions\SystemOneException;
use EarthlingOllie\SystemOneClassification\Transport\Response;

/**
 * Where DecisionClient keeps the server's successful responses, keyed by what decided them
 * (see DecisionClient::decide()). The Laravel layer backs it with a cache store.
 *
 * Caching only ever saves calls: an implementation must not throw. A store that fails is a
 * miss on get() and a no-op on put(), and is reported by the implementation.
 */
interface ResponseCache
{
    /** The response stored under the key, or null on a miss. */
    public function get(string $key): ?Response;

    public function put(string $key, Response $response): void;

    /**
     * The client skipped the cache for one decision for this reason: the server's revisions
     * could not be read, or the cached entry could not be decoded. The decision is still sent.
     */
    public function skipped(SystemOneException $reason): void;
}
