<?php

declare(strict_types=1);

use EarthlingOllie\SystemOneClassification\Domain\Context;
use EarthlingOllie\SystemOneClassification\Domain\Decision;
use EarthlingOllie\SystemOneClassification\Domain\Question;
use EarthlingOllie\SystemOneClassification\Laravel\Facades\SystemOne;
use EarthlingOllie\SystemOneClassification\Result\Verdict;
use EarthlingOllie\SystemOneClassification\Tests\TestCase;

/*
 * One yes/no question, end to end: facade -> manager -> client -> codec -> HTTP -> the
 * Laya container at SYSTEM_ONE_URL (key from SYSTEM_ONE_API_KEY when the server needs one).
 */

uses(TestCase::class);

it('asks a real yes/no question and gets a probability between 0 and 1', function (): void {
    $churn = Question::yesNo('churn', 'Does the customer threaten to cancel or leave?');
    $decision = Decision::about(Context::text(
        'Hi, we were billed twice for March. Please refund the duplicate charge today or we will cancel our plan.',
    ))->ask($churn);

    $answer = SystemOne::decide($decision)->get($churn);

    expect($answer->probability)->toBeFloat()
        ->toBeGreaterThanOrEqual(0.0)
        ->toBeLessThanOrEqual(1.0)
        ->and($answer->verdict(yes: 0.8, no: 0.2))->toBeInstanceOf(Verdict::class);
});
