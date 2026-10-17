<?php

declare(strict_types=1);

use EarthlingOllie\SystemOneClassification\Domain\Context;
use EarthlingOllie\SystemOneClassification\Domain\Decision;
use EarthlingOllie\SystemOneClassification\Domain\Question;
use EarthlingOllie\SystemOneClassification\Exceptions\RejectedByModel;
use EarthlingOllie\SystemOneClassification\Protocol\SystemOneCodec;
use EarthlingOllie\SystemOneClassification\Tests\Support\LayaFixtures;

/*
 * The codec against the `noul` scenario recorded from a real laya-serve (see
 * LayaFixtures for which recording is in use).
 */

/** The decision bin/laya-record-fixtures sends for the `noul` scenario (bin/lib/laya_payloads.py). */
function recordedNoulDecision(): array
{
    $churn = Question::yesNo('churn', 'Does the customer threaten to cancel or leave?');
    $ticket = 'Hi, we were billed twice for March. Please refund the duplicate charge today or we will cancel our plan.';

    return [Decision::about(Context::text($ticket))->ask($churn), $churn];
}

it('encodes a request that matches the recorded noul request', function (): void {
    [$decision] = recordedNoulDecision();

    $codec = new SystemOneCodec;

    expect($codec->encode($decision))->toBe(LayaFixtures::requestBody('noul'))
        ->and(SystemOneCodec::PATH)->toBe(LayaFixtures::requestPath('noul'));
});

it('decodes the recorded noul response into a yes/no answer', function (): void {
    [$decision, $churn] = recordedNoulDecision();
    $recorded = LayaFixtures::responseBody('noul');
    assert(is_array($recorded['answers']) && is_array($recorded['answers']['churn']));

    $answer = (new SystemOneCodec)->decode($decision, LayaFixtures::response('noul'))->get($churn);

    expect($answer->probability)->toBe((float) $recorded['answers']['churn']['noul'])
        ->and($answer->probability)->toBeGreaterThanOrEqual(0.0)->toBeLessThanOrEqual(1.0);
});

it('reads the yes/no answer out of a response that also answers other questions', function (): void {
    $churn = Question::yesNo('churn', 'Does the customer threaten to cancel or leave?');
    $decision = Decision::about(Context::text('Charged twice'))->ask($churn);
    $recorded = LayaFixtures::responseBody('multiple_questions');
    assert(is_array($recorded['answers']) && is_array($recorded['answers']['churn']));

    $answer = (new SystemOneCodec)->decode($decision, LayaFixtures::response('multiple_questions'))->get($churn);

    expect($answer->probability)->toBe((float) $recorded['answers']['churn']['noul']);
});

it('raises RejectedByModel with the server detail for each recorded 4xx', function (string $scenario, int $status): void {
    [$decision] = recordedNoulDecision();
    $detail = LayaFixtures::responseBody($scenario)['detail'];
    assert(is_string($detail));

    try {
        (new SystemOneCodec)->decode($decision, LayaFixtures::response($scenario));
        $this->fail('Expected RejectedByModel.');
    } catch (RejectedByModel $rejected) {
        expect($rejected->status)->toBe($status)
            ->and($rejected->detail)->toBe($detail)
            ->and($rejected->getMessage())->toBe("The model server rejected the decision (HTTP {$status}): {$detail}");
    }
})->with([
    ['unauthorized_bad_key', 401],
    ['unauthorized_missing_key', 401],
    ['missing_state', 400],
    ['too_many_options', 413],
    ['invalid_request', 422],
    ['invalid_min_confidence', 422],
    ['unknown_model', 422],
]);

it('encodes structured fields as the recorded structured state', function (): void {
    $recorded = LayaFixtures::requestBody('multiple_questions');
    assert(is_array($recorded['state']) && is_array($recorded['questions']));
    $decision = Decision::about(Context::fields($recorded['state']))
        ->ask(Question::yesNo('churn', 'Does the customer threaten to cancel or leave?'));

    $body = (new SystemOneCodec)->encode($decision);

    expect($recorded['state'])->toBe(['subject' => 'Charged twice', 'body' => 'Hi, we were billed twice for March. Please refund the duplicate charge today or we will cancel our plan.'])
        ->and(json_encode($body['state'], JSON_THROW_ON_ERROR))->toBe(json_encode($recorded['state'], JSON_THROW_ON_ERROR))
        ->and($body['questions'])->toBe(['churn' => $recorded['questions']['churn']]);
});

it('reads the recorded model, routing and usage into the result meta', function (): void {
    [$decision] = recordedNoulDecision();
    $recorded = LayaFixtures::responseBody('noul');
    assert(is_array($recorded['routing']) && is_array($recorded['usage']));

    $meta = (new SystemOneCodec)->decode($decision, LayaFixtures::response('noul'), 12.5)->meta;

    expect($meta->model)->toBe($recorded['model'])
        ->and($meta->checkpoint)->toBe($recorded['routing']['model'])
        ->and($meta->checkpoint)->toBe('english')
        ->and($meta->routingReason)->toBe($recorded['routing']['reason'])
        ->and($meta->inputTokens)->toBe($recorded['usage']['input_tokens'])
        ->and($meta->outputTokens)->toBe($recorded['usage']['output_tokens'])
        ->and($meta->latencyMs)->toBe(12.5);
});

it('reads from fixtures marked as shape-only until real weights are recorded', function (): void {
    // A reminder that fails once LayaFixtures::DIRECTORY points at real fixtures:
    // delete this test then (see tests/Fixtures/laya/provisional/README.md).
    expect(LayaFixtures::isProvisional())->toBeTrue()
        ->and(LayaFixtures::manifest()['laya_version'])->toBe('0.4.8');
});
