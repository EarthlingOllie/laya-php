<?php

declare(strict_types=1);

use EarthlingOllie\SystemOneClassification\Client\DecisionClient;
use EarthlingOllie\SystemOneClassification\Domain\Context;
use EarthlingOllie\SystemOneClassification\Domain\Decision;
use EarthlingOllie\SystemOneClassification\Domain\Question;
use EarthlingOllie\SystemOneClassification\Exceptions\HostUnavailable;
use EarthlingOllie\SystemOneClassification\Exceptions\InvalidDecision;
use EarthlingOllie\SystemOneClassification\Exceptions\SystemOneException;
use EarthlingOllie\SystemOneClassification\Protocol\SystemOneCodec;
use EarthlingOllie\SystemOneClassification\Tests\Support\ArrayResponseCache;
use EarthlingOllie\SystemOneClassification\Tests\Support\RecordingObserver;
use EarthlingOllie\SystemOneClassification\Tests\Support\ScriptedTransport;
use EarthlingOllie\SystemOneClassification\Transport\Response;

function observedDecision(): Decision
{
    return Decision::about(Context::text('We will cancel.'))->ask(Question::yesNo('churn', 'Cancel?'));
}

it('tells the observer about each decision made, with its result, as not cached', function (): void {
    $observer = new RecordingObserver;
    $decision = observedDecision();

    $result = (new DecisionClient(new ScriptedTransport([new Response(200, '{"answers":{"churn":{"type":"noul","noul":0.4}}}')]), new SystemOneCodec, observer: $observer))
        ->decide($decision);

    expect($observer->calls->getArrayCopy())->toBe([['decided', $decision, $result, false]]);
});

it('tells the observer a decision came from the cache', function (): void {
    $answer = new Response(200, '{"answers":{"churn":{"type":"noul","noul":0.4}},"routing":{"model":"english"}}');
    $health = new Response(200, '{"revisions":{"english":"a"}}');
    $observer = new RecordingObserver;
    $client = new DecisionClient(new ScriptedTransport([$answer], [$health, $health]), new SystemOneCodec, cache: new ArrayResponseCache, observer: $observer);

    $client->decide(observedDecision());
    $client->decide(observedDecision());

    expect(array_map(fn (array $call): mixed => $call[3] ?? null, $observer->calls->getArrayCopy()))->toBe([false, true]);
});

it('tells the observer a decision sent because the cache was skipped was not cached', function (): void {
    $observer = new RecordingObserver;
    $transport = new ScriptedTransport([new Response(200, '{"answers":{"churn":{"type":"noul","noul":0.4}}}')], [new Response(200, '{"revisions":{"english":null}}')]);

    (new DecisionClient($transport, new SystemOneCodec, cache: new ArrayResponseCache, observer: $observer))->decide(observedDecision());

    expect($observer->calls[0][3] ?? null)->toBeFalse();
});

it('tells the observer about a failed decision and rethrows the same exception', function (ScriptedTransport $transport, Decision $decision, string $type): void {
    $observer = new RecordingObserver;

    try {
        (new DecisionClient($transport, new SystemOneCodec, observer: $observer))->decide($decision);
        $this->fail('Expected the decision to fail.');
    } catch (SystemOneException $exception) {
        expect($exception)->toBeInstanceOf($type)
            ->and($observer->calls->getArrayCopy())->toBe([['failed', $decision, $exception]]);
    }
})->with([
    'refused before sending' => [new ScriptedTransport, Decision::about(Context::text('x')), InvalidDecision::class],
    'a server error' => [new ScriptedTransport([new Response(503, '{}')]), observedDecision(), HostUnavailable::class],
]);
