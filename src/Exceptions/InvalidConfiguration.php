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

    public static function invalidTimeout(string $name): self
    {
        return new self("System One connection '{$name}' has a 'timeout' that is not a number of seconds.");
    }

    public static function unknownProfile(string $name, string $profiles): self
    {
        return new self("System One connection '{$name}' has a 'profile' that is not one of: {$profiles}.");
    }
}
