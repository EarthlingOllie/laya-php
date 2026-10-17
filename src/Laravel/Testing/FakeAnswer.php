<?php

declare(strict_types=1);

namespace EarthlingOllie\SystemOneClassification\Laravel\Testing;

use BackedEnum;
use Closure;
use EarthlingOllie\SystemOneClassification\Domain\Question\ChoiceQuestion;
use EarthlingOllie\SystemOneClassification\Domain\Question\QuestionHandle;
use EarthlingOllie\SystemOneClassification\Domain\Question\ScoreQuestion;
use EarthlingOllie\SystemOneClassification\Domain\Question\YesNoQuestion;
use EarthlingOllie\SystemOneClassification\Exceptions\InvalidDecision;
use EarthlingOllie\SystemOneClassification\Exceptions\InvalidDistribution;
use EarthlingOllie\SystemOneClassification\Exceptions\InvalidFakeAnswer;
use EarthlingOllie\SystemOneClassification\Exceptions\InvalidProbability;
use EarthlingOllie\SystemOneClassification\Result\Abstention;
use EarthlingOllie\SystemOneClassification\Result\Answer\Answer;
use EarthlingOllie\SystemOneClassification\Result\Answer\ChoiceAnswer;
use EarthlingOllie\SystemOneClassification\Result\Answer\ScoreAnswer;
use EarthlingOllie\SystemOneClassification\Result\Answer\YesNoAnswer;
use EarthlingOllie\SystemOneClassification\Result\Confidence;
use EarthlingOllie\SystemOneClassification\Result\Distribution;
use EarthlingOllie\SystemOneClassification\Result\Probability;

use function array_map;
use function array_reverse;
use function array_sum;
use function count;
use function in_array;
use function log;
use function max;

/**
 * A scripted answer for SystemOne::fake(): the question it answers and how to answer it.
 *
 * Build one with the typed helpers: `FakeAnswer::choice($department, Department::Billing)`,
 * `FakeAnswer::score($urgency, Urgency::High, expected: 1.7)`, `FakeAnswer::yesNo($churn, 0.91)`,
 * and `FakeAnswer::abstained(...)` around any of them.
 * The answers are the same immutable types the real client returns, with a distribution a
 * model could have given: the scripted case is always the most likely one. Their raw
 * `confidence` is Laya's (1 - normalised entropy of the distribution), tagged with the profile
 * of the connection the fake answers on.
 *
 * Their abstention is what a server reports for the decision they answer: none (NotRequested)
 * unless it sets a minimum confidence, and then, at that threshold, Abstained when the answer's
 * confidence is below it (or for an abstained() script), Passed otherwise.
 *
 * @template-covariant TAnswer of Answer
 */
