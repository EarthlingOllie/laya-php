<?php

declare(strict_types=1);

namespace EarthlingOllie\SystemOneClassification\Domain\Question;

use EarthlingOllie\SystemOneClassification\Result\Answer\Answer;

use function array_keys;
use function array_map;
use function array_udiff_assoc;
use function preg_replace;

/**
 * When two question handles are the same question: the one identity Result::get() and the
 * testing fake's scripts go by.
 *
 * Handles are values: two handles of one class with strictly equal properties, private ones
 * included, are the same question, wherever they were built. Everything the model is shown
 * counts, a choice question's case descriptions among it.
 */
final readonly class HandleIdentity
{
    /**
     * What makes two handles different questions: ['class'] when their classes differ, otherwise
     * the properties missing from or not strictly equal on either one (nested objects, the enum
     * cases, compare by identity). Empty when they are the same question.
     *
     * @param  QuestionHandle<Answer>  $one
     * @param  QuestionHandle<Answer>  $other
     * @return list<string>
     */
    public static function differences(QuestionHandle $one, QuestionHandle $other): array
    {
        if ($one::class !== $other::class) {
            return ['class'];
        }

        $same = static fn (mixed $a, mixed $b): int => (int) ($a !== $b);
        // Both ways, so a property only one of them has initialised counts too.
        $differing = array_udiff_assoc((array) $one, (array) $other, $same) + array_udiff_assoc((array) $other, (array) $one, $same);

        // A private property's array key is "\0Class\0name"; keep the name.
        return array_map(
            static fn (int|string $property): string => preg_replace('/^\x00[^\x00]*\x00/', '', "{$property}") ?? "{$property}",
            array_keys($differing),
        );
    }
}
