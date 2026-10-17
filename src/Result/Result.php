<?php

declare(strict_types=1);

namespace EarthlingOllie\SystemOneClassification\Result;

use EarthlingOllie\SystemOneClassification\Domain\Question\QuestionHandle;
use EarthlingOllie\SystemOneClassification\Exceptions\UnknownQuestion;
use EarthlingOllie\SystemOneClassification\Result\Answer\Answer;

use function array_keys;
use function array_map;
use function array_udiff_assoc;
use function preg_replace;

/** The answers to one decision, read with the question handles that asked them. */
final readonly class Result
{
    /**
     * @param  array<string, array{question: QuestionHandle<Answer>, answer: Answer}>  $answers  each asked handle with its answer, keyed by question key
     */
    public function __construct(private array $answers) {}

    /**
     * Reads the answer to an asked question.
     *
     * Handles are values: an equal handle (same class, key, instructions and every other setting)
     * built elsewhere reads the same answer. A handle that only shares the key, for example one
     * from another decision, is refused rather than handed that decision's answer. Everything the
     * model was shown counts, a choice question's case descriptions included: a handle rebuilt
     * under another locale is another question, so read the result with the handle you asked.
     *
     * @template TAnswer of Answer
     *
     * @param  QuestionHandle<TAnswer>  $question
     * @return TAnswer
     *
     * @throws UnknownQuestion when the question was not part of the decision
     */
    public function get(QuestionHandle $question): Answer
    {
        $key = $question->key;
        ['question' => $asked, 'answer' => $answer] = $this->answers[$key] ?? throw UnknownQuestion::notAsked($key, array_keys($this->answers));

        if ($asked::class !== $question::class) {
            throw UnknownQuestion::otherQuestion($key, ['class']);
        }

        $differing = self::differingFields($asked, $question);

        if ($differing !== []) {
            throw UnknownQuestion::otherQuestion($key, $differing);
        }

        $type = $question->answerType();

        if (! $answer instanceof $type) {
            throw UnknownQuestion::otherType($key, $answer::class, $type);
        }

        return $answer;
    }

    /**
     * The properties, private ones included, that are missing from or not strictly equal on either of two handles of one class.
     * Nested objects (enum cases) compare by identity.
     *
     * @param  QuestionHandle<Answer>  $asked
     * @param  QuestionHandle<Answer>  $given
     * @return list<string>
     */
    private static function differingFields(QuestionHandle $asked, QuestionHandle $given): array
    {
        $same = static fn (mixed $one, mixed $other): int => (int) ($one !== $other);
        // Both ways, so a property only one of them has initialised counts too.
        $differing = array_udiff_assoc((array) $asked, (array) $given, $same) + array_udiff_assoc((array) $given, (array) $asked, $same);

        // A private property's array key is "\0Class\0name"; keep the name.
        return array_map(
            static fn (int|string $property): string => preg_replace('/^\x00[^\x00]*\x00/', '', "{$property}") ?? "{$property}",
            array_keys($differing),
        );
    }
}
