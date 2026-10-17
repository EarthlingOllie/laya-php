<?php

declare(strict_types=1);

namespace EarthlingOllie\SystemOneClassification\Laravel;

use EarthlingOllie\SystemOneClassification\Client\DecisionClient;
use EarthlingOllie\SystemOneClassification\Domain\Decision;
use EarthlingOllie\SystemOneClassification\Exceptions\InvalidConfiguration;
use EarthlingOllie\SystemOneClassification\Model\JevProfile;
use EarthlingOllie\SystemOneClassification\Model\LayaProfile;
use EarthlingOllie\SystemOneClassification\Model\ModelProfile;
use EarthlingOllie\SystemOneClassification\Protocol\SystemOneCodec;
use EarthlingOllie\SystemOneClassification\Result\Result;
use EarthlingOllie\SystemOneClassification\Transport\HttpTransport;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Http\Client\Factory;

use function array_is_list;
use function explode;
use function filter_var;
use function implode;
use function is_array;
use function is_numeric;
use function is_scalar;
use function is_string;
use function trim;

/**
 * What the SystemOne facade resolves to: builds a DecisionClient per configured connection
 * (config/system-one.php) and sends decisions to the default one.
 *
 * Config is read on every call, so runtime config() changes apply.
 */
final readonly class SystemOneManager
{
    /** The model profiles a connection can name. */
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

        return new DecisionClient(
            new HttpTransport($this->http, $url, $apiKey, (float) $timeout),
            new SystemOneCodec(self::profile($name, $connection)),
        );
    }

    /** Sends the decision to the default connection. */
    public function decide(Decision $decision): Result
    {
        return $this->connection()->decide($decision);
    }

    /**
     * The connection's model profile, from its `profile`, `tournament_grouping` and `models`.
     *
     * @param  array<mixed>  $connection
     *
     * @throws InvalidConfiguration
     */
    private static function profile(string $name, array $connection): ModelProfile
    {
        // Like the timeout, an unset or empty profile means the default: Laya.
        $profile = $connection['profile'] ?? '';
        $models = self::models($name, $connection['models'] ?? []);
        // Checked whatever the profile, so a config does not break only when it switches to laya.
        $tournamentGrouping = self::tournamentGrouping($name, $connection['tournament_grouping'] ?? false);

        return match ($profile) {
            '', 'laya' => new LayaProfile($tournamentGrouping, $models),
            'jev' => new JevProfile($models),
            default => throw InvalidConfiguration::unknownProfile($name, implode(', ', self::PROFILES)),
        };
    }

    /**
     * true or false, or an env() string such as '1', 'true', 'on', '0', 'false' or ''.
     *
     * @throws InvalidConfiguration
     */
    private static function tournamentGrouping(string $name, mixed $value): bool
    {
        // filter_var() passes true and false through and reads '1', 'true', 'on', '0', '' ...
        $value = is_scalar($value) ? filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) : null;

        return $value ?? throw InvalidConfiguration::invalidTournamentGrouping($name);
    }

    /**
     * A list of model names, or a comma-separated string of them (SYSTEM_ONE_MODELS), each
     * trimmed. Empty entries of the string are dropped, so '' means none (as an unset or null
     * key does: the caller reads it with ?? []); an empty or non-string entry of the list is
     * refused.
     *
     * @return list<string>
     *
     * @throws InvalidConfiguration
     */
    private static function models(string $name, mixed $models): array
    {
        if (is_string($models)) {
            $names = [];

            foreach (explode(',', $models) as $model) {
                $model = trim($model);

                if ($model !== '') {
                    $names[] = $model;
                }
            }

            return $names;
        }

        if (! is_array($models) || ! array_is_list($models)) {
            throw InvalidConfiguration::invalidModels($name);
        }

        $names = [];

        foreach ($models as $model) {
            $model = is_string($model) ? trim($model) : '';

            if ($model === '') {
                throw InvalidConfiguration::invalidModels($name);
            }

            $names[] = $model;
        }

        return $names;
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
