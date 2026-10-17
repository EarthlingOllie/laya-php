<?php

declare(strict_types=1);

namespace EarthlingOllie\SystemOneClassification\Transport;

/**
 * Waits between HttpTransport's retries. SystemSleeper really waits; the Laravel layer passes
 * one that goes through Illuminate\Support\Sleep, so Sleep::fake() can assert the backoff.
 */
interface Sleeper
{
    /** @param  int<0, max>  $microseconds */
    public function sleep(int $microseconds): void;
}
