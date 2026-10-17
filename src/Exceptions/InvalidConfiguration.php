<?php

declare(strict_types=1);

namespace EarthlingOllie\SystemOneClassification\Exceptions;

use InvalidArgumentException;

/** config/system-one.php does not describe a usable connection. */
final class InvalidConfiguration extends InvalidArgumentException implements SystemOneException
{
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

    public static function modelNotString(string $name): self
    {
        return new self("System One connection '{$name}' has a 'model' that is not a string.");
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
