<?php

declare(strict_types=1);

use EarthlingOllie\SystemOneClassification\Domain\Context;
use EarthlingOllie\SystemOneClassification\Domain\Decision;
use EarthlingOllie\SystemOneClassification\Domain\Question;
use EarthlingOllie\SystemOneClassification\Exceptions\HostUnavailable;
use EarthlingOllie\SystemOneClassification\Exceptions\InvalidDecision;
use EarthlingOllie\SystemOneClassification\Laravel\Events\DecisionFailed;
use EarthlingOllie\SystemOneClassification\Laravel\Events\DecisionMade;
use EarthlingOllie\SystemOneClassification\Laravel\Facades\SystemOne;
use EarthlingOllie\SystemOneClassification\Result\Result;
use EarthlingOllie\SystemOneClassification\Result\Usage;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;

/*
 * #12's events and result cache for #8's decideMany(): each context is one decision, as the
 * fake records it, and is keyed exactly as decide() keys it, so both share cache entries.
 */

function manyChurn(): Decision
{
    return Decision::about(Context::text('template'))->ask(Question::yesNo('churn', 'Does the customer threaten to cancel?'));
}

function churnIn(Result $result): float
{
    return $result->get(Question::yesNo('churn', 'Does the customer threaten to cancel?'))->probability;
}

/**
 * Fakes laya-serve: /health reports the revision, and every state, single or batched, gets a
 * new probability (0.001, 0.002, ...) and routes to $checkpoint.
 *
 * @param  array<string, ?string>  $revisions
 * @return ArrayObject<int, mixed> the states sent, in order
 */
function fakeBatchServer(string $checkpoint = 'english', ?Closure $override = null, array $revisions = ['english' => 'rev-a']): ArrayObject
{
    $sent = new ArrayObject;
    Http::fake(function (Request $request) use ($sent, $checkpoint, $override, $revisions) {
        if (str_ends_with($request->url(), '/health')) {
            return Http::response(['status' => 'ok', 'revisions' => $revisions]);
        }

        if ($override !== null && ($response = $override($request)) !== null) {
            return $response;
        }

        $answer = static function (mixed $state) use ($sent, $checkpoint): array {
            $sent->append($state);

            return ['answers' => ['churn' => ['type' => 'noul', 'noul' => count($sent) / 1000]], 'routing' => ['model' => $checkpoint]];
        };

        if (str_ends_with($request->url(), '/batch')) {
            return Http::response(['results' => array_map($answer, (array) $request['states']), 'total_usage' => ['input_tokens' => 1, 'output_tokens' => 0]]);
        }

        return Http::response($answer($request['state']));
    });

    return $sent;
}

beforeEach(function (): void {
    config(['system-one.connections.local' => ['url' => 'http://laya.test', 'model' => 'english']]);
    Exceptions::fake();
});

it('dispatches DecisionMade for each context of a batch, with its own context and result', function (): void {
    config(['system-one.events.include_context' => true]);
    fakeBatchServer();
    Event::fake([DecisionMade::class, DecisionFailed::class]);

    $results = SystemOne::decideMany([Context::text('first'), Context::text('second')], manyChurn());

    $made = Event::dispatched(DecisionMade::class)->map(fn (array $event): array => [$event[0]->context, $event[0]->result, $event[0]->cached, $event[0]->model])->all();
    expect($made)->toBe([['first', $results[0], false, 'english'], ['second', $results[1], false, 'english']]);
    Event::assertNotDispatched(DecisionFailed::class);
});

it('dispatches DecisionFailed for each context of a batch that fails, then throws', function (): void {
    config(['system-one.events.include_context' => true]);
    fakeBatchServer(override: fn () => Http::response(['detail' => 'down'], 503));
    Event::fake([DecisionMade::class, DecisionFailed::class]);

    expect(fn (): mixed => SystemOne::decideMany([Context::text('first'), Context::text('second')], manyChurn()))->toThrow(HostUnavailable::class);

    expect(Event::dispatched(DecisionFailed::class)->map(fn (array $event): array => [$event[0]->context, $event[0]->exception::class])->all())
        ->toBe([['first', HostUnavailable::class], ['second', HostUnavailable::class]]);
    Event::assertNotDispatched(DecisionMade::class);
});

