<?php

declare(strict_types=1);

namespace EarthlingOllie\SystemOneClassification\Result\Answer;

use EarthlingOllie\SystemOneClassification\Exceptions\InvalidProbability;
use EarthlingOllie\SystemOneClassification\Result\Abstention;
use EarthlingOllie\SystemOneClassification\Result\Verdict;

/**
 * The answer to a yes/no question: the probability that the statement is true.
 *
 * The probability is the model's best guess even when the answer abstained.
 */
final readonly class YesNoAnswer implements Answer
{
    public bool $abstained;

    /** @throws InvalidProbability when the probability or the abstention threshold is outside [0, 1] */
    public function __construct(
        public float $probability,
        public Abstention $abstention = Abstention::NotRequested,
        public ?float $abstentionThreshold = null,
    ) {
        if (! self::isUnitInterval($probability)) {
            throw InvalidProbability::outOfRange('A probability', $probability);
        }

        if ($abstentionThreshold !== null && ! self::isUnitInterval($abstentionThreshold)) {
            throw InvalidProbability::outOfRange('The abstention threshold', $abstentionThreshold);
        }

        $this->abstained = $abstention === Abstention::Abstained;
    }

    /**
     * Whether the probability reaches the threshold.
     *
     * @throws InvalidProbability when the threshold is outside [0, 1]
     */
    public function isYes(float $threshold = 0.5): bool
    {
        self::checkThreshold('The threshold', $threshold);

        return $this->probability >= $threshold;
    }

    /**
     * Yes at or above `yes`, No at or below `no`, Unsure in between. When both thresholds are
     * equal, a probability that reaches them is Yes.
     *
     * @throws InvalidProbability when a threshold is outside [0, 1], or `no` is above `yes`
     */
    public function verdict(float $yes, float $no): Verdict
    {
        self::checkThreshold('The yes threshold', $yes);
        self::checkThreshold('The no threshold', $no);

        if ($no > $yes) {
            throw InvalidProbability::thresholdsCrossed($yes, $no);
        }

        return match (true) {
            $this->probability >= $yes => Verdict::Yes,
            $this->probability <= $no => Verdict::No,
            default => Verdict::Unsure,
        };
    }

    private static function checkThreshold(string $name, float $threshold): void
    {
        if (! self::isUnitInterval($threshold)) {
            throw InvalidProbability::outOfRange($name, $threshold);
        }
    }

    private static function isUnitInterval(float $value): bool
    {
        return $value >= 0.0 && $value <= 1.0;
    }
}
