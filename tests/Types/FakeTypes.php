<?php

declare(strict_types=1);

/*
 * Type-inference tests for the testing fake. Nothing here is executed: PHPStan analyses
 * this file and fails if an assertType() expectation does not match the inferred type.
 */

namespace EarthlingOllie\SystemOneClassification\Tests\Types;

use EarthlingOllie\SystemOneClassification\Client\Decider;
use EarthlingOllie\SystemOneClassification\Domain\Context;
use EarthlingOllie\SystemOneClassification\Domain\Decision;
use EarthlingOllie\SystemOneClassification\Domain\Question;
use EarthlingOllie\SystemOneClassification\Laravel\ConnectionResolver;
use EarthlingOllie\SystemOneClassification\Laravel\Facades\SystemOne;
use EarthlingOllie\SystemOneClassification\Laravel\Testing\FakeAnswer;
use EarthlingOllie\SystemOneClassification\Laravel\Testing\FakeConnection;
use EarthlingOllie\SystemOneClassification\Laravel\Testing\SystemOneFake;
use EarthlingOllie\SystemOneClassification\Result\Answer\ChoiceAnswer;
use EarthlingOllie\SystemOneClassification\Result\Answer\ScoreAnswer;
use EarthlingOllie\SystemOneClassification\Result\Answer\YesNoAnswer;
use EarthlingOllie\SystemOneClassification\Result\Result;
use EarthlingOllie\SystemOneClassification\Tests\Fixtures\Enums\Department;
use EarthlingOllie\SystemOneClassification\Tests\Fixtures\Enums\Urgency;

use function PHPStan\Testing\assertType;

function fakeTypes(ConnectionResolver $systemOne): void
{
    $churn = Question::yesNo('churn', 'Does the customer threaten to cancel?');
    $department = Question::choice('department', Department::class, 'Which department should handle this?');
    $urgency = Question::score('urgency', Urgency::class, 'How urgent is this?');

    $yesNo = FakeAnswer::yesNo($churn, 0.91);
    $choice = FakeAnswer::choice($department, Department::Billing, confidence: 0.9);
    $score = FakeAnswer::score($urgency, Urgency::High, expected: 1.7);

    // The scripted answers are the real answer types, typed by the question's enum.
    assertType(FakeAnswer::class.'<'.YesNoAnswer::class.'>', $yesNo);
    assertType(YesNoAnswer::class, $yesNo->answerFor('laya'));
    assertType(ChoiceAnswer::class.'<'.Department::class.'>', $choice->answerFor('laya'));
    assertType(Department::class, $choice->answerFor('laya')->value);
    assertType(ScoreAnswer::class.'<'.Urgency::class.'>', $score->answerFor('laya'));
    // abstained() keeps the script's answer type, enum included.
    assertType(FakeAnswer::class.'<'.ChoiceAnswer::class.'<'.Department::class.'>>', FakeAnswer::abstained($choice));
    assertType(Department::class, FakeAnswer::abstained($choice)->answerFor('laya', 0.8)->value);

    $fake = SystemOne::fake([$yesNo, $choice, $score]);

    assertType(SystemOneFake::class, $fake);
    assertType(SystemOneFake::class, $fake->strict());
    assertType(FakeConnection::class, $fake->connection('jev'));

    // Code written against the interface runs on the real manager and the fake alike.
    $decision = Decision::about(Context::text('x'))->ask($department);

    assertType(Decider::class, $systemOne->connection('jev'));
    assertType(Result::class, $systemOne->decide($decision));
    assertType(Department::class, $systemOne->decide($decision)->get($department)->value);
    assertType(Decider::class, SystemOne::connection('jev'));
}
