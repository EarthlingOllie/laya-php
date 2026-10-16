<?php

declare(strict_types=1);

namespace EarthlingOllie\SystemOneClassification\Domain\Question;

use EarthlingOllie\SystemOneClassification\Exceptions\InvalidDecision;
use EarthlingOllie\SystemOneClassification\Result\Answer\YesNoAnswer;

use function trim;

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

        if (trim($instructions) === '') {
            throw InvalidDecision::missingInstructions($key);
        }
    }

    public function answerType(): string
    {
        return YesNoAnswer::class;
    }
}
