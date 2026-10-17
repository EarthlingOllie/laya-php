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
use EarthlingOllie\SystemOneClassification\Domain\Question\ChoiceQuestion;
use EarthlingOllie\SystemOneClassification\Domain\Question\QuestionHandle;
use EarthlingOllie\SystemOneClassification\Domain\Question\ScoreQuestion;
use EarthlingOllie\SystemOneClassification\Domain\Question\YesNoQuestion;
use EarthlingOllie\SystemOneClassification\Laravel\Facades\SystemOne;
use EarthlingOllie\SystemOneClassification\Result\Answer\Answer;
use EarthlingOllie\SystemOneClassification\Result\Answer\ChoiceAnswer;
use EarthlingOllie\SystemOneClassification\Result\Answer\ScoreAnswer;
use EarthlingOllie\SystemOneClassification\Result\Answer\YesNoAnswer;
use EarthlingOllie\SystemOneClassification\Result\Confidence;
use EarthlingOllie\SystemOneClassification\Result\Distribution;
use EarthlingOllie\SystemOneClassification\Result\Result;
use EarthlingOllie\SystemOneClassification\Result\Verdict;
use EarthlingOllie\SystemOneClassification\Tests\Fixtures\Enums\Department;
use EarthlingOllie\SystemOneClassification\Tests\Fixtures\Enums\Priority;
use EarthlingOllie\SystemOneClassification\Tests\Fixtures\Enums\Urgency;

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

function choiceAnswerTypes(Result $result): void
{
    $department = Question::choice('department', Department::class, 'Which department should handle this?');

    assertType(ChoiceQuestion::class.'<'.Department::class.'>', $department);
    assertType(ChoiceAnswer::class.'<'.Department::class.'>', $result->get($department));
    assertType(Department::class, $result->get($department)->value);
    assertType('float', $result->get($department)->answerConfidence);
    assertType(Confidence::class, $result->get($department)->confidence);
    assertType('float', $result->get($department)->confidence->value);
    assertType('string', $result->get($department)->confidence->profile);
    assertType('bool', $result->get($department)->isConfident(0.8));
    assertType('float', $result->get($department)->probabilityOf(Department::Other));
    assertType(Distribution::class.'<'.Department::class.'>', $result->get($department)->distribution);
    assertType('list<'.Department::class.'>', $result->get($department)->distribution->cases());
    assertType('non-empty-list<array{case: '.Department::class.', description: string|null}>', $department->options());

    assertType('list<array{case: '.Department::class.', probability: float}>', $result->get($department)->distribution->entries());

    // Int-backed enums are typed the same way.
    $priority = Question::choice('priority', Priority::class, 'How soon?');

    assertType(Priority::class, $result->get($priority)->value);
}

function scoreAnswerTypes(Result $result): void
{
    $urgency = Question::score('urgency', Urgency::class, 'How urgent is this?');

    assertType(ScoreQuestion::class.'<'.Urgency::class.'>', $urgency);
    assertType(ScoreAnswer::class.'<'.Urgency::class.'>', $result->get($urgency));
    assertType(Urgency::class, $result->get($urgency)->value);
    assertType('float', $result->get($urgency)->expected);
    assertType('float', $result->get($urgency)->answerConfidence);
    assertType(Confidence::class, $result->get($urgency)->confidence);
    assertType('bool', $result->get($urgency)->isConfident(0.8));
    assertType('float', $result->get($urgency)->probabilityOf(Urgency::High));
    assertType(Distribution::class.'<'.Urgency::class.'>', $result->get($urgency)->distribution);
    assertType('list<'.Urgency::class.'>', $result->get($urgency)->distribution->cases());
    assertType('list<array{case: '.Urgency::class.', probability: float}>', $result->get($urgency)->distribution->entries());
    assertType('non-empty-list<array{case: '.Urgency::class.', value: int, description: string|null}>', $urgency->levels());

    // The enum comes back as itself: its cases and backing value need no cast.
    assertType('0|1|2|3', $result->get($urgency)->value->value);
    assertType('bool', $result->get($urgency)->value === Urgency::Critical);
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
