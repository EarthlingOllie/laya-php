<?php

declare(strict_types=1);

use EarthlingOllie\SystemOneClassification\Client\DecisionClient;
use EarthlingOllie\SystemOneClassification\Domain\Context;
use EarthlingOllie\SystemOneClassification\Domain\Decision;
use EarthlingOllie\SystemOneClassification\Domain\Question;
use EarthlingOllie\SystemOneClassification\Exceptions\HostUnavailable;
use EarthlingOllie\SystemOneClassification\Exceptions\InvalidDecision;
use EarthlingOllie\SystemOneClassification\Exceptions\MalformedResponse;
use EarthlingOllie\SystemOneClassification\Exceptions\RejectedByModel;
use EarthlingOllie\SystemOneClassification\Protocol\SystemOneCodec;
use EarthlingOllie\SystemOneClassification\Tests\Support\ArrayResponseCache;
use EarthlingOllie\SystemOneClassification\Tests\Support\ScriptedTransport;
use EarthlingOllie\SystemOneClassification\Transport\Response;
use EarthlingOllie\SystemOneClassification\Transport\Transport;

/*
 * The client's side of result caching: what the key covers, when the cache is read and
 * written, and when it is skipped. The Laravel store behind ResponseCache is tested in Feature.
 */

function cachedChurnDecision(string $text = 'We will cancel.'): Decision
{
    return Decision::about(Context::text($text))->ask(Question::yesNo('churn', 'Does the customer threaten to cancel?'));
}

/** @param array<string, ?string> $revisions */
function healthWith(array $revisions = ['english' => 'rev-a']): Response
{
    return new Response(200, json_encode(['status' => 'ok', 'revisions' => (object) $revisions], JSON_THROW_ON_ERROR));
}

function churnAnswer(float $noul = 0.91): Response
{
    return new Response(200, '{"model":"laya-rl-agent","answers":{"churn":{"type":"noul","noul":'.$noul.'}},"routing":{"model":"english"}}');
}

it('does not read the server health when no cache is given', function (): void {
    $transport = new ScriptedTransport([churnAnswer()]);

    (new DecisionClient($transport, new SystemOneCodec))->decide(cachedChurnDecision());

    expect($transport->calls())->toBe(['POST /v1/systemone']);
});

it('reads the revisions, misses, sends the decision and stores the response', function (): void {
    $transport = new ScriptedTransport([churnAnswer()], [healthWith()]);
    $cache = new ArrayResponseCache;

    $result = (new DecisionClient($transport, new SystemOneCodec, cache: $cache))->decide(cachedChurnDecision());

    expect($result->get(Question::yesNo('churn', 'Does the customer threaten to cancel?'))->probability)->toBe(0.91)
        ->and($transport->calls())->toBe(['GET /health', 'POST /v1/systemone'])
        ->and($cache->keys())->toHaveCount(1)
        ->and($cache->entries[$cache->keys()[0]] ?? null)->toEqual(churnAnswer())
        ->and($cache->log->getArrayCopy())->toBe(['get '.$cache->keys()[0], 'put '.$cache->keys()[0]])
        ->and($cache->skipped->count())->toBe(0);
});

it('answers an identical decision from the cache without sending it, with no latency', function (): void {
    $transport = new ScriptedTransport([churnAnswer()], [healthWith(), healthWith()]);
    $client = new DecisionClient($transport, new SystemOneCodec, cache: new ArrayResponseCache);
    $first = $client->decide(cachedChurnDecision());

    $second = $client->decide(cachedChurnDecision());

    $churn = Question::yesNo('churn', 'Does the customer threaten to cancel?');
    expect($second->get($churn)->probability)->toBe($first->get($churn)->probability)
        ->and($second->meta->model)->toBe('laya-rl-agent')
        ->and($second->meta->checkpoint)->toBe('english')
        ->and($second->meta->latencyMs)->toBeNull()
        ->and($first->meta->latencyMs)->not->toBeNull()
        ->and($transport->calls())->toBe(['GET /health', 'POST /v1/systemone', 'GET /health']);
});

