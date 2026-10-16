<?php

declare(strict_types=1);

namespace EarthlingOllie\SystemOneClassification\Domain\Question;

use EarthlingOllie\SystemOneClassification\Result\Answer\Answer;

/**
 * A question you keep and pass back to Result::get() to read its answer.
 *
 * TAnswer is the answer type the question produces, so `get()` returns it without a cast.
 * The key is what the wire format and logs use; it is unique within a decision.
 *
 * @template-covariant TAnswer of Answer
 */
interface QuestionHandle
{
    /** @var non-empty-string */
    public string $key { get; }

    public string $instructions { get; }

    /**
     * @return class-string<TAnswer>
     */
    public function answerType(): string;
}
