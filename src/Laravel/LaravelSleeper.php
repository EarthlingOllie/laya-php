<?php

declare(strict_types=1);

namespace EarthlingOllie\SystemOneClassification\Laravel;

use EarthlingOllie\SystemOneClassification\Transport\Sleeper;
use Illuminate\Support\Sleep;

/** Waits between retries through Laravel's Sleep, so tests can Sleep::fake() and assert the backoff. */
final readonly class LaravelSleeper implements Sleeper
{
    public function sleep(int $microseconds): void
    {
        Sleep::usleep($microseconds);
    }
}