it('keys on a hash of the checkpoint revisions and the whole request body, model included', function (): void {
    $cache = new ArrayResponseCache;
    $decision = cachedChurnDecision()->withMinConfidence(0.5);

    (new DecisionClient(new ScriptedTransport([churnAnswer()], [healthWith(['english' => 'rev-a'])]), new SystemOneCodec, model: 'english', cache: $cache))
        ->decide($decision);

    $request = (new SystemOneCodec)->encode($decision, 'english');
    expect($request)->toHaveKey('model', 'english')
        ->and($cache->keys())->toBe([hash('sha256', serialize(['revisions' => ['english' => 'rev-a'], 'request' => $request]))]);
});

it('stores a decision under a new key when anything it sends or the revision differs', function (?string $model, Decision $decision, string $revision): void {
    $cache = new ArrayResponseCache;
    (new DecisionClient(new ScriptedTransport([churnAnswer()], [healthWith()]), new SystemOneCodec, cache: $cache))
        ->decide(cachedChurnDecision());
    $transport = new ScriptedTransport([churnAnswer(0.2)], [healthWith(['english' => $revision])]);

    $result = (new DecisionClient($transport, new SystemOneCodec, model: $model, cache: $cache))->decide($decision);

    expect($transport->calls())->toBe(['GET /health', 'POST /v1/systemone'])
        ->and($cache->keys())->toHaveCount(2)
        ->and($result->meta->latencyMs)->not->toBeNull();
})->with([
    'context' => [null, cachedChurnDecision('We are happy.'), 'rev-a'],
    'control' => [null, cachedChurnDecision()->withLanguage('en'), 'rev-a'],
    'model' => ['english', cachedChurnDecision(), 'rev-a'],
    'revision' => [null, cachedChurnDecision(), 'rev-b'],
    'question' => [null, Decision::about(Context::text('We will cancel.'))->ask(Question::yesNo('churn', 'Do they cancel?')), 'rev-a'],
]);

it('refuses a context that is not valid UTF-8 before reading the health or the cache', function (): void {
    // #6's codec refuses it up front, so it never reaches the cache key or the transport.
    $transport = new ScriptedTransport([], []);
    $cache = new ArrayResponseCache;

    expect(fn () => (new DecisionClient($transport, new SystemOneCodec, cache: $cache))->decide(cachedChurnDecision("Latin-1 caf\xE9")))
        ->toThrow(InvalidDecision::class, 'The context text is not valid UTF-8, which JSON requires.')
        ->and($transport->calls())->toBe([])
        ->and($cache->keys())->toBe([]);
});

it('stores nothing answered by a checkpoint whose revision it did not key on', function (string $answer): void {
    // A server that loads checkpoints lazily reports only english, then routes to multilingual
    // and loads it: the key holds no multilingual revision, so the answer must not be kept.
    $answered = new Response(200, $answer);
    $transport = new ScriptedTransport([$answered, $answered], [healthWith(['english' => 'a']), healthWith(['english' => 'a'])]);
    $cache = new ArrayResponseCache;
    $client = new DecisionClient($transport, new SystemOneCodec, cache: $cache);

    $client->decide(cachedChurnDecision());
    $client->decide(cachedChurnDecision());

    expect($transport->calls())->toBe(['GET /health', 'POST /v1/systemone', 'GET /health', 'POST /v1/systemone'])
        ->and($cache->entries->count())->toBe(0);
})->with([
    'another checkpoint' => ['{"answers":{"churn":{"type":"noul","noul":0.5}},"routing":{"model":"multilingual"}}'],
    'no routing reported' => ['{"answers":{"churn":{"type":"noul","noul":0.5}}}'],
]);

it('misses when the server has loaded another checkpoint since', function (): void {
    $cache = new ArrayResponseCache;
    $transport = new ScriptedTransport([churnAnswer(), churnAnswer()], [healthWith(['english' => 'a']), healthWith(['english' => 'a', 'multilingual' => 'b'])]);
    $client = new DecisionClient($transport, new SystemOneCodec, cache: $cache);

    $client->decide(cachedChurnDecision());
    $client->decide(cachedChurnDecision());

    expect($transport->calls())->toBe(['GET /health', 'POST /v1/systemone', 'GET /health', 'POST /v1/systemone'])
        ->and($cache->keys())->toHaveCount(2);
});

