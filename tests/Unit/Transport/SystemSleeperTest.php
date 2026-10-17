<?php

declare(strict_types=1);

use EarthlingOllie\SystemOneClassification\Transport\SystemSleeper;

it('really waits for the microseconds it is given', function (): void {
    $start = hrtime(true);

    (new SystemSleeper)->sleep(30_000);

    expect((hrtime(true) - $start) / 1e9)->toBeGreaterThanOrEqual(0.03)->toBeLessThan(2.0);
});
