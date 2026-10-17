<?php

declare(strict_types=1);

use EarthlingOllie\SystemOneClassification\Domain\Context;
use EarthlingOllie\SystemOneClassification\Domain\Decision;
use EarthlingOllie\SystemOneClassification\Domain\Question;
use EarthlingOllie\SystemOneClassification\Laravel\Facades\SystemOne;
use EarthlingOllie\SystemOneClassification\Model\LayaProfile;
use EarthlingOllie\SystemOneClassification\Tests\Support\OptionsEnum;
use EarthlingOllie\SystemOneClassification\Tests\TestCase;

/*
 * The Laya profile's limits against the Laya container at SYSTEM_ONE_URL: what the profile
 * lets through, the real server answers. Only the shape is asserted: the CI and sandbox
 * checkpoints may carry random weights.
 */

uses(TestCase::class);

const PROFILE_TICKET = 'Hi, we were billed twice for March. Please refund the duplicate charge today or we will cancel our plan.';

it('gets an answer from the real server for a choice with as many options as the profile allows', function (): void {
    $label = Question::choice('label', OptionsEnum::withCases(LayaProfile::MAX_CHOICE_OPTIONS), 'Which label fits?');

    $answer = SystemOne::decide(Decision::about(Context::text(PROFILE_TICKET))->ask($label))->get($label);

    expect($answer->distribution->cases())->toHaveCount(LayaProfile::MAX_CHOICE_OPTIONS);
});

it('answers a decision pinned to the english checkpoint, which the container loads', function (): void {
    $churn = Question::yesNo('churn', 'Does the customer threaten to cancel or leave?');

    $answer = SystemOne::decide(Decision::about(Context::text(PROFILE_TICKET))->ask($churn)->withModel('english'))->get($churn);

    expect($answer->probability)->toBeGreaterThanOrEqual(0.0)->toBeLessThanOrEqual(1.0);
});
