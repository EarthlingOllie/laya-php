<?php

declare(strict_types=1);

use EarthlingOllie\SystemOneClassification\Domain\Context;
use EarthlingOllie\SystemOneClassification\Domain\Decision;
use EarthlingOllie\SystemOneClassification\Domain\Question;
use EarthlingOllie\SystemOneClassification\Laravel\Facades\SystemOne;
use EarthlingOllie\SystemOneClassification\Result\ResultSet;
use EarthlingOllie\SystemOneClassification\Tests\TestCase;

/*
 * decideMany() end to end against the Laya container at SYSTEM_ONE_URL: one batch request
 * for a few contexts, and 65 contexts, which the server only accepts split into two requests.
 */

uses(TestCase::class);

it('asks one question about several contexts through the batch endpoint', function (): void {
    $churn = Question::yesNo('churn', 'Does the customer threaten to cancel or leave?');
    $decision = Decision::about(Context::text('template'))->ask($churn);

    $results = SystemOne::decideMany([
        Context::text('Refund the duplicate charge today or we will cancel our plan.'),
        Context::text('The app crashes every time I open the settings page.'),
        Context::text('Just wanted to say the new dashboard looks great.'),
    ], $decision);

    expect($results)->toBeInstanceOf(ResultSet::class)->toHaveCount(3)
        ->and($results->usage?->inputTokens)->toBeGreaterThan(0);

    foreach ($results as $result) {
        expect($result->get($churn)->probability)->toBeGreaterThanOrEqual(0.0)->toBeLessThanOrEqual(1.0);
    }
});

it('splits 65 contexts past the server cap and returns 65 results', function (): void {
    $churn = Question::yesNo('churn', 'Does the customer threaten to cancel or leave?');
    $contexts = array_map(static fn (int $n): Context => Context::text("Ticket {$n}: please cancel my plan."), range(1, 65));

    $results = SystemOne::decideMany($contexts, Decision::about(Context::text('template'))->ask($churn));

    expect($results)->toHaveCount(65)
        ->and($results[64]->get($churn)->probability)->toBeGreaterThanOrEqual(0.0)->toBeLessThanOrEqual(1.0);
});
