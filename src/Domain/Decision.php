<?php

declare(strict_types=1);

namespace EarthlingOllie\SystemOneClassification\Domain;

use EarthlingOllie\SystemOneClassification\Domain\Question\QuestionHandle;
use EarthlingOllie\SystemOneClassification\Exceptions\InvalidDecision;
use EarthlingOllie\SystemOneClassification\Result\Answer\Answer;

use function array_column;
use function in_array;
use function trim;

/**
 * An immutable request: questions about one context. Every builder returns a new instance.
 */
final readonly class Decision
{
    /**
     * @param  list<QuestionHandle<Answer>>  $questions
     * @param  ?string  $model  the checkpoint to answer with (the wire's `model`), or null to
     *                          let the server choose
     */
    private function __construct(
        public Context $context,
        private array $questions,
        public ?string $model,
    ) {}

    public static function about(Context $context): self
    {
        return new self($context, [], null);
    }

    /**
     * A new decision pinned to one checkpoint, such as 'english' or 'multilingual'.
     *
     * Which values are valid depends on the model: the connection's profile checks it before
     * sending.
     *
     * @throws InvalidDecision when the model is blank or has whitespace around it
     */
    public function withModel(string $model): self
    {
        $trimmed = trim($model);

        if ($trimmed === '') {
            throw InvalidDecision::blankModel();
        }

        // Model values are exact names: ' english' is not 'english' to the server either.
        if ($trimmed !== $model) {
            throw InvalidDecision::paddedModel($model, $trimmed);
        }

        return new self($this->context, $this->questions, $model);
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

        return new self($this->context, $asked, $this->model);
    }

    /**
     * @return list<QuestionHandle<Answer>>
     */
    public function questions(): array
    {
        return $this->questions;
    }
}
