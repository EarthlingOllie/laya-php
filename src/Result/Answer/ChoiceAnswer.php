<?php

declare(strict_types=1);

namespace EarthlingOllie\SystemOneClassification\Result\Answer;

use BackedEnum;
use EarthlingOllie\SystemOneClassification\Exceptions\InvalidDistribution;
use EarthlingOllie\SystemOneClassification\Exceptions\InvalidProbability;
use EarthlingOllie\SystemOneClassification\Result\Abstention;
use EarthlingOllie\SystemOneClassification\Result\Confidence;
use EarthlingOllie\SystemOneClassification\Result\Distribution;
use EarthlingOllie\SystemOneClassification\Result\Probability;

/**
 * The answer to a choice question: the case the model picked, typed as the question's enum.
 *
 * Threshold on `answerConfidence` (the probability of the reported answer), which compares
 * across models. `confidence` is the model's own raw value, tagged with its profile.
 *
 * @template TEnum of BackedEnum
 */
final readonly class ChoiceAnswer implements Answer
{
    public bool $abstained;

    /**
     * @param  TEnum  $value
     * @param  Distribution<TEnum>  $distribution
     *
     * @throws InvalidProbability when answerConfidence or the abstention threshold is outside [0, 1]
     * @throws InvalidDistribution when the value is not one of the distribution's cases
     */
    public function __construct(
        public BackedEnum $value,
        public float $answerConfidence,
        public Confidence $confidence,
        public Distribution $distribution,
        public Abstention $abstention = Abstention::NotRequested,
        public ?float $abstentionThreshold = null,
    ) {
        Probability::check('The answer confidence', $answerConfidence);

        if ($abstentionThreshold !== null) {
            Probability::check('The abstention threshold', $abstentionThreshold);
        }

        $this->abstained = $abstention === Abstention::Abstained;
        $distribution->probabilityOf($value);
    }

    /**
     * @param  TEnum  $case
     */
    public function probabilityOf(BackedEnum $case): float
    {
        return $this->distribution->probabilityOf($case);
    }

    /**
     * Whether answerConfidence reaches the threshold.
     *
     * @throws InvalidProbability when the threshold is outside [0, 1]
     */
    public function isConfident(float $threshold): bool
    {
        return $this->answerConfidence >= Probability::check('The threshold', $threshold);
    }
}
