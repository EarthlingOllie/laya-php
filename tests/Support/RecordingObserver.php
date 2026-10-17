<?php

declare(strict_types=1);

namespace EarthlingOllie\SystemOneClassification\Tests\Support;

use ArrayObject;
use EarthlingOllie\SystemOneClassification\Client\DecisionObserver;
use EarthlingOllie\SystemOneClassification\Domain\Decision;
use EarthlingOllie\SystemOneClassification\Exceptions\SystemOneException;
use EarthlingOllie\SystemOneClassification\Result\Result;

/** A DecisionObserver for client tests that records each notification in order. */
final readonly class RecordingObserver implements DecisionObserver
{
    /**
     * @param  ArrayObject<int, array{0: 'decided', 1: Decision, 2: Result, 3: bool}|array{0: 'failed', 1: Decision, 2: SystemOneException}>  $calls
     */
    public function __construct(public ArrayObject $calls = new ArrayObject) {}

    public function decided(Decision $decision, Result $result, bool $cached): void
    {
        $this->calls->append(['decided', $decision, $result, $cached]);
    }

    public function failed(Decision $decision, SystemOneException $exception): void
    {
        $this->calls->append(['failed', $decision, $exception]);
    }
}
