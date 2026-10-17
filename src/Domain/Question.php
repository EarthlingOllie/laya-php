<?php

declare(strict_types=1);

namespace EarthlingOllie\SystemOneClassification\Domain;

use BackedEnum;
use EarthlingOllie\SystemOneClassification\Domain\Question\ChoiceQuestion;
use EarthlingOllie\SystemOneClassification\Domain\Question\ScoreQuestion;
use EarthlingOllie\SystemOneClassification\Domain\Question\YesNoQuestion;

/**
 * Builds questions: `Question::yesNo('churn', 'Does the customer threaten to cancel?')`.
 *
 * Each factory returns a typed handle (see Question\QuestionHandle).
 */
final readonly class Question
{
    public static function yesNo(string $key, string $instructions): YesNoQuestion
    {
        return new YesNoQuestion($key, $instructions);
    }

    /**
     * `Question::choice('department', Department::class, 'Which department should handle this?')`
     *
     * @template TEnum of BackedEnum
     *
     * @param  class-string<TEnum>  $enum  a backed enum whose cases are the options
     * @return ChoiceQuestion<TEnum>
     */
    public static function choice(string $key, string $enum, string $instructions): ChoiceQuestion
    {
        return new ChoiceQuestion($key, $enum, $instructions);
    }

    /**
     * `Question::score('urgency', Urgency::class, 'How urgent is this?')`
     *
     * @template TEnum of BackedEnum
     *
     * @param  class-string<TEnum>  $enum  an int-backed enum whose cases are the levels, ordered by backing value
     * @return ScoreQuestion<TEnum>
     */
    public static function score(string $key, string $enum, string $instructions): ScoreQuestion
    {
        return new ScoreQuestion($key, $enum, $instructions);
    }
}
