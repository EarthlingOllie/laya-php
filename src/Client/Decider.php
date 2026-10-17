<?php

declare(strict_types=1);

namespace EarthlingOllie\SystemOneClassification\Client;

use EarthlingOllie\SystemOneClassification\Domain\Context;
use EarthlingOllie\SystemOneClassification\Domain\Decision;
use EarthlingOllie\SystemOneClassification\Exceptions\SystemOneException;
use EarthlingOllie\SystemOneClassification\Result\Result;
use EarthlingOllie\SystemOneClassification\Result\ResultSet;

/**
 * Anything that answers decisions on one connection: DecisionClient, and the testing fake's
 * stand-in for it. Type-hint this rather than DecisionClient, so SystemOne::fake() can stand in.
 */
interface Decider
{
    /** @throws SystemOneException */
    public function decide(Decision $decision): Result;

    /**
     * Asks the decision's questions, with its settings, about each context: a Result per
     * context, in order.
     *
     * @param  list<Context>  $contexts
     *
     * @throws SystemOneException
     */
    public function decideMany(array $contexts, Decision $decision): ResultSet;
}
