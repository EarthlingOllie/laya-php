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
use EarthlingOllie\SystemOneClassification\Result\Answer\Answer;
use EarthlingOllie\SystemOneClassification\Result\Answer\YesNoAnswer;
use EarthlingOllie\SystemOneClassification\Result\Result;
use EarthlingOllie\SystemOneClassification\Result\ResultSet;
use EarthlingOllie\SystemOneClassification\Result\Usage;
use EarthlingOllie\SystemOneClassification\Result\Verdict;

use function count;
use function PHPStan\Testing\assertType;

function yesNoAnswerTypes(Result $result): void
{
    $churn = Question::yesNo('churn', 'Does the customer threaten to cancel?');

    assertType(YesNoQuestion::class, $churn);
    assertType(YesNoAnswer::class, $result->get($churn));
    assertType('float', $result->get($churn)->probability);
    assertType('bool', $result->get($churn)->isYes(threshold: 0.8));
    assertType(Verdict::class, $result->get($churn)->verdict(yes: 0.8, no: 0.2));
}

/**
 * @param  QuestionHandle<Answer>  $anyQuestion
 */
function genericHandleTypes(Result $result, QuestionHandle $anyQuestion): void
{
    assertType(Answer::class, $result->get($anyQuestion));
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

function resultSetTypes(): void
{
    $churn = Question::yesNo('churn', 'Does the customer threaten to cancel?');
    $decision = Decision::about(Context::text('template'))->ask($churn);
    $results = SystemOne::decideMany([Context::text('We were billed twice.')], $decision);

    assertType(ResultSet::class, $results);
    assertType(ResultSet::class, SystemOne::connection('local')->decideMany([Context::text('x')], $decision));
    assertType(Result::class, $results[0]);
    assertType(YesNoAnswer::class, $results[0]->get($churn));
    assertType('int<0, max>', count($results));
    assertType(Usage::class.'|null', $results->usage);
    assertType('list<'.Result::class.'>', $results->all());

    foreach ($results as $position => $result) {
        assertType('int', $position);
        assertType('float', $result->get($churn)->probability);
    }
}
