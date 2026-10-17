<?php

declare(strict_types=1);

use EarthlingOllie\SystemOneClassification\Exceptions\InvalidProbability;
use EarthlingOllie\SystemOneClassification\Result\Abstention;
use EarthlingOllie\SystemOneClassification\Result\Answer\ChoiceAnswer;
use EarthlingOllie\SystemOneClassification\Result\Answer\ScoreAnswer;
use EarthlingOllie\SystemOneClassification\Result\Confidence;
use EarthlingOllie\SystemOneClassification\Result\Distribution;
use EarthlingOllie\SystemOneClassification\Tests\Fixtures\Enums\Department;
use EarthlingOllie\SystemOneClassification\Tests\Fixtures\Enums\Severity;

/*
 * #7's abstention on the enum answers (#4's choice, #5's score), as YesNoAnswerTest checks it on
 * yes/no answers: every Answer carries it. Each builder passes the gate's fields, when given,
 * as the answer's last two constructor arguments.
 */

dataset('enum answers', [
    'choice' => [fn (): ChoiceAnswer => new ChoiceAnswer(
        Department::Billing,
        0.7,
        new Confidence(0.4, 'laya'),
        new Distribution([
            ['case' => Department::Billing, 'probability' => 0.7],
            ['case' => Department::Technical, 'probability' => 0.2],
            ['case' => Department::Other, 'probability' => 0.1],
        ]),
    )],
    'score' => [fn (): ScoreAnswer => new ScoreAnswer(
        Severity::Major,
        0.5,
        new Confidence(0.2, 'laya'),
        new Distribution([
            ['case' => Severity::Minor, 'probability' => 0.25],
            ['case' => Severity::Major, 'probability' => 0.5],
            ['case' => Severity::Critical, 'probability' => 0.25],
        ]),
    )],
]);

dataset('gated enum answers', [
    'choice' => [fn (Abstention $abstention, ?float $threshold): ChoiceAnswer => new ChoiceAnswer(
        Department::Billing,
        0.7,
        new Confidence(0.4, 'laya'),
        new Distribution([
            ['case' => Department::Billing, 'probability' => 0.7],
            ['case' => Department::Technical, 'probability' => 0.2],
            ['case' => Department::Other, 'probability' => 0.1],
        ]),
        $abstention,
        $threshold,
    )],
    'score' => [fn (Abstention $abstention, ?float $threshold): ScoreAnswer => new ScoreAnswer(
        Severity::Major,
        0.5,
        new Confidence(0.2, 'laya'),
        new Distribution([
            ['case' => Severity::Minor, 'probability' => 0.25],
            ['case' => Severity::Major, 'probability' => 0.5],
            ['case' => Severity::Critical, 'probability' => 0.25],
        ]),
        $abstention,
        $threshold,
    )],
]);

it('has not abstained when no minimum confidence was asked for', function (Closure $build): void {
    $answer = $build();

    expect($answer->abstained)->toBeFalse()
        ->and($answer->abstention)->toBe(Abstention::NotRequested)
        ->and($answer->abstentionThreshold)->toBeNull();
})->with('enum answers');

it('has abstained only when the gate says abstained, keeping the best guess', function (Closure $build, Abstention $abstention, bool $abstained): void {
    $answer = $build($abstention, 0.9);

    expect($answer->abstained)->toBe($abstained)
        ->and($answer->abstention)->toBe($abstention)
        ->and($answer->abstentionThreshold)->toBe(0.9)
        ->and($answer->value)->toBeInstanceOf(BackedEnum::class);
})->with('gated enum answers')->with([
    'abstained' => [Abstention::Abstained, true],
    'passed' => [Abstention::Passed, false],
    'unevaluated' => [Abstention::Unevaluated, false],
]);

it('accepts an abstention threshold at either end of [0, 1]', function (Closure $build, float $threshold): void {
    expect($build(Abstention::Passed, $threshold)->abstentionThreshold)->toBe($threshold);
})->with('gated enum answers')->with([0.0, 1.0]);

it('rejects an abstention threshold outside [0, 1]', function (Closure $build, float $threshold): void {
    expect(fn () => $build(Abstention::Passed, $threshold))
        ->toThrow(InvalidProbability::class, 'The abstention threshold must be between 0 and 1');
})->with('gated enum answers')->with([-0.01, 1.01, NAN]);
