<?php

declare(strict_types=1);

namespace EarthlingOllie\SystemOneClassification\Laravel\Install;

use function preg_match;
use function preg_quote;
use function rtrim;

/** Edits to a `.env` file that never overwrite what is already set. */
final readonly class EnvFile
{
    /**
     * The contents with `KEY=value` appended, or null when the key is already set (to any
     * value, empty included), so the file is left as it is.
     */
    public static function withDefault(string $contents, string $key, string $value): ?string
    {
        if (preg_match('/^[ \t]*(?:export[ \t]+)?'.preg_quote($key, '/').'[ \t]*=/m', $contents) === 1) {
            return null;
        }

        $kept = rtrim($contents, "\n");

        return ($kept === '' ? '' : "{$kept}\n\n")."{$key}={$value}\n";
    }
}
