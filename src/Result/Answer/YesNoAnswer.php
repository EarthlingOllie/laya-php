<?php

declare(strict_types=1);

namespace EarthlingOllie\SystemOneClassification\Result\Answer;

use EarthlingOllie\SystemOneClassification\Exceptions\InvalidProbability;
use EarthlingOllie\SystemOneClassification\Result\Probability;
use EarthlingOllie\SystemOneClassification\Result\Verdict;

/** The answer to a yes/no question: the probability that the statement is true. */
final readonly class YesNoAnswer implements Answer
{
    /** @throws InvalidProbability when the probability is outside [0, 1] */
    public function __construct(public float $probability)
    {
        Probability::check('A probability', $probability);
    }

    /**
     * Whether the probability reaches the threshold.
     *
     * @throws InvalidProbability when the threshold is outside [0, 1]
     */
    public function isYes(float $threshold = 0.5): bool
    {
        return $this->probability >= Probability::check('The threshold', $threshold);
    }

    /**
     * Yes at or above `yes`, No at or below `no`, Unsure in between. When both thresholds are
     * equal, a probability that reaches them is Yes.
     *
     * @throws InvalidProbability when a threshold is outside [0, 1], or `no` is above `yes`
     */
    public function verdict(float $yes, float $no): Verdict
    {
        Probability::check('The yes threshold', $yes);
        Probability::check('The no threshold', $no);

        if ($no > $yes) {
            throw InvalidProbability::thresholdsCrossed($yes, $no);
        }

        return match (true) {
            $this->probability >= $yes => Verdict::Yes,
            $this->probability <= $no => Verdict::No,
            default => Verdict::Unsure,
        };
    }
}