it('dispatches one DecisionFailed for a batch refused before it has a context to ask about', function (array $contexts): void {
    Http::fake();
    Event::fake([DecisionFailed::class]);

    expect(fn (): mixed => SystemOne::decideMany($contexts, manyChurn()))->toThrow(InvalidDecision::class);

    Event::assertDispatchedTimes(DecisionFailed::class, 1);
    Event::assertDispatched(fn (DecisionFailed $event): bool => $event->exception instanceof InvalidDecision && $event->questionKeys === ['churn']);
    Http::assertNothingSent();
})->with([
    'no contexts' => [[]],
    'not a context' => [[Context::text('a'), 'b']],
]);

it('answers each context of a batch from the cache it shares with decide(), sending only the misses', function (): void {
    config(['system-one.cache' => ['enabled' => true, 'store' => 'array', 'ttl' => 60]]);
    $sent = fakeBatchServer();
    Event::fake([DecisionMade::class]);

    $single = SystemOne::decide(manyChurn()->withContext(Context::text('known')));
    $batch = SystemOne::decideMany([Context::text('new'), Context::text('known')], manyChurn());
    $again = SystemOne::decideMany([Context::text('known'), Context::text('new')], manyChurn());

    expect($sent->getArrayCopy())->toBe(['known', 'new'])
        ->and(churnIn($batch[1]))->toBe(churnIn($single))
        ->and(churnIn($again[0]))->toBe(churnIn($single))
        ->and(churnIn($again[1]))->toBe(churnIn($batch[0]))
        ->and(Event::dispatched(DecisionMade::class)->map(fn (array $event): bool => $event[0]->cached)->all())->toBe([false, false, true, true, true]);
    // A batch answered from the cache alone sends no batch, so it counts no tokens.
    expect($again->usage?->inputTokens)->toBe(0);
});

it('stores no batch answer from a checkpoint the cache key holds no revision for', function (): void {
    config(['system-one.cache' => ['enabled' => true, 'store' => 'array', 'ttl' => 60]]);
    $sent = fakeBatchServer(checkpoint: 'multilingual');

    SystemOne::decideMany([Context::text('a')], manyChurn());
    SystemOne::decideMany([Context::text('a')], manyChurn());

    expect($sent->getArrayCopy())->toBe(['a', 'a']);
});

it('sends every context of a batch when the cache is off, without reading the health', function (): void {
    $sent = fakeBatchServer();

    SystemOne::decideMany([Context::text('a')], manyChurn());
    SystemOne::decideMany([Context::text('a')], manyChurn());

    expect($sent->getArrayCopy())->toBe(['a', 'a']);
    Http::assertNotSent(fn (Request $request): bool => str_ends_with($request->url(), '/health'));
});

it('skips the cache for a batch when a loaded checkpoint has no revision, as decide() does', function (): void {
    config(['system-one.cache' => ['enabled' => true, 'store' => 'array', 'ttl' => 60]]);
    $sent = fakeBatchServer(revisions: ['english' => null]);

    SystemOne::decideMany([Context::text('a'), Context::text('b')], manyChurn());
    SystemOne::decideMany([Context::text('a'), Context::text('b')], manyChurn());

    expect($sent->getArrayCopy())->toBe(['a', 'b', 'a', 'b']);
});

it('fails every context of a cached batch when the server cannot be reached', function (): void {
    config(['system-one.cache' => ['enabled' => true, 'store' => 'array', 'ttl' => 60]]);
    Http::fake(['*' => Http::failedConnection('refused')]);
    Event::fake([DecisionFailed::class]);

    expect(fn (): mixed => SystemOne::decideMany([Context::text('a'), Context::text('b')], manyChurn()))->toThrow(HostUnavailable::class);

    Event::assertDispatchedTimes(DecisionFailed::class, 2);
});

