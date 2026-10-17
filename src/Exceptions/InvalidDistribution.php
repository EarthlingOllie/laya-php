<?php

declare(strict_types=1);

namespace EarthlingOllie\SystemOneClassification\Exceptions;

use BackedEnum;
use InvalidArgumentException;

/** A distribution that does not cover its enum, or a case it is not over. */
final class InvalidDistribution extends InvalidArgumentException implements SystemOneException
{
    public static function empty(): self
    {
        return new self('A distribution needs at least one case.');
    }

    public static function notOverEnum(string $enum): self
    {
        return new self("A distribution must give every case of {$enum} exactly one probability.");
    }

    public static function unknownCase(BackedEnum $case, string $enum): self
    {
        return new self($case::class."::{$case->name} is not a case of this distribution over {$enum}.");
    }
}
