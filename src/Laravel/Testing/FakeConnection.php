<?php

declare(strict_types=1);

namespace EarthlingOllie\SystemOneClassification\Laravel\Testing;

use EarthlingOllie\SystemOneClassification\Client\Decider;
use EarthlingOllie\SystemOneClassification\Domain\Decision;
use EarthlingOllie\SystemOneClassification\Result\Result;

/**
 * What SystemOne::connection() returns under the fake: the fake, answering on one connection.
 *
 * It holds no state of its own; every decision is answered and recorded by the fake.
 */
final readonly class FakeConnection implements Decider
{
    public function __construct(
        private SystemOneFake $fake,
        public string $name,
        public string $profile,
    ) {}

    public function decide(Decision $decision): Result
    {
        return $this->fake->answer($decision, $this);
    }
}
