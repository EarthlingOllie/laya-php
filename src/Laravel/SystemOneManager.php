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
use EarthlingOllie\SystemOneClassification\Transport\RetryPolicy;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Http\Client\Factory;

use function array_is_list;
use function explode;
use function filter_var;
use function implode;
use function in_array;
use function is_array;
use function is_int;
use function is_numeric;
use function is_scalar;
use function is_string;
use function parse_url;
use function str_starts_with;
use function strtolower;
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

    /** The most retries a connection may ask for: more only stalls a decision against a host that is down. */
    private const int MAX_RETRIES = 10;

    /** Milliseconds before the first retry when a connection sets `retries` but no `retry_delay`. */
    private const int DEFAULT_RETRY_DELAY = 200;

    public function __construct(
        private Repository $config,
        private Factory $http,
    ) {}

    /**
     * The client for a named connection, or the default one.
     *
     * @throws InvalidConfiguration when the connection is missing or malformed, or is remote
     *                              and has no API key
     */
    public function connection(?string $name = null): DecisionClient
    {
        $config = $this->configuration($name);

        if ($config->remote && ! $config->hasApiKey()) {
            throw InvalidConfiguration::missingApiKey($config->name);
        }

        return new DecisionClient(
            new HttpTransport(new QuietHttpFactory($this->http), $config->url, $config->apiKey(), $config->timeout, $config->retry, new LaravelSleeper),
            new SystemOneCodec($config->profile),
            model: $config->model,
        );
    }

    /**
     * A named connection's config, or the default one's, checked as connection() checks it
     * except for the API key a remote connection needs: what a stand-in for the host (the
     * testing fake) checks, since it sends nothing.
     *
     * @throws InvalidConfiguration when the connection is missing or malformed
     */
    public function configuration(?string $name = null): ConnectionConfig
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

        // Laravel's withToken() trims the key, so a blank one would go out as a bare "Bearer".
        $apiKey = trim((string) $apiKey);
        $remote = self::flag($connection['remote'] ?? false) ?? throw InvalidConfiguration::invalidRemote($name);

        if ($remote && ! self::isSecure($url)) {
            throw InvalidConfiguration::insecureRemoteUrl($name);
        }

        $model = self::model($name, $connection['model'] ?? null);

        return new ConnectionConfig(
            $name,
            $url,
            $apiKey === '' ? null : $apiKey,
            (float) $timeout,
            self::profile($name, $connection, $model),
            $model,
            new RetryPolicy(
                self::retries($name, $connection['retries'] ?? null),
                self::wholeNumber($name, 'retry_delay', $connection['retry_delay'] ?? null, self::DEFAULT_RETRY_DELAY),
            ),
            $remote,
        );
    }

    /** Sends the decision to the default connection. */
    public function decide(Decision $decision): Result
    {
        return $this->connection()->decide($decision);
    }

    /**
     * The connection's model profile, from its `profile`, `tournament_grouping` and `models`,
     * plus its default `model`: naming it is as good as listing it.
     *
     * @param  array<mixed>  $connection
     *
     * @throws InvalidConfiguration
     */
    private static function profile(string $name, array $connection, ?string $model): ModelProfile
    {
        // Like the timeout, an unset or empty profile means the default: Laya.
        $profile = $connection['profile'] ?? '';
        $models = self::models($name, $connection['models'] ?? []);

        if ($model !== null && ! in_array($model, $models, true)) {
            $models[] = $model;
        }

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
        return self::flag($value) ?? throw InvalidConfiguration::invalidTournamentGrouping($name);
    }

    /** true or false, or an env() string such as '1', 'true', 'on', '0', 'false' or ''; else null. */
    private static function flag(mixed $value): ?bool
    {
        // filter_var() passes true and false through and reads '1', 'true', 'on', '0', '' ...
        return is_scalar($value) ? filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) : null;
    }

    /**
     * Whether a remote connection's key may go to the URL: over https, or over http only to
     * this machine (localhost, 127.0.0.0/8 or ::1), where nothing on the way can read it.
     */
    private static function isSecure(string $url): bool
    {
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $loopback = $host === 'localhost' || $host === '[::1]'
            || (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false && str_starts_with($host, '127.'));

        return $scheme === 'https' || ($scheme === 'http' && $loopback);
    }

    /**
     * The default model, trimmed as `models` are; null or empty lets the server route.
     *
     * @throws InvalidConfiguration
     */
    private static function model(string $name, mixed $model): ?string
    {
        if ($model !== null && ! is_string($model)) {
            throw InvalidConfiguration::modelNotString($name);
        }

        $model = trim((string) $model);

        return $model === '' ? null : $model;
    }

    /**
     * `retries`: none when unset or empty, at most MAX_RETRIES.
     *
     * @return int<0, max>
     *
     * @throws InvalidConfiguration
     */
    private static function retries(string $name, mixed $value): int
    {
        $retries = self::wholeNumber($name, 'retries', $value, 0);

        return $retries > self::MAX_RETRIES ? throw InvalidConfiguration::tooManyRetries($name, self::MAX_RETRIES) : $retries;
    }

    /**
     * A count or delay of zero or more: an int, or an env() string of one. Unset or empty
     * means the default.
     *
     * @param  int<0, max>  $default
     * @return int<0, max>
     *
     * @throws InvalidConfiguration
     */
    private static function wholeNumber(string $name, string $key, mixed $value, int $default): int
    {
        if ($value === null || $value === '') {
            return $default;
        }

        $number = is_int($value) || is_string($value) ? filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]) : false;

        return $number === false ? throw InvalidConfiguration::invalidRetry($name, $key) : $number;
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
