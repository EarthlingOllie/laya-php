<?php

declare(strict_types=1);

namespace EarthlingOllie\SystemOneClassification\Result;

use EarthlingOllie\SystemOneClassification\Exceptions\InvalidProbability;

/** The one check for every probability, confidence and threshold the answers hold or take. */
final readonly class Probability
{
    /**
     * @param  string  $name  what the value is, for the message ("The threshold")
     * @return float the value, unchanged
     *
     * @throws InvalidProbability when the value is outside [0, 1] or NAN
     */
    public static function check(string $name, float $value): float
    {
        // Written as "not inside" so that NAN, which fails every comparison, is refused too.
        if (! ($value >= 0.0 && $value <= 1.0)) {
            throw InvalidProbability::outOfRange($name, $value);
        }

        return $value;
    }
}
