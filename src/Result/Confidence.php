<?php

declare(strict_types=1);

namespace EarthlingOllie\SystemOneClassification\Result;

/**
 * The model's own `confidence` value, tagged with the model profile that produced it.
 *
 * Its meaning depends on the model (Laya reports 1 - normalised entropy, Jev uses its own
 * formula), so a threshold tuned on one profile does not carry over to another. Threshold on
 * an answer's `answerConfidence` instead, which is comparable across models.
 */
final readonly class Confidence
{
    public function __construct(
        public float $value,
        public string $profile,
    ) {}
}
