<?php

declare(strict_types=1);

namespace EarthlingOllie\SystemOneClassification\Laravel;

use EarthlingOllie\SystemOneClassification\Client\DecisionClient;
use EarthlingOllie\SystemOneClassification\Domain\Decision;
use EarthlingOllie\SystemOneClassification\Exceptions\InvalidConfiguration;
use EarthlingOllie\SystemOneClassification\Protocol\SystemOneCodec;
use EarthlingOllie\SystemOneClassification\Result\Result;
use EarthlingOllie\SystemOneClassification\Transport\HttpTransport;
use EarthlingOllie\SystemOneClassification\Transport\Redactor;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Http\Client\Factory;

use function is_array;
use function is_numeric;
use function is_string;

/**
 * What the SystemOne facade resolves to: builds a DecisionClient per configured connection
 * (config/system-one.php) and sends decisions to the default one.
 *
 * Config is read on every call, so runtime config() changes apply.
 */
final readonly class SystemOneManager
{
    public function __construct(
        private Repository $config,
        private Factory $http,
    ) {}

    /**
     * The client for a named connection, or the default one.
     *
     * @throws InvalidConfiguration when the connection is missing or malformed
     */
    public function connection(?string $name = null): DecisionClient
    {
        ['url' => $url, 'apiKey' => $apiKey, 'timeout' => $timeout] = $this->settings($name ?? $this->defaultConnection());

        return new DecisionClient(
            new HttpTransport($this->http, $url, $apiKey, $timeout),
            new SystemOneCodec,
        );
    }

    /** Sends the decision to the default connection. */
    public function decide(Decision $decision): Result
    {
        return $this->connection()->decide($decision);
    }

    /**
     * The name of the connection used when none is given (`system-one.default`).
     *
     * @throws InvalidConfiguration when it is not set
     */
    public function defaultConnection(): string
    {
        $name = $this->config->get('system-one.default');

        if (! is_string($name)) {
            throw InvalidConfiguration::noDefaultConnection();
        }

        return $name;
    }

    /**
     * A connection's URL fit to print: its userinfo, query values and API key masked (Redactor).
     *
     * @throws InvalidConfiguration when the connection is missing or malformed
     */
    public function displayUrl(?string $name = null): string
    {
        ['url' => $url, 'apiKey' => $apiKey] = $this->settings($name ?? $this->defaultConnection());

        return Redactor::forConnection($url, $apiKey)->redact($url);
    }

    /**
     * @return array{url: non-empty-string, apiKey: ?string, timeout: float}
     *
     * @throws InvalidConfiguration
     */
    private function settings(string $name): array
    {
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

        return ['url' => $url, 'apiKey' => $apiKey, 'timeout' => (float) $timeout];
    }
}
