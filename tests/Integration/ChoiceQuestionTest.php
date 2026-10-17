<?php

declare(strict_types=1);

use EarthlingOllie\SystemOneClassification\Domain\Context;
use EarthlingOllie\SystemOneClassification\Domain\Decision;
use EarthlingOllie\SystemOneClassification\Domain\Question;
use EarthlingOllie\SystemOneClassification\Laravel\Facades\SystemOne;
use EarthlingOllie\SystemOneClassification\Tests\Fixtures\Enums\Department;
use EarthlingOllie\SystemOneClassification\Tests\Fixtures\Enums\Priority;
use EarthlingOllie\SystemOneClassification\Tests\TestCase;

/*
 * Choice questions, end to end against the Laya container at SYSTEM_ONE_URL. Only the shape
 * is asserted: the CI and sandbox checkpoints may carry random weights.
 */

uses(TestCase::class);

it('asks a real choice question and gets a case of the enum with a full distribution', function (): void {
    $department = Question::choice('department', Department::class, 'Which department should handle this?');
    $churn = Question::yesNo('churn', 'Does the customer threaten to cancel or leave?');
    $decision = Decision::about(Context::text(
        'Hi, we were billed twice for March. Please refund the duplicate charge today or we will cancel our plan.',
    ))->ask($department, $churn);

    $result = SystemOne::decide($decision);
    $answer = $result->get($department);

    expect($answer->value)->toBeInstanceOf(Department::class)
        ->and($answer->answerConfidence)->toBeGreaterThanOrEqual(0.0)->toBeLessThanOrEqual(1.0)
        ->and($answer->confidence->profile)->toBe('laya')
        ->and($answer->distribution->cases())->toBe(Department::cases())
        ->and($answer->isConfident(0.0))->toBeTrue()
        ->and($result->get($churn)->probability)->toBeGreaterThanOrEqual(0.0)->toBeLessThanOrEqual(1.0);
});

it('round-trips int-backed labels, which go out as a JSON object', function (): void {
    $priority = Question::choice('priority', Priority::class, 'How soon does this need an answer?');

    $answer = SystemOne::decide(Decision::about(Context::text('The site is down for every customer.'))->ask($priority))->get($priority);

    expect($answer->value)->toBeInstanceOf(Priority::class)
        ->and($answer->distribution->cases())->toBe(Priority::cases());
});
