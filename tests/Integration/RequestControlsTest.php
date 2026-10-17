<?php

declare(strict_types=1);

use EarthlingOllie\SystemOneClassification\Domain\Context;
use EarthlingOllie\SystemOneClassification\Domain\Decision;
use EarthlingOllie\SystemOneClassification\Domain\Question;
use EarthlingOllie\SystemOneClassification\Laravel\Facades\SystemOne;
use EarthlingOllie\SystemOneClassification\Result\Abstention;
use EarthlingOllie\SystemOneClassification\Tests\TestCase;

/*
 * Request controls, abstention and metadata against the Laya container at SYSTEM_ONE_URL.
 */

uses(TestCase::class);

it('abstains below the minimum confidence and still gives the best guess', function (): void {
    $churn = Question::yesNo('churn', 'Does the customer threaten to cancel or leave?');
    // The same ambiguous state and threshold bin/laya-record-fixtures uses for below_min_confidence.
    $decision = Decision::about(Context::text('Hello, I have a question.'))->ask($churn)->withMinConfidence(0.99);

    $answer = SystemOne::decide($decision)->get($churn);

    expect($answer->abstained)->toBeTrue()
        ->and($answer->abstention)->toBe(Abstention::Abstained)
        ->and($answer->abstentionThreshold)->toBe(0.99)
        ->and($answer->probability)->toBeGreaterThanOrEqual(0.0)->toBeLessThanOrEqual(1.0);
});

it('passes every answer at a minimum confidence of zero', function (): void {
    $churn = Question::yesNo('churn', 'Does the customer threaten to cancel or leave?');
    $decision = Decision::about(Context::text('We will cancel our plan.'))->ask($churn)->withMinConfidence(0.0);

    $answer = SystemOne::decide($decision)->get($churn);

    expect($answer->abstained)->toBeFalse()
        ->and($answer->abstention)->toBe(Abstention::Passed)
        ->and($answer->abstentionThreshold)->toBe(0.0);
});

it('runs no gate when no minimum confidence is set', function (): void {
    $churn = Question::yesNo('churn', 'Does the customer threaten to cancel or leave?');

    $answer = SystemOne::decide(Decision::about(Context::text('We will cancel our plan.'))->ask($churn))->get($churn);

    expect($answer->abstention)->toBe(Abstention::NotRequested)
        ->and($answer->abstained)->toBeFalse()
        ->and($answer->abstentionThreshold)->toBeNull();
});

it('reports the model, routed checkpoint, routing reason, usage and latency', function (): void {
    $churn = Question::yesNo('churn', 'Does the customer threaten to cancel or leave?');
    $decision = Decision::about(Context::text('We will cancel our plan.'))->ask($churn)->withLanguage('en');

    $meta = SystemOne::decide($decision)->meta;

    expect($meta->model)->toBeString()->not->toBe('')
        ->and($meta->checkpoint)->toBe('english')
        ->and($meta->routingReason)->toBe("explicit lang='en'")
        ->and($meta->inputTokens)->toBeGreaterThan(0)
        ->and($meta->outputTokens)->toBe(0)
        ->and($meta->latencyMs)->toBeGreaterThan(0.0);
});

it('sends structured fields and every control, which the server accepts', function (): void {
    $churn = Question::yesNo('churn', 'Does the customer threaten to cancel or leave?');
    $decision = Decision::about(Context::fields(['subject' => 'Charged twice', 'body' => 'We will cancel our plan.', 'seats' => 3]))
        ->ask($churn)
        ->withMinConfidence(0.5)
        ->withLanguage('en')
        ->withLanguageHint('en')
        ->withMaxLength(64)
        ->withHeadMaxLength(32);

    $result = SystemOne::decide($decision);

    expect($result->get($churn)->abstention)->not->toBe(Abstention::NotRequested)
        ->and($result->get($churn)->abstentionThreshold)->toBe(0.5)
        ->and($result->meta->inputTokens)->toBeGreaterThan(0)->toBeLessThanOrEqual(64);
});
