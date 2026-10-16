<?php

declare(strict_types=1);

namespace EarthlingOllie\SystemOneClassification\Domain\Question;

use EarthlingOllie\SystemOneClassification\Exceptions\InvalidDecision;

use function trim;

/** The rules every question key follows, whatever the question type. */
final readonly class QuestionKey
{
    /**
     * @return non-empty-string the key, unchanged
     *
     * @throws InvalidDecision when the key is blank, or a whole number PHP would store as an
     *                         integer array key (the questions would then go out as a JSON list)
     */
    public static function validate(string $key): string
    {
        if (trim($key) === '') {
            throw InvalidDecision::emptyKey();
        }

        // PHP's own rule for when an array key becomes an int: a canonical decimal in range.
        if ((string) (int) $key === $key) {
            throw InvalidDecision::integerKey($key);
        }

        return $key;
    }
}
