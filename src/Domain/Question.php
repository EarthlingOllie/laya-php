<?php

declare(strict_types=1);

namespace EarthlingOllie\SystemOneClassification\Domain;

use EarthlingOllie\SystemOneClassification\Domain\Question\YesNoQuestion;

/**
 * Builds questions: `Question::yesNo('churn', 'Does the customer threaten to cancel?')`.
 *
 * Each factory returns a typed handle (see Question\QuestionHandle). `choice()` and
 * `score()` join `yesNo()` here.
 */
final readonly class Question
{
    public static function yesNo(string $key, string $instructions): YesNoQuestion
    {
        return new YesNoQuestion($key, $instructions);
    }
}
