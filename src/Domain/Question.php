<?php

declare(strict_types=1);

namespace EarthlingOllie\SystemOneClassification\Domain;

use BackedEnum;
use EarthlingOllie\SystemOneClassification\Domain\Question\ChoiceQuestion;
use EarthlingOllie\SystemOneClassification\Domain\Question\YesNoQuestion;

/**
 * Builds questions: `Question::yesNo('churn', 'Does the customer threaten to cancel?')`.
 *
 * Each factory returns a typed handle (see Question\QuestionHandle). `score()` joins
 * `yesNo()` and `choice()` here.
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
}
