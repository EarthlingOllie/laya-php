<?php

declare(strict_types=1);

namespace EarthlingOllie\SystemOneClassification\Domain\Question;

use EarthlingOllie\SystemOneClassification\Result\Answer\YesNoAnswer;

/**
 * A yes/no statement check (Laya's `noul`): the model returns P(true).
 *
 * Build it with Question::yesNo().
 *
 * @implements QuestionHandle<YesNoAnswer>
 */
final readonly class YesNoQuestion implements QuestionHandle
{
    /** @var non-empty-string */
    public string $key;

    public function __construct(string $key, public string $instructions)
    {
        $this->key = QuestionKey::validate($key);
        Instructions::validate($key, $instructions);
    }

    public function answerType(): string
    {
        return YesNoAnswer::class;
    }
}
