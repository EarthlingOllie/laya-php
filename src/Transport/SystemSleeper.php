<?php

declare(strict_types=1);

namespace EarthlingOllie\SystemOneClassification\Transport;

use function usleep;

/** Waits with PHP's usleep(). */
final readonly class SystemSleeper implements Sleeper
{
    public function sleep(int $microseconds): void
    {
        usleep($microseconds);
    }
}
