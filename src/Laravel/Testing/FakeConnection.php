<?php

declare(strict_types=1);

namespace EarthlingOllie\SystemOneClassification\Laravel\Testing;

use EarthlingOllie\SystemOneClassification\Client\Decider;
use EarthlingOllie\SystemOneClassification\Domain\Context;
use EarthlingOllie\SystemOneClassification\Domain\Decision;
use EarthlingOllie\SystemOneClassification\Model\ModelProfile;
use EarthlingOllie\SystemOneClassification\Result\Result;
use EarthlingOllie\SystemOneClassification\Result\ResultSet;

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
        public ModelProfile $profile,
    ) {}

    public function decide(Decision $decision): Result
    {
        return $this->fake->answer($decision, $this);
    }

    /** @param  list<Context>  $contexts */
    public function decideMany(array $contexts, Decision $decision): ResultSet
    {
        return $this->fake->answerMany($contexts, $decision, $this);
    }
}
