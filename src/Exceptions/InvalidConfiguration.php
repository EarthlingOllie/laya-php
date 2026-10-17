<?php

declare(strict_types=1);

namespace EarthlingOllie\SystemOneClassification\Exceptions;

use InvalidArgumentException;

/** config/system-one.php does not describe a usable connection, or there is none to read. */
final class InvalidConfiguration extends InvalidArgumentException implements SystemOneException
{
    public static function noApplication(): self
    {
        return new self('SystemOne::fake() needs a Laravel application behind the facades, to read config/system-one.php from.');
    }

    public static function noDefaultConnection(): self
    {
        return new self('config/system-one.php has no default connection name.');
    }

    public static function unknownConnection(string $name): self
    {
        return new self("System One connection '{$name}' is not configured in config/system-one.php.");
    }

    public static function missingUrl(string $name): self
    {
        return new self("System One connection '{$name}' needs a 'url'.");
    }

    public static function apiKeyNotString(string $name): self
    {
        return new self("System One connection '{$name}' has an 'api_key' that is not a string.");
    }

    /** A Compose file `system-one:install` reads or edits cannot be used as it is. */
    public static function unusableCompose(string $file, string $reason): self
    {
        return new self("{$file} {$reason}.");
    }

    public static function invalidTimeout(string $name): self
    {
        return new self("System One connection '{$name}' has a 'timeout' that is not a number of seconds.");
    }

    public static function unknownProfile(string $name, string $profiles): self
    {
        return new self("System One connection '{$name}' has a 'profile' that is not one of: {$profiles}.");
    }

    public static function invalidTournamentGrouping(string $name): self
    {
        return new self("System One connection '{$name}' has a 'tournament_grouping' that is not true or false.");
    }

    public static function invalidModels(string $name): self
    {
        return new self("System One connection '{$name}' has 'models' that are not a list of model names.");
    }

    public static function modelNotString(string $name): self
    {
        return new self("System One connection '{$name}' has a 'model' that is not a string.");
    }

    /** @param  string  $key  'retries' or 'retry_delay' */
    public static function invalidRetry(string $name, string $key): self
    {
        return new self("System One connection '{$name}' has a '{$key}' that is not a whole number of zero or more.");
    }

    public static function tooManyRetries(string $name, int $max): self
    {
        return new self("System One connection '{$name}' has 'retries' above {$max}.");
    }

    public static function invalidRemote(string $name): self
    {
        return new self("System One connection '{$name}' has a 'remote' that is not true or false.");
    }

    public static function missingApiKey(string $name): self
    {
        return new self("System One connection '{$name}' is a remote host, so it needs an 'api_key'; nothing is sent to it without one.");
    }

    public static function insecureRemoteUrl(string $name): self
    {
        return new self("System One connection '{$name}' is a remote host, so its 'url' must use https (plain http is allowed only to localhost).");
    }

    /** @param  string  $setting  such as 'cache.enabled' */
    public static function notABoolean(string $setting): self
    {
        return new self("config/system-one.php has a '{$setting}' that is not a boolean.");
    }

    public static function invalidCacheTtl(): self
    {
        return new self("config/system-one.php has a 'cache.ttl' that is not a whole number of seconds above zero.");
    }

    public static function invalidCacheStore(): self
    {
        return new self("config/system-one.php has a 'cache.store' that is not a store name.");
    }
}
