<?php

declare(strict_types=1);

namespace EarthlingOllie\SystemOneClassification\Laravel;

use EarthlingOllie\SystemOneClassification\Client\DecisionClient;
use EarthlingOllie\SystemOneClassification\Domain\Decision;
use EarthlingOllie\SystemOneClassification\Exceptions\InvalidConfiguration;
use EarthlingOllie\SystemOneClassification\Protocol\SystemOneCodec;
use EarthlingOllie\SystemOneClassification\Result\Result;
use EarthlingOllie\SystemOneClassification\Transport\HttpTransport;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Http\Client\Factory;

use function filter_var;
use function is_array;
use function is_numeric;
use function is_string;

/**
 * What the SystemOne facade resolves to: builds a DecisionClient per configured connection
 * (config/system-one.php), with the events and result cache the config turns on, and sends
 * decisions to the default one.
 *
 * Config is read on every call, so runtime config() changes apply.
 */
final readonly class SystemOneManager
{
    /** Seconds a cached result is kept when `system-one.cache.ttl` is empty. */
    private const int DEFAULT_TTL = 3600;

    public function __construct(
        private Repository $config,
        private Factory $http,
        private Dispatcher $events,
        private CacheFactory $cache,
        private ExceptionHandler $exceptions,
    ) {}

    /**
     * The client for a named connection, or the default one.
     *
     * @throws InvalidConfiguration when the connection is missing or malformed
     */
    public function connection(?string $name = null): DecisionClient
    {
        $name ??= $this->defaultConnection();
        $connection = $this->config->get("system-one.connections.{$name}");

        if (! is_array($connection)) {
            throw InvalidConfiguration::unknownConnection($name);
        }

        $url = $connection['url'] ?? null;
        $apiKey = $connection['api_key'] ?? null;
        $timeout = $connection['timeout'] ?? '';

        if (! is_string($url) || $url === '') {
            throw InvalidConfiguration::missingUrl($name);
        }

        if ($apiKey !== null && ! is_string($apiKey)) {
            throw InvalidConfiguration::apiKeyNotString($name);
        }

        // An empty env value (`SYSTEM_ONE_TIMEOUT=`) reaches here as '' or null: use the default.
        if ($timeout === '') {
            $timeout = 30.0;
        }

        if (! is_numeric($timeout) || $timeout <= 0) {
            throw InvalidConfiguration::invalidTimeout($name);
        }

        $model = $connection['model'] ?? null;

        if ($model !== null && ! is_string($model)) {
            throw InvalidConfiguration::modelNotString($name);
        }

        // `SYSTEM_ONE_MODEL=` lets the server route, as leaving it out does.
        $model = $model === '' ? null : $model;

        return new DecisionClient(
            new HttpTransport($this->http, $url, $apiKey, (float) $timeout),
            new SystemOneCodec,
            model: $model,
            cache: $this->responseCache($name),
            observer: $this->observer($name, $model),
        );
    }

    /** Sends the decision to the default connection. */
    public function decide(Decision $decision): Result
    {
        return $this->connection()->decide($decision);
    }

    /** @throws InvalidConfiguration */
    private function observer(string $connection, ?string $model): ?DispatchesDecisionEvents
    {
        if (! $this->flag('events.enabled', true)) {
            return null;
        }

        return new DispatchesDecisionEvents($this->events, $connection, $model, $this->flag('events.include_context', false));
    }

    /** @throws InvalidConfiguration */
    private function responseCache(string $connection): ?StoreResponseCache
    {
        if (! $this->flag('cache.enabled', false)) {
            return null;
        }

        $store = $this->config->get('system-one.cache.store');
        $ttl = $this->config->get('system-one.cache.ttl');

        if ($store !== null && ! is_string($store)) {
            throw InvalidConfiguration::invalidCacheStore();
        }

        // An empty env value (`SYSTEM_ONE_CACHE_TTL=`) reaches here as '' or null: use the default.
        $ttl = $ttl === null || $ttl === '' ? self::DEFAULT_TTL : filter_var($ttl, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        if ($ttl === false) {
            throw InvalidConfiguration::invalidCacheTtl();
        }

        return new StoreResponseCache($this->cache, $this->exceptions, $connection, $store === '' ? null : $store, $ttl);
    }

    /**
     * A boolean setting as config files and env() write it: true/false, or a string such as
     * '1', 'on' or 'false'. Missing, null or empty (`SYSTEM_ONE_EVENTS=`) means the default, as
     * for every other setting.
     *
     * @throws InvalidConfiguration
     */
    private function flag(string $setting, bool $default): bool
    {
        $value = $this->config->get("system-one.{$setting}");

        if ($value === null || $value === '') {
            return $default;
        }

        return filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? throw InvalidConfiguration::notABoolean($setting);
    }

    private function defaultConnection(): string
    {
        $name = $this->config->get('system-one.default');

        if (! is_string($name)) {
            throw InvalidConfiguration::noDefaultConnection();
        }

        return $name;
    }
}
