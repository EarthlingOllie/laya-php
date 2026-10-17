<?php

declare(strict_types=1);

use EarthlingOllie\SystemOneClassification\Domain\Context;
use EarthlingOllie\SystemOneClassification\Domain\Decision;
use EarthlingOllie\SystemOneClassification\Domain\Question;
use EarthlingOllie\SystemOneClassification\Exceptions\HostUnavailable;
use EarthlingOllie\SystemOneClassification\Exceptions\InvalidConfiguration;
use EarthlingOllie\SystemOneClassification\Exceptions\InvalidDecision;
use EarthlingOllie\SystemOneClassification\Exceptions\RejectedByModel;
use EarthlingOllie\SystemOneClassification\Laravel\Events\DecisionFailed;
use EarthlingOllie\SystemOneClassification\Laravel\Events\DecisionMade;
use EarthlingOllie\SystemOneClassification\Laravel\Facades\SystemOne;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;

const EVENT_TEXT = 'Jane Doe, 12 Elm Street: we will cancel.';
const EVENT_KEY = 'sk-event-secret';

function eventDecision(): Decision
{
    return Decision::about(Context::text(EVENT_TEXT))->ask(
        Question::yesNo('churn', 'Does the customer threaten to cancel?'),
        Question::yesNo('refund', 'Do they ask for a refund?'),
    );
}

function fakeAnswers(): void
{
    Http::fake([
        '*/health' => Http::response(['revisions' => ['english' => 'rev-a']]),
        '*/v1/systemone' => Http::response([
            'model' => 'laya-rl-agent',
            'answers' => ['churn' => ['type' => 'noul', 'noul' => 0.9], 'refund' => ['type' => 'noul', 'noul' => 0.2]],
            'routing' => ['model' => 'english', 'reason' => 'English Latin text'],
            'usage' => ['input_tokens' => 40, 'output_tokens' => 0],
        ]),
    ]);
}

/**
 * Answers the first request with both answers, then every later one as $then does: a 422 by
 * default. (A second Http::fake() would not replace the first one's stubs.)
 */
function fakeAnswersThen(?Closure $then = null): void
{
    $sent = 0;
    $then ??= fn () => Http::response(['detail' => 'no'], 422);
    Http::fake(function (Request $request) use (&$sent, $then) {
        return ++$sent === 1
            ? Http::response(['answers' => ['churn' => ['type' => 'noul', 'noul' => 0.9], 'refund' => ['type' => 'noul', 'noul' => 0.2]]])
            : $then($request);
    });
}

/** Every value an event carries, nested objects and the exception's message included, as one string. */
function everythingIn(object $event): string
{
    return serialize($event).var_export(json_decode((string) json_encode($event), true), true);
}

beforeEach(function (): void {
    config(['system-one.connections.local' => ['url' => 'http://laya.test', 'api_key' => EVENT_KEY, 'model' => 'english']]);
    Event::fake([DecisionMade::class, DecisionFailed::class]);
});

it('dispatches DecisionMade with the connection, model, question keys, result metadata and latency', function (): void {
    fakeAnswers();

    $result = SystemOne::decide(eventDecision());

    Event::assertDispatchedTimes(DecisionMade::class, 1);
    Event::assertDispatched(fn (DecisionMade $event): bool => $event->connection === 'local'
        && $event->model === 'english'
        && $event->questionKeys === ['churn', 'refund']
        && $event->result === $result
        && $event->meta === $result->meta
        && $event->meta->checkpoint === 'english'
        && $event->meta->inputTokens === 40
        && $event->latencyMs === $result->meta->latencyMs
        && $event->latencyMs !== null
        && $event->cached === false
        && $event->context === null);
    Event::assertNotDispatched(DecisionFailed::class);
});

it('names the connection the decision went through, and no model when the server routes', function (): void {
    config(['system-one.connections.other' => ['url' => 'http://other.test']]);
    fakeAnswers();

    SystemOne::connection('other')->decide(eventDecision());

    Event::assertDispatched(fn (DecisionMade $event): bool => $event->connection === 'other' && $event->model === null);
});

