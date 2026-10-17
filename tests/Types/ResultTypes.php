<?php

declare(strict_types=1);

/*
 * Type-inference tests for reading answers. Nothing here is executed: PHPStan analyses
 * this file and fails if an assertType() expectation does not match the inferred type.
 */

namespace EarthlingOllie\SystemOneClassification\Tests\Types;

use EarthlingOllie\SystemOneClassification\Domain\Context;
use EarthlingOllie\SystemOneClassification\Domain\Decision;
use EarthlingOllie\SystemOneClassification\Domain\Question;
use EarthlingOllie\SystemOneClassification\Domain\Question\QuestionHandle;
use EarthlingOllie\SystemOneClassification\Domain\Question\YesNoQuestion;
use EarthlingOllie\SystemOneClassification\Laravel\Facades\SystemOne;
use EarthlingOllie\SystemOneClassification\Result\Abstention;
use EarthlingOllie\SystemOneClassification\Result\Answer\Answer;
use EarthlingOllie\SystemOneClassification\Result\Answer\YesNoAnswer;
use EarthlingOllie\SystemOneClassification\Result\Meta;
use EarthlingOllie\SystemOneClassification\Result\Result;
use EarthlingOllie\SystemOneClassification\Result\Verdict;

use function PHPStan\Testing\assertType;

function yesNoAnswerTypes(Result $result): void
{
    $churn = Question::yesNo('churn', 'Does the customer threaten to cancel?');

    assertType(YesNoQuestion::class, $churn);
    assertType(YesNoAnswer::class, $result->get($churn));
    assertType('float', $result->get($churn)->probability);
    assertType('bool', $result->get($churn)->isYes(threshold: 0.8));
    assertType(Verdict::class, $result->get($churn)->verdict(yes: 0.8, no: 0.2));
    assertType('bool', $result->get($churn)->abstained);
    assertType(Abstention::class, $result->get($churn)->abstention);
    assertType('float|null', $result->get($churn)->abstentionThreshold);
}

function metaTypes(Result $result): void
{
    assertType(Meta::class, $result->meta);
    assertType('string|null', $result->meta->model);
    assertType('string|null', $result->meta->checkpoint);
    assertType('string|null', $result->meta->routingReason);
    assertType('int<0, max>|null', $result->meta->inputTokens);
    assertType('int<0, max>|null', $result->meta->outputTokens);
    assertType('float|null', $result->meta->latencyMs);
}

/**
 * @param  QuestionHandle<Answer>  $anyQuestion
 */
function genericHandleTypes(Result $result, QuestionHandle $anyQuestion): void
{
    assertType(Answer::class, $result->get($anyQuestion));
    assertType('bool', $result->get($anyQuestion)->abstained);
}

function facadeTypes(): void
{
    $churn = Question::yesNo('churn', 'Does the customer threaten to cancel?');
    $decision = Decision::about(Context::text('We were billed twice.'))->ask($churn);

    assertType(Decision::class, $decision);
    assertType(Result::class, SystemOne::decide($decision));
    assertType(YesNoAnswer::class, SystemOne::decide($decision)->get($churn));
    assertType(Result::class, SystemOne::connection('local')->decide($decision));
}
