<?php

declare(strict_types=1);

namespace EarthlingOllie\SystemOneClassification\Tests\Support;

use EarthlingOllie\SystemOneClassification\Transport\Sleeper;

/**
 * A Sleeper that only records how long it was asked to wait, so retry tests run instantly
 * and can assert the backoff.
 */
final class RecordingSleeper implements Sleeper
{
    /** @var list<int> */
    private array $waits = [];

    public function sleep(int $microseconds): void
    {
        $this->waits[] = $microseconds;
    }

    /** @return list<int> every wait asked for, in microseconds, in order */
    public function waits(): array
    {
        return $this->waits;
    }
}