it('dispatches DecisionFailed with the connection and the typed exception, then throws it', function (Closure $fake, string $type): void {
    $fake();

    try {
        SystemOne::decide(eventDecision());
        $this->fail('Expected the decision to fail.');
    } catch (Throwable $thrown) {
        expect($thrown)->toBeInstanceOf($type);
        Event::assertDispatched(fn (DecisionFailed $event): bool => $event->connection === 'local'
            && $event->model === 'english'
            && $event->questionKeys === ['churn', 'refund']
            && $event->exception === $thrown
            && $event->context === null);
    }

    Event::assertNotDispatched(DecisionMade::class);
})->with([
    'unreachable' => [fn () => Http::fake(['*' => Http::failedConnection('Connection refused to '.EVENT_KEY)]), HostUnavailable::class],
    'refused' => [fn () => Http::fake(['*' => Http::response(['detail' => 'bad request'], 422)]), RejectedByModel::class],
]);

it('dispatches DecisionFailed for a decision refused before it is sent', function (): void {
    Http::fake();

    expect(fn () => SystemOne::decide(Decision::about(Context::text(EVENT_TEXT))))->toThrow(InvalidDecision::class);

    Event::assertDispatched(fn (DecisionFailed $event): bool => $event->exception instanceof InvalidDecision && $event->questionKeys === []);
    Http::assertNothingSent();
});

it('keeps the context text and the API key out of every event by default', function (): void {
    fakeAnswersThen(Http::failedConnection('cURL error 7: refused (key '.EVENT_KEY.')'));
    SystemOne::decide(eventDecision());
    expect(fn () => SystemOne::decide(eventDecision()))->toThrow(HostUnavailable::class);

    $events = [...Event::dispatched(DecisionMade::class), ...Event::dispatched(DecisionFailed::class)];

    expect($events)->toHaveCount(2);

    foreach ($events as [$event]) {
        expect($event)->toBeObject()
            ->and(everythingIn($event))->not->toContain('Jane Doe')->not->toContain(EVENT_KEY)->not->toContain('sk-event');
    }
});

it('adds the context to both events when include_context is on', function (): void {
    config(['system-one.events.include_context' => true]);
    fakeAnswersThen();
    SystemOne::decide(eventDecision());
    expect(fn () => SystemOne::decide(eventDecision()))->toThrow(RejectedByModel::class);

    Event::assertDispatched(fn (DecisionMade $event): bool => $event->context === EVENT_TEXT && ! str_contains(everythingIn($event), EVENT_KEY));
    Event::assertDispatched(fn (DecisionFailed $event): bool => $event->context === EVENT_TEXT && ! str_contains(everythingIn($event), EVENT_KEY));
});

it('adds structured fields as the context when include_context is on', function (): void {
    config(['system-one.events.include_context' => true]);
    fakeAnswers();

    SystemOne::decide(Decision::about(Context::fields(['subject' => 'Refund']))->ask(Question::yesNo('churn', 'Cancel?'), Question::yesNo('refund', 'Refund?')));

    Event::assertDispatched(fn (DecisionMade $event): bool => $event->context === ['subject' => 'Refund']);
});

it('dispatches no event when events are turned off', function (): void {
    config(['system-one.events.enabled' => false]);
    fakeAnswersThen();
    SystemOne::decide(eventDecision());
    expect(fn () => SystemOne::decide(eventDecision()))->toThrow(RejectedByModel::class);

    Event::assertNothingDispatched();
});

it('reads the event flags as booleans, as env() and config files write them', function (mixed $enabled, mixed $include, bool $dispatched, bool $withContext): void {
    config(['system-one.events.enabled' => $enabled, 'system-one.events.include_context' => $include]);
    fakeAnswers();

    SystemOne::decide(eventDecision());

    expect(Event::dispatched(DecisionMade::class))->toHaveCount($dispatched ? 1 : 0);
    if ($dispatched) {
        Event::assertDispatched(fn (DecisionMade $event): bool => ($event->context !== null) === $withContext);
    }
})->with([
    'missing keys keep the defaults' => [null, null, true, false],
    'empty values keep the defaults, as SYSTEM_ONE_EVENTS= writes them' => ['', '', true, false],
    'strings' => ['true', '1', true, true],
    'off as a string' => ['0', 'false', false, false],
    'on, without context' => [true, 'off', true, false],
]);

it('rejects an event flag that is not a boolean', function (string $key): void {
    config(["system-one.events.{$key}" => 'maybe']);

    expect(fn () => SystemOne::connection())
        ->toThrow(InvalidConfiguration::class, "config/system-one.php has a 'events.{$key}' that is not a boolean.");
})->with(['enabled', 'include_context']);
