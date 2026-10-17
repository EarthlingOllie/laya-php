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

    /** A Compose file `system-one:install` reads or edits cannot be used as it is. */
    public static function unusableCompose(string $file, string $reason): self
    {
        return new self("{$file} {$reason}.");
    }

    public static function invalidTimeout(string $name): self
    {
        return new self("System One connection '{$name}' has a 'timeout' that is not a number of seconds.");
    }
}