it('keeps the API key and, by default, the contexts out of every batch event', function (): void {
    config(['system-one.connections.local.api_key' => 'sk-batch-event-secret']);
    fakeBatchServer(override: fn (Request $request) => $request['states'] === ['b'] ? Http::response(['detail' => 'sk-batch-event-secret'], 503) : null);
    Event::fake([DecisionMade::class, DecisionFailed::class]);

    SystemOne::decideMany([Context::text('a')], manyChurn());

    try {
        SystemOne::decideMany([Context::text('b')], manyChurn());
    } catch (HostUnavailable) {
        // The DecisionFailed event is what this test reads.
    }

    $events = [...Event::dispatched(DecisionMade::class)->all(), ...Event::dispatched(DecisionFailed::class)->all()];

    expect($events)->toHaveCount(2);

    foreach ($events as [$event]) {
        expect(serialize($event).var_export(json_decode((string) json_encode($event), true), true))->not->toContain('sk-batch-event-secret')
            ->and($event->context)->toBeNull();
    }
});

it('stores the answers of every request of a batch that spans several, each under its own context', function (): void {
    config(['system-one.cache' => ['enabled' => true, 'store' => 'array', 'ttl' => 60]]);
    $sent = fakeBatchServer();
    $contexts = array_map(static fn (int $n): Context => Context::text("ticket {$n}"), range(0, 64));

    $first = SystemOne::decideMany($contexts, manyChurn());
    $again = SystemOne::decideMany($contexts, manyChurn());

    expect($sent)->toHaveCount(65)
        ->and(churnIn($again[0]))->toBe(churnIn($first[0]))
        ->and(churnIn($again[64]))->toBe(churnIn($first[64]))
        ->and(churnIn($first[64]))->toBe(0.065);
    Http::assertSentCount(1 + 2 + 1);
});

it('puts a cached answer and a fresh one back in the order of the contexts', function (): void {
    config(['system-one.cache' => ['enabled' => true, 'store' => 'array', 'ttl' => 60]]);
    fakeBatchServer();
    $known = SystemOne::decide(manyChurn()->withContext(Context::text('known')));

    $batch = SystemOne::decideMany([Context::text('known'), Context::text('new'), Context::text('known')], manyChurn());

    expect([churnIn($batch[0]), churnIn($batch[1]), churnIn($batch[2])])->toBe([churnIn($known), 0.002, churnIn($known)]);
});

it('reports no tokens for a batch answered from the cache alone', function (): void {
    config(['system-one.cache' => ['enabled' => true, 'store' => 'array', 'ttl' => 60]]);
    fakeBatchServer();

    SystemOne::decideMany([Context::text('a')], manyChurn());

    expect(SystemOne::decideMany([Context::text('a')], manyChurn())->usage)->toEqual(new Usage(0, 0));
});

// Review finding: a later request of a batch failing must not lose the answers already paid for.
it('keeps the answers of the requests that succeeded when a later request of the batch fails', function (): void {
    config(['system-one.cache' => ['enabled' => true, 'store' => 'array', 'ttl' => 60]]);
    $batches = 0;
    $sent = fakeBatchServer(override: function (Request $request) use (&$batches) {
        return str_ends_with($request->url(), '/batch') && ++$batches === 2 ? Http::response(['detail' => 'down'], 503) : null;
    });
    $contexts = array_map(static fn (int $n): Context => Context::text("ticket {$n}"), range(0, 64));

    expect(fn (): mixed => SystemOne::decideMany($contexts, manyChurn()))->toThrow(HostUnavailable::class);
    $answered = count($sent);
    SystemOne::decideMany($contexts, manyChurn());

    // The first request's 64 answers come from the cache; only the 65th state is sent again.
    expect($answered)->toBe(64)
        ->and(count($sent))->toBe(65)
        ->and($sent[64])->toBe('ticket 64');
});
