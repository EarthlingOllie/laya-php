<?php

declare(strict_types=1);

namespace EarthlingOllie\SystemOneClassification\Exceptions;

use InvalidArgumentException;

/** A probability or a yes/no threshold outside [0, 1], or thresholds the wrong way round. */
final class InvalidProbability extends InvalidArgumentException implements SystemOneException
{
    public static function outOfRange(string $name, float $value): self
    {
        return new self("{$name} must be between 0 and 1, got {$value}.");
    }

    public static function thresholdsCrossed(float $yes, float $no): self
    {
        return new self("The no threshold ({$no}) must not be above the yes threshold ({$yes}).");
    }
}
