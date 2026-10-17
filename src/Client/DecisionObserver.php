<?php

declare(strict_types=1);

namespace EarthlingOllie\SystemOneClassification\Client;

use EarthlingOllie\SystemOneClassification\Domain\Decision;
use EarthlingOllie\SystemOneClassification\Exceptions\SystemOneException;
use EarthlingOllie\SystemOneClassification\Result\Result;

/** Told about every decision a DecisionClient makes or fails; the Laravel layer turns these into events. */
interface DecisionObserver
{
    /** @param  bool  $cached  whether the result came from the ResponseCache instead of the server */
    public function decided(Decision $decision, Result $result, bool $cached): void;

    /** Called before the client rethrows the exception. */
    public function failed(Decision $decision, SystemOneException $exception): void;
}
