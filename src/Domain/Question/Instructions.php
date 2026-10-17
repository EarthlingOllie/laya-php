<?php

declare(strict_types=1);

namespace EarthlingOllie\SystemOneClassification\Domain\Question;

use EarthlingOllie\SystemOneClassification\Exceptions\InvalidDecision;

use function trim;

/** The rule every question's instructions follow, whatever the question type. */
final readonly class Instructions
{
    /**
     * @return string the instructions, unchanged
     *
     * @throws InvalidDecision when they are empty or blank
     */
    public static function validate(string $key, string $instructions): string
    {
        if (trim($instructions) === '') {
            throw InvalidDecision::missingInstructions($key);
        }

        return $instructions;
    }
}
