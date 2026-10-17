<?php

declare(strict_types=1);

namespace EarthlingOllie\SystemOneClassification\Laravel;

use EarthlingOllie\SystemOneClassification\Client\DecisionClient;
use EarthlingOllie\SystemOneClassification\Domain\Decision;
use EarthlingOllie\SystemOneClassification\Exceptions\InvalidConfiguration;
use EarthlingOllie\SystemOneClassification\Protocol\SystemOneCodec;
use EarthlingOllie\SystemOneClassification\Result\Result;
use EarthlingOllie\SystemOneClassification\Transport\HttpTransport;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Http\Client\Factory;

use function implode;
use function in_array;
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
    /** The model profiles a connection can name; #6 turns each into a ModelProfile. */
    private const array PROFILES = ['laya', 'jev'];

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
        $name ??= $this->defaultConnection();
        $connection = $this->config->get("system-one.connections.{$name}");

        if (! is_array($connection)) {
            throw InvalidConfiguration::unknownConnection($name);
        }

        $url = $connection['url'] ?? null;
        $apiKey = $connection['api_key'] ?? null;
        $timeout = $connection['timeout'] ?? '';
        $profile = $connection['profile'] ?? '';

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

        // Like the timeout, an unset or empty profile means the default: Laya.
        if ($profile === '') {
            $profile = 'laya';
        }

        if (! in_array($profile, self::PROFILES, true)) {
            throw InvalidConfiguration::unknownProfile($name, implode(', ', self::PROFILES));
        }

        return new DecisionClient(
            new HttpTransport($this->http, $url, $apiKey, (float) $timeout),
            new SystemOneCodec($profile),
        );
    }

    /** Sends the decision to the default connection. */
    public function decide(Decision $decision): Result
    {
        return $this->connection()->decide($decision);
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
