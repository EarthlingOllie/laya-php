<?php

declare(strict_types=1);

namespace EarthlingOllie\SystemOneClassification\Domain;

use EarthlingOllie\SystemOneClassification\Domain\Question\QuestionHandle;
use EarthlingOllie\SystemOneClassification\Exceptions\InvalidDecision;
use EarthlingOllie\SystemOneClassification\Result\Answer\Answer;

use function array_column;
use function in_array;

/**
 * An immutable request: questions about one context. Every builder returns a new instance.
 */
final readonly class Decision
{
    /**
     * @param  list<QuestionHandle<Answer>>  $questions
     */
    private function __construct(
        public Context $context,
        private array $questions,
    ) {}

    public static function about(Context $context): self
    {
        return new self($context, []);
    }

    /**
     * A new decision that also asks the given questions. Keys must be unique.
     *
     * @param  QuestionHandle<Answer>  ...$questions
     *
     * @throws InvalidDecision when a key is already asked
     */
    public function ask(QuestionHandle ...$questions): self
    {
        $asked = $this->questions;
        $keys = array_column($asked, 'key');

        foreach ($questions as $question) {
            if (in_array($question->key, $keys, true)) {
                throw InvalidDecision::duplicateKey($question->key);
            }

            $keys[] = $question->key;
            $asked[] = $question;
        }

        return new self($this->context, $asked);
    }

    /**
     * @return list<QuestionHandle<Answer>>
     */
    public function questions(): array
    {
        return $this->questions;
    }
}
