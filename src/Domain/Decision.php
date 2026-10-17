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
 * An immutable request: questions about one context, plus the optional request controls.
 * Every builder returns a new instance.
 *
 * A control left null is not sent, so the server applies its own default.
 */
final readonly class Decision
{
    /**
     * @param  list<QuestionHandle<Answer>>  $questions
     * @param  ?string  $model  the checkpoint to answer with (the wire's `model`), or null to
     *                          let the server choose
     * @param  ?float  $minConfidence  `min_confidence`: answers whose confidence falls below it abstain
     * @param  ?string  $language  `lang`: the language code of the context, which picks the checkpoint
     * @param  ?string  $languageHint  `lang_guess`: a weaker hint the server's language detection may overrule
     * @param  ?positive-int  $maxLength  `max_len`: the token budget for the whole input
     * @param  ?positive-int  $headMaxLength  `head_max_len`: the token budget for each question head
     */
    private function __construct(
        public Context $context,
        private array $questions,
        public ?string $model = null,
        public ?float $minConfidence = null,
        public ?string $language = null,
        public ?string $languageHint = null,
        public ?int $maxLength = null,
        public ?int $headMaxLength = null,
    ) {}

    public static function about(Context $context): self
    {
        return new self($context, []);
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

        return $this->copy(model: $model);
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

        return $this->copy(questions: $asked);
    }

    /**
     * A new decision whose answers abstain when their confidence is below the threshold
     * (`min_confidence`). An abstained answer still carries the model's best guess.
     *
     * @throws InvalidDecision when the threshold is outside [0, 1]
     */
    public function withMinConfidence(float $minConfidence): self
    {
        if (! ($minConfidence >= 0.0 && $minConfidence <= 1.0)) {
            throw InvalidDecision::minConfidenceOutOfRange($minConfidence);
        }

        return $this->copy(minConfidence: $minConfidence);
    }

    /**
     * A new decision that tells the server the context's language (`lang`), such as 'de'.
     * Whitespace around the code is trimmed.
     *
     * @throws InvalidDecision when the code is blank
     */
    public function withLanguage(string $code): self
    {
        return $this->copy(language: self::code('The language code', $code));
    }

    /**
     * A new decision with a language hint (`lang_guess`) for the server's language detection.
     * Whitespace around the code is trimmed.
     *
     * @throws InvalidDecision when the code is blank
     */
    public function withLanguageHint(string $code): self
    {
        return $this->copy(languageHint: self::code('The language hint', $code));
    }

    /**
     * A new decision with a token budget for the whole input (`max_len`).
     *
     * @throws InvalidDecision when the length is not positive
     */
    public function withMaxLength(int $tokens): self
    {
        return $this->copy(maxLength: self::positive('The max length', $tokens));
    }

    /**
     * A new decision with a token budget for each question head (`head_max_len`).
     *
     * @throws InvalidDecision when the length is not positive
     */
    public function withHeadMaxLength(int $tokens): self
    {
        return $this->copy(headMaxLength: self::positive('The head max length', $tokens));
    }

    /**
     * A new decision that asks the same questions, with the same settings, about another
     * context. decideMany() uses it to ask one decision's questions about many contexts.
     */
    public function withContext(Context $context): self
    {
        return $this->copy(context: $context);
    }

    /**
     * @return list<QuestionHandle<Answer>>
     */
    public function questions(): array
    {
        return $this->questions;
    }

    /**
     * @return non-empty-string the code without surrounding whitespace
     *
     * @throws InvalidDecision
     */
    /**
     * This decision with the given parts changed. Every builder sets a non-null value, so a
     * null argument means "keep this one".
     *
     * @param  ?list<QuestionHandle<Answer>>  $questions
     * @param  ?positive-int  $maxLength
     * @param  ?positive-int  $headMaxLength
     */
    private function copy(
        ?Context $context = null,
        ?array $questions = null,
        ?string $model = null,
        ?float $minConfidence = null,
        ?string $language = null,
        ?string $languageHint = null,
        ?int $maxLength = null,
        ?int $headMaxLength = null,
    ): self {
        return new self(
            $context ?? $this->context,
            $questions ?? $this->questions,
            $model ?? $this->model,
            $minConfidence ?? $this->minConfidence,
            $language ?? $this->language,
            $languageHint ?? $this->languageHint,
            $maxLength ?? $this->maxLength,
            $headMaxLength ?? $this->headMaxLength,
        );
    }

    private static function code(string $name, string $code): string
    {
        $code = trim($code);

        if ($code === '') {
            throw InvalidDecision::emptyLanguage($name);
        }

        return $code;
    }

    /**
     * @return positive-int
     *
     * @throws InvalidDecision
     */
    private static function positive(string $name, int $tokens): int
    {
        if ($tokens < 1) {
            throw InvalidDecision::nonPositiveLength($name, $tokens);
        }

        return $tokens;
    }
}