it('skips the cache without a word when the server reports no revision to key on', function (array $revisions): void {
    $transport = new ScriptedTransport([churnAnswer()], [healthWith($revisions)]);
    $cache = new ArrayResponseCache;

    (new DecisionClient($transport, new SystemOneCodec, cache: $cache))->decide(cachedChurnDecision());

    expect($transport->calls())->toBe(['GET /health', 'POST /v1/systemone'])
        ->and($cache->log->count())->toBe(0)
        ->and($cache->skipped->count())->toBe(0);
})->with([
    'nothing loaded' => [[]],
    'a checkpoint without a revision' => [['english' => null]],
    'one of two without a revision' => [['english' => 'a', 'multilingual' => null]],
]);

it('reports and skips the cache when the health response is refused or malformed, and still decides', function (Response $health, string $exception): void {
    $transport = new ScriptedTransport([churnAnswer()], [$health]);
    $cache = new ArrayResponseCache;

    $result = (new DecisionClient($transport, new SystemOneCodec, cache: $cache))->decide(cachedChurnDecision());

    expect($result->get(Question::yesNo('churn', 'Does the customer threaten to cancel?'))->probability)->toBe(0.91)
        ->and($transport->calls())->toBe(['GET /health', 'POST /v1/systemone'])
        ->and($cache->log->count())->toBe(0)
        ->and($cache->skipped->count())->toBe(1)
        ->and($cache->skipped[0] ?? null)->toBeInstanceOf($exception);
})->with([
    'no /health' => [new Response(404, '{"detail":"Not Found"}'), RejectedByModel::class],
    'a 5xx' => [new Response(503, '{"detail":"loading"}'), HostUnavailable::class],
    'no revisions' => [new Response(200, '{"status":"ok"}'), MalformedResponse::class],
]);

// Review finding: the /health probe is sent once; when it cannot reach the server the cache is
// skipped, and the decision goes out with the connection's retries (#9), which decide whether
// the host is really down.
it('skips the cache, reporting why, and sends the decision when the health probe cannot reach the server', function (): void {
    $transport = new class implements Transport
    {
        public int $posts = 0;

        public function post(string $path, array $body): Response
        {
            $this->posts++;

            return new Response(200, '{"answers":{"churn":{"type":"noul","noul":0.4}}}');
        }

        public function get(string $path): Response
        {
            throw HostUnavailable::unreachable('http://laya.test', 'Connection refused', 1);
        }
    };
    $cache = new ArrayResponseCache;

    $answer = (new DecisionClient($transport, new SystemOneCodec, cache: $cache))->decide(cachedChurnDecision());

    expect($answer->get(Question::yesNo('churn', 'Does the customer threaten to cancel?'))->probability)->toBe(0.4)
        ->and($transport->posts)->toBe(1)
        ->and($cache->skipped->count())->toBe(1)
        ->and($cache->skipped[0])->toBeInstanceOf(HostUnavailable::class)
        ->and($cache->keys())->toBe([]);
});

it('reports a cached entry it cannot read, then decides and overwrites it', function (): void {
    $cache = new ArrayResponseCache;
    (new DecisionClient(new ScriptedTransport([churnAnswer()], [healthWith()]), new SystemOneCodec, cache: $cache))->decide(cachedChurnDecision());
    $key = $cache->keys()[0];
    $cache->entries[$key] = new Response(200, 'not json');
    $transport = new ScriptedTransport([churnAnswer(0.3)], [healthWith()]);

    $result = (new DecisionClient($transport, new SystemOneCodec, cache: $cache))->decide(cachedChurnDecision());

    expect($result->get(Question::yesNo('churn', 'Does the customer threaten to cancel?'))->probability)->toBe(0.3)
        ->and($transport->calls())->toBe(['GET /health', 'POST /v1/systemone'])
        ->and($cache->skipped->count())->toBe(1)
        ->and($cache->skipped[0] ?? null)->toBeInstanceOf(MalformedResponse::class)
        ->and($cache->entries[$key] ?? null)->toEqual(churnAnswer(0.3));
});

it('stores nothing for a decision the server refuses or answers badly', function (Response $response, string $exception): void {
    $cache = new ArrayResponseCache;

    expect(fn () => (new DecisionClient(new ScriptedTransport([$response], [healthWith()]), new SystemOneCodec, cache: $cache))->decide(cachedChurnDecision()))
        ->toThrow($exception)
        ->and($cache->entries->count())->toBe(0);
})->with([
    [new Response(503, '{"detail":"busy"}'), HostUnavailable::class],
    [new Response(422, '{"detail":"bad"}'), RejectedByModel::class],
    [new Response(200, '{"answers":{}}'), MalformedResponse::class],
]);
