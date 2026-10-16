<?php

declare(strict_types=1);

namespace EarthlingOllie\SystemOneClassification\Result;

use EarthlingOllie\SystemOneClassification\Domain\Question\QuestionHandle;
use EarthlingOllie\SystemOneClassification\Exceptions\UnknownQuestion;
use EarthlingOllie\SystemOneClassification\Result\Answer\Answer;

use function array_keys;

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
     * from another decision, is refused rather than handed that decision's answer.
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

        // Strict on every property, private ones included; nested objects (enum cases) compare by identity.
        if ($asked::class !== $question::class || (array) $asked !== (array) $question) {
            throw UnknownQuestion::otherQuestion($key);
        }

        $type = $question->answerType();

        if (! $answer instanceof $type) {
            throw UnknownQuestion::otherType($key, $answer::class, $type);
        }

        return $answer;
    }
}