final readonly class FakeAnswer
{
    /**
     * @param  QuestionHandle<TAnswer>  $question
     * @param  Closure(string, Abstention, ?float): TAnswer  $answer  builds the answer for a
     *                                                                connection's profile and
     *                                                                the decision's gate
     * @param  float  $answerConfidence  what the gate compares: the server's `answer_confidence`
     * @param  bool  $abstains  whether the answer abstains when the decision is gated, whatever
     *                          its confidence (abstained())
     */
    private function __construct(
        public QuestionHandle $question,
        private Closure $answer,
        private float $answerConfidence,
        private bool $abstains = false,
    ) {}

    /**
     * The scripted answer, abstained: it keeps its value (the model's best guess) and reads as
     * Abstained at the decision's minimum confidence. A decision that sets none is refused, as
     * no server would abstain on it.
     *
     * @template TAbstained of Answer
     *
     * @param  self<TAbstained>  $answer
     * @return self<TAbstained>
     */
    public static function abstained(self $answer): self
    {
        return new self($answer->question, $answer->answer, $answer->answerConfidence, abstains: true);
    }

    /**
     * A yes/no answer with the given probability that the statement is true.
     *
     * @return self<YesNoAnswer>
     *
     * @throws InvalidProbability when the probability is outside [0, 1]
     */
    public static function yesNo(YesNoQuestion $question, float $probability): self
    {
        Probability::check('A probability', $probability);

        // laya-serve's answer_confidence for a yes/no answer: the probability of the side reported.
        return new self(
            $question,
            static fn (string $profile, Abstention $abstention, ?float $threshold): YesNoAnswer => new YesNoAnswer($probability, $abstention, $threshold),
            max($probability, 1.0 - $probability),
        );
    }

    /**
     * A choice answer picking the case with the given confidence (its probability). The rest
     * is shared evenly among the other options, so the confidence can be no lower than an even
     * split (1/n for n options): below that, another option would be the more likely one.
     *
     * @template TEnum of BackedEnum
     *
     * @param  ChoiceQuestion<TEnum>  $question
     * @param  TEnum  $case
     * @return self<ChoiceAnswer<TEnum>>
     *
     * @throws InvalidDistribution when the case is not one of the question's options
     * @throws InvalidProbability when the confidence is outside [0, 1]
     * @throws InvalidFakeAnswer when the confidence is below an even split
     */
    public static function choice(ChoiceQuestion $question, BackedEnum $case, float $confidence = 1.0): self
    {
        self::checkCase($question->enum, $case);
        Probability::check('The answer confidence', $confidence);
        $options = count($question->options());

        if ($confidence * $options < 1.0) {
            throw InvalidFakeAnswer::confidenceBelowShare($question->key, $confidence, $options);
        }

        // One option takes all of it (the confidence must then be 1): nothing to share.
        $rest = (1.0 - $confidence) / max(1, $options - 1);
        $distribution = new Distribution(array_map(
            static fn (array $option): array => ['case' => $option['case'], 'probability' => $option['case'] === $case ? $confidence : $rest],
            $question->options(),
        ));

        return new self($question, static fn (string $profile, Abstention $abstention, ?float $threshold): ChoiceAnswer => new ChoiceAnswer(
            $case,
            $confidence,
            new Confidence(self::rawConfidence($distribution), $profile),
            $distribution,
            $abstention,
            $threshold,
        ), $confidence);
    }

    /**
     * A score answer with the case as its most likely level, and the given expected level (the
     * mean backing value), or the case's own value when none is given.
     *
     * The distribution is the case alone, blended with the fewest levels that move the mean
     * towards the expected level: so an expected level keeps the case the most likely level,
     * and one no such distribution reaches is refused. Floating point makes the answer's
     * `expected` equal to the scripted one up to rounding.
     *
     * @template TEnum of BackedEnum
     *
     * @param  ScoreQuestion<TEnum>  $question
     * @param  TEnum  $case
     * @return self<ScoreAnswer<TEnum>>
     *
     * @throws InvalidDistribution when the case is not one of the question's levels
     * @throws InvalidFakeAnswer when the case cannot be the most likely level at that expected level
     */
    public static function score(ScoreQuestion $question, BackedEnum $case, ?float $expected = null): self
    {
        $levels = $question->levels();
        $value = self::levelValue($question, $case);
        $expected ??= $value;
        $down = self::widest($levels, $value, up: false);
        $up = self::widest(array_reverse($levels), $value, up: true);
        $lowest = self::mean($down);
        $highest = self::mean($up);

        // At the lowest mean the case only ties with the lower levels, and a tie goes to the
        // lowest of them (the server's argmax), so that end is open unless it is the case's own
        // value. Written as "not inside" so that NAN, which fails every comparison, is refused.
        if (! (($expected > $lowest || (float) $expected === (float) $value) && $expected <= $highest)) {
            throw InvalidFakeAnswer::expectedOutOfReach($question->key, $case, $expected, $lowest, $lowest < $value, $highest);
        }

        // Blend the case alone (mean = its value) with the set on the expected level's side, in
        // the proportion that puts the mean on the expected level; at its own value, with nothing.
        [$blend, $weight] = match ($expected <=> $value) {
            -1 => [$down, ($expected - $value) / ($lowest - $value)],
            1 => [$up, ($expected - $value) / ($highest - $value)],
            default => [[], 0.0],
        };

        $distribution = new Distribution(array_map(
            static fn (array $level): array => [
                'case' => $level['case'],
                'probability' => ($level['case'] === $case ? 1.0 - $weight : 0.0) + (in_array($level['value'], $blend, true) ? $weight / count($blend) : 0.0),
            ],
            $levels,
        ));
        $answerConfidence = $distribution->probabilityOf($case);

        return new self($question, static fn (string $profile, Abstention $abstention, ?float $threshold): ScoreAnswer => new ScoreAnswer(
            $case,
            $answerConfidence,
            new Confidence(self::rawConfidence($distribution), $profile),
            $distribution,
            $abstention,
            $threshold,
        ), $answerConfidence);
    }

    /**
     * What the fake answers a question with no script: a certain no for a yes/no question, the
     * first option (in declaration order) of a choice, the lowest level of a score.
     *
     * @template TAnswer2 of Answer
     *
     * @param  QuestionHandle<TAnswer2>  $question
     * @return self<Answer>
     *
     * @throws InvalidDecision for a question type the wire format does not have
     */
    public static function unscripted(QuestionHandle $question): self
    {
        return match (true) {
            $question instanceof YesNoQuestion => self::yesNo($question, 0.0),
            $question instanceof ChoiceQuestion => self::choice($question, $question->options()[0]['case']),
            $question instanceof ScoreQuestion => self::score($question, $question->levels()[0]['case']),
            default => throw InvalidDecision::unsupportedQuestion($question),
        };
    }

    /**
     * The answer, its raw confidence tagged with the given model profile, gated as laya-serve
     * gates it at the decision's minimum confidence: Abstained below it (comparing
     * `answer_confidence`, as the server does) or for an abstained() script, Passed otherwise.
     *
     * @return TAnswer
     *
     * @throws InvalidFakeAnswer when the answer abstains but the decision sets no minimum confidence
     */
    public function answerFor(string $profile, ?float $minConfidence = null): Answer
    {
        if ($minConfidence === null) {
            return $this->abstains
                ? throw InvalidFakeAnswer::abstainedWithoutGate($this->question->key)
                : ($this->answer)($profile, Abstention::NotRequested, null);
        }

        $abstains = $this->abstains || $this->answerConfidence < $minConfidence;

        return ($this->answer)($profile, $abstains ? Abstention::Abstained : Abstention::Passed, $minConfidence);
    }

    /**
     * @param  class-string<BackedEnum>  $enum
     *
     * @throws InvalidDistribution when the case is not one of the enum's
     */
    private static function checkCase(string $enum, BackedEnum $case): void
    {
        if (! $case instanceof $enum) {
            throw InvalidDistribution::unknownCase($case, $enum);
        }
    }

    /**
     * @template TEnum of BackedEnum
     *
     * @param  ScoreQuestion<TEnum>  $question
     * @param  TEnum  $case
     *
     * @throws InvalidDistribution when the case is not one of the question's levels
     */
    private static function levelValue(ScoreQuestion $question, BackedEnum $case): int
    {
        foreach ($question->levels() as $level) {
            if ($level['case'] === $case) {
                return $level['value'];
            }
        }

        throw InvalidDistribution::unknownCase($case, $question->enum);
    }

    /**
     * The backing values of the set of levels, the case's among them, whose mean is furthest
     * from the case's value in one direction: the case plus every level, most extreme first,
     * that still moves the mean that way. A level at the mean is left out, so the set is the
     * smallest one. (The case's own value never moves it, as the mean only moves away from it.)
     *
     * @param  list<array{case: BackedEnum, value: int, description: ?string}>  $levels  most extreme first
     * @return non-empty-list<int>
     */
    private static function widest(array $levels, int $value, bool $up): array
    {
        $set = [$value];

        foreach ($levels as $level) {
            $mean = self::mean($set);

            if ($up ? $level['value'] > $mean : $level['value'] < $mean) {
                $set[] = $level['value'];
            }
        }

        return $set;
    }

    /**
     * @param  non-empty-list<int>  $values
     */
    private static function mean(array $values): float
    {
        return array_sum($values) / count($values);
    }

    /**
     * Laya's raw confidence: 1 - the distribution's entropy over the largest it can be.
     *
     * @template TCase of BackedEnum
     *
     * @param  Distribution<TCase>  $distribution
     */
    private static function rawConfidence(Distribution $distribution): float
    {
        $entropy = 0.0;

        foreach ($distribution->entries() as ['probability' => $probability]) {
            $entropy -= $probability > 0.0 ? $probability * log($probability) : 0.0;
        }

        // One case has no uncertainty (its entropy is 0); max() avoids dividing by log(1) = 0.
        return 1.0 - $entropy / log(max(2, count($distribution)));
    }
}
