<?php

declare(strict_types=1);

use EarthlingOllie\SystemOneClassification\Domain\Context;
use EarthlingOllie\SystemOneClassification\Domain\Decision;
use EarthlingOllie\SystemOneClassification\Domain\Question;
use EarthlingOllie\SystemOneClassification\Laravel\Facades\SystemOne;
use EarthlingOllie\SystemOneClassification\Tests\Fixtures\Enums\Department;
use EarthlingOllie\SystemOneClassification\Tests\Fixtures\Enums\Severity;
use EarthlingOllie\SystemOneClassification\Tests\Fixtures\Enums\Urgency;
use EarthlingOllie\SystemOneClassification\Tests\TestCase;

/*
 * Score questions, end to end against the Laya container at SYSTEM_ONE_URL. Only the shape
 * is asserted: the CI and sandbox checkpoints may carry random weights.
 */

uses(TestCase::class);

it('asks a real score question and gets a level of the enum, the expected level and a full distribution', function (): void {
    $urgency = Question::score('urgency', Urgency::class, 'How urgent is this?');
    $department = Question::choice('department', Department::class, 'Which department should handle this?');
    $decision = Decision::about(Context::text(
        'Hi, we were billed twice for March. Please refund the duplicate charge today or we will cancel our plan.',
    ))->ask($department, $urgency);

    $result = SystemOne::decide($decision);
    $answer = $result->get($urgency);

    expect($answer->value)->toBeInstanceOf(Urgency::class)
        ->and($answer->expected)->toBeGreaterThanOrEqual(0.0)->toBeLessThanOrEqual(3.0)
        ->and($answer->answerConfidence)->toBeGreaterThanOrEqual(0.0)->toBeLessThanOrEqual(1.0)
        ->and($answer->confidence->profile)->toBe('laya')
        ->and($answer->distribution->cases())->toBe(Urgency::cases())
        ->and($answer->isConfident(0.0))->toBeTrue()
        ->and($result->get($department)->value)->toBeInstanceOf(Department::class);
});

it('scores on levels declared out of order and backed by gapped values', function (): void {
    $severity = Question::score('severity', Severity::class, 'How severe is this problem?');

    $answer = SystemOne::decide(Decision::about(Context::text('The site is down for every customer.'))->ask($severity))->get($severity);

    expect($answer->value)->toBeInstanceOf(Severity::class)
        ->and($answer->expected)->toBeGreaterThanOrEqual(1.0)->toBeLessThanOrEqual(10.0)
        ->and($answer->distribution->cases())->toBe([Severity::Minor, Severity::Major, Severity::Critical]);
});
