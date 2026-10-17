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

use function is_int;

/**
 * The answer to a score question: the most likely level, typed as the question's enum, and
 * the expected level as a decimal.
 *
 * `expected` is the expected backing value under the distribution, so it compares with the
 * values directly (`$answer->expected >= Urgency::High->value`) and can fall between two
 * levels. Threshold on `answerConfidence` (the probability of the reported answer), which
 * compares across models. `confidence` is the model's own raw value, tagged with its profile.
 *
 * @template TEnum of BackedEnum
 */
final readonly class ScoreAnswer implements Answer
{
    public bool $abstained;

    /** The expected level: the mean backing value, weighted by the distribution. */
    public float $expected;

    /**
     * @param  TEnum  $value  the most probable level
     * @param  Distribution<TEnum>  $distribution  over an int-backed enum; the probabilities are
     *                                             normalised, as the server rounds each one
     *
     * @throws InvalidProbability when answerConfidence or the abstention threshold is outside [0, 1]
     * @throws InvalidDistribution when the value is not one of the distribution's cases, the
     *                             enum is not int-backed, no level has any probability, or
     *                             another level is more probable than the value
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
        $probability = $distribution->probabilityOf($value);

        $this->expected = self::expected($value::class, $distribution);

        // Any of equally likely levels will do; the codec takes the lowest of them.
        foreach ($distribution->entries() as ['probability' => $other]) {
            if ($other > $probability) {
                throw InvalidDistribution::notMostLikely($value, $probability, $other);
            }
        }
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

    /**
     * The mean backing value, each weighted by its probability: sum(p * v) / sum(p). Dividing
     * by the total normalises probabilities the server rounded, which need not add up to 1.
     *
     * @param  Distribution<TEnum>  $distribution
     */
    private static function expected(string $enum, Distribution $distribution): float
    {
        $weighted = 0.0;
        $total = 0.0;

        foreach ($distribution->entries() as ['case' => $case, 'probability' => $probability]) {
            if (! is_int($case->value)) {
                throw InvalidDistribution::notIntBacked($case::class);
            }

            $weighted += $probability * $case->value;
            $total += $probability;
        }

        if ($total <= 0.0) {
            throw InvalidDistribution::noProbability($enum);
        }

        return $weighted / $total;
    }
}
