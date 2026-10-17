<?php

declare(strict_types=1);

namespace EarthlingOllie\SystemOneClassification\Laravel;

use EarthlingOllie\SystemOneClassification\Client\ResponseCache;
use EarthlingOllie\SystemOneClassification\Exceptions\SystemOneException;
use EarthlingOllie\SystemOneClassification\Transport\Response;
use Illuminate\Contracts\Cache\Factory;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Throwable;

use function hash;
use function is_array;
use function is_int;
use function is_string;

/**
 * One connection's results in a Laravel cache store (`system-one.cache`).
 *
 * Entries are plain arrays (status and body), not objects, so they survive a store that
 * refuses to unserialize classes. A store that throws is reported and skipped: a decision
 * never fails because of its cache.
 */
final readonly class StoreResponseCache implements ResponseCache
{
    /**
     * @param  ?string  $store  the store name; null uses the default store
     * @param  positive-int  $ttl  seconds an entry is kept
     */
    public function __construct(
        private Factory $cache,
        private ExceptionHandler $exceptions,
        private string $connection,
        private ?string $store,
        private int $ttl,
    ) {}

    public function get(string $key): ?Response
    {
        try {
            $entry = $this->cache->store($this->store)->get($this->storeKey($key));
        } catch (Throwable $broken) {
            $this->exceptions->report($broken);

            return null;
        }

        // Anything this class did not write reads as a miss, and is overwritten.
        if (! is_array($entry) || ! is_int($entry['status'] ?? null) || ! is_string($entry['body'] ?? null)) {
            return null;
        }

        return new Response($entry['status'], $entry['body']);
    }

    public function put(string $key, Response $response): void
    {
        try {
            $this->cache->store($this->store)->put($this->storeKey($key), ['status' => $response->status, 'body' => $response->body], $this->ttl);
        } catch (Throwable $broken) {
            $this->exceptions->report($broken);
        }
    }

    public function skipped(SystemOneException $reason): void
    {
        $this->exceptions->report($reason);
    }

    /** Scoped to the connection, and hashed so any connection name makes a valid key for every store. */
    private function storeKey(string $key): string
    {
        return 'system-one:'.hash('sha256', $this->connection."\0".$key);
    }
}
