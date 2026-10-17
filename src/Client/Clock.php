<?php

declare(strict_types=1);

namespace EarthlingOllie\SystemOneClassification\Client;

/** A monotonic clock for measuring latency; tests inject their own. */
interface Clock
{
    /** Seconds since an arbitrary fixed point; only differences between readings mean anything. */
    public function now(): float;
}
