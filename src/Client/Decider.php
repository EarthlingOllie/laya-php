<?php

declare(strict_types=1);

namespace EarthlingOllie\SystemOneClassification\Client;

use EarthlingOllie\SystemOneClassification\Domain\Decision;
use EarthlingOllie\SystemOneClassification\Exceptions\SystemOneException;
use EarthlingOllie\SystemOneClassification\Result\Result;

/**
 * Anything that answers decisions on one connection: DecisionClient, and the testing fake's
 * stand-in for it. Type-hint this rather than DecisionClient, so SystemOne::fake() can stand in.
 */
interface Decider
{
    /** @throws SystemOneException */
    public function decide(Decision $decision): Result;
}
