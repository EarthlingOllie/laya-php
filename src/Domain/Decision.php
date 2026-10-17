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
     * @param  ?float  $minConfidence  `min_confidence`: answers whose confidence falls below it abstain
     * @param  ?string  $language  `lang`: the language code of the context, which picks the checkpoint
     * @param  ?string  $languageHint  `lang_guess`: a weaker hint the server's language detection may overrule
     * @param  ?positive-int  $maxLength  `max_len`: the token budget for the whole input
     * @param  ?positive-int  $headMaxLength  `head_max_len`: the token budget for each question head
     */
    private function __construct(
        public Context $context,
        private array $questions,
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

        return new self($this->context, $asked, $this->minConfidence, $this->language, $this->languageHint, $this->maxLength, $this->headMaxLength);
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

        return new self($this->context, $this->questions, $minConfidence, $this->language, $this->languageHint, $this->maxLength, $this->headMaxLength);
    }

    /**
     * A new decision that tells the server the context's language (`lang`), such as 'de'.
     * Whitespace around the code is trimmed.
     *
     * @throws InvalidDecision when the code is blank
     */
    public function withLanguage(string $code): self
    {
        return new self($this->context, $this->questions, $this->minConfidence, self::code('The language code', $code), $this->languageHint, $this->maxLength, $this->headMaxLength);
    }

    /**
     * A new decision with a language hint (`lang_guess`) for the server's language detection.
     * Whitespace around the code is trimmed.
     *
     * @throws InvalidDecision when the code is blank
     */
    public function withLanguageHint(string $code): self
    {
        return new self($this->context, $this->questions, $this->minConfidence, $this->language, self::code('The language hint', $code), $this->maxLength, $this->headMaxLength);
    }

    /**
     * A new decision with a token budget for the whole input (`max_len`).
     *
     * @throws InvalidDecision when the length is not positive
     */
    public function withMaxLength(int $tokens): self
    {
        return new self($this->context, $this->questions, $this->minConfidence, $this->language, $this->languageHint, self::positive('The max length', $tokens), $this->headMaxLength);
    }

    /**
     * A new decision with a token budget for each question head (`head_max_len`).
     *
     * @throws InvalidDecision when the length is not positive
     */
    public function withHeadMaxLength(int $tokens): self
    {
        return new self($this->context, $this->questions, $this->minConfidence, $this->language, $this->languageHint, $this->maxLength, self::positive('The head max length', $tokens));
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
