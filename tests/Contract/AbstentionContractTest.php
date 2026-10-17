<?php

declare(strict_types=1);

use EarthlingOllie\SystemOneClassification\Domain\Context;
use EarthlingOllie\SystemOneClassification\Domain\Decision;
use EarthlingOllie\SystemOneClassification\Domain\Question;
use EarthlingOllie\SystemOneClassification\Protocol\SystemOneCodec;
use EarthlingOllie\SystemOneClassification\Result\Abstention;
use EarthlingOllie\SystemOneClassification\Tests\Support\LayaFixtures;

/*
 * The request controls and the abstention gate against the `below_min_confidence` scenario
 * recorded from a real laya-serve (min_confidence 0.99 on an ambiguous state).
 *
 * The recording also asks a choice and a score question, which this codec does not build yet,
 * so the request is compared field by field outside `questions`, and only the churn answer is
 * decoded.
 */

/** The yes/no part of the decision bin/laya-record-fixtures sends for `below_min_confidence`. */
function recordedBelowMinConfidenceDecision(): array
{
    $churn = Question::yesNo('churn', 'Does the customer threaten to cancel or leave?');

    return [Decision::about(Context::text('Hello, I have a question.'))->ask($churn)->withMinConfidence(0.99), $churn];
}

it('encodes the state and min_confidence as recorded, in the recorded order', function (): void {
    [$decision] = recordedBelowMinConfidenceDecision();
    $recorded = LayaFixtures::requestBody('below_min_confidence');
    assert(is_array($recorded['questions']));

    $body = (new SystemOneCodec)->encode($decision);

    expect(array_keys($body))->toBe(array_keys($recorded))
        ->and($body['state'])->toBe($recorded['state'])
        ->and($body['min_confidence'])->toBe($recorded['min_confidence'])
        ->and($body['questions'])->toBe(['churn' => $recorded['questions']['churn']]);
});

it('decodes the recorded abstained answer, keeping the best guess', function (): void {
    [$decision, $churn] = recordedBelowMinConfidenceDecision();
    $recorded = LayaFixtures::responseBody('below_min_confidence');
    assert(is_array($recorded['answers']) && is_array($recorded['answers']['churn']));
    $wire = $recorded['answers']['churn'];

    $answer = (new SystemOneCodec)->decode($decision, LayaFixtures::response('below_min_confidence'))->get($churn);

    expect($wire['abstention'])->toBe('abstained')
        ->and($wire['low_confidence'])->toBeTrue()
        ->and($answer->abstained)->toBeTrue()
        ->and($answer->abstention)->toBe(Abstention::Abstained)
        ->and($answer->abstentionThreshold)->toBe($wire['abstention_threshold'])
        ->and($answer->abstentionThreshold)->toBe(0.99)
        ->and($answer->probability)->toBe((float) $wire['noul']);
});

it('reads an answer recorded without min_confidence as not gated', function (): void {
    $churn = Question::yesNo('churn', 'Does the customer threaten to cancel or leave?');
    $decision = Decision::about(Context::text('x'))->ask($churn);

    $answer = (new SystemOneCodec)->decode($decision, LayaFixtures::response('noul'))->get($churn);

    expect(LayaFixtures::responseBody('noul')['answers'])->not->toHaveKey('churn.abstention')
        ->and($answer->abstained)->toBeFalse()
        ->and($answer->abstention)->toBe(Abstention::NotRequested)
        ->and($answer->abstentionThreshold)->toBeNull();
});
