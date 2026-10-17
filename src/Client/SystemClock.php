<?php

declare(strict_types=1);

namespace EarthlingOllie\SystemOneClassification\Client;

use function hrtime;

/** The system's monotonic high-resolution clock, which wall-clock changes do not move. */
final readonly class SystemClock implements Clock
{
    public function now(): float
    {
        return hrtime(true) / 1e9;
    }
}
