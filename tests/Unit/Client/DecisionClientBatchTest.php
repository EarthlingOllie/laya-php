<?php

declare(strict_types=1);

use EarthlingOllie\SystemOneClassification\Client\DecisionClient;
use EarthlingOllie\SystemOneClassification\Domain\Context;
use EarthlingOllie\SystemOneClassification\Domain\Decision;
use EarthlingOllie\SystemOneClassification\Domain\Question;
use EarthlingOllie\SystemOneClassification\Domain\Question\YesNoQuestion;
use EarthlingOllie\SystemOneClassification\Exceptions\InvalidDecision;
use EarthlingOllie\SystemOneClassification\Exceptions\RejectedByModel;
use EarthlingOllie\SystemOneClassification\Exceptions\UnknownQuestion;
use EarthlingOllie\SystemOneClassification\Protocol\SystemOneCodec;
use EarthlingOllie\SystemOneClassification\Result\Usage;
use EarthlingOllie\SystemOneClassification\Transport\Response;
use EarthlingOllie\SystemOneClassification\Transport\Transport;

/**
 * A transport that records every request and answers every question about each state
 * "Ticket <n>" with a probability of n / 1000, so a result shows which context it belongs to. Each request
 * reports 10 input tokens and 1 output token per state.
 */
final class RecordingBatchTransport implements Transport
{
    /** @var list<array{path: string, body: array<string, mixed>}> */
    public array $requests = [];

    /** @param  array<int, Response>  $responses  canned responses by request number (0-based) */
    public function __construct(private readonly array $responses = []) {}

    public function post(string $path, array $body): Response
    {
        $number = count($this->requests);
        $this->requests[] = ['path' => $path, 'body' => $body];

        if (isset($this->responses[$number])) {
            return $this->responses[$number];
        }

        $states = $body['states'];
        $questions = $body['questions'];
        assert(is_array($states) && is_array($questions));
        $results = array_map(static fn (mixed $state): array => ['answers' => array_map(static fn (): array => [
            'type' => 'noul',
            'noul' => (int) substr((string) $state, strlen('Ticket ')) / 1000,
        ], $questions)], $states);

        return new Response(200, json_encode([
            'results' => $results,
            'total_usage' => ['input_tokens' => 10 * count($states), 'output_tokens' => count($states)],
        ], JSON_THROW_ON_ERROR));
    }
}

function batchChurn(): YesNoQuestion
{
    return Question::yesNo('churn', 'Does the customer threaten to cancel?');
}

/** @return list<Context> */
function tickets(int $count): array
{
    return array_map(static fn (int $n): Context => Context::text("Ticket {$n}"), range(1, $count));
}

it('sends a small batch as one request to the batch endpoint and returns a result per context', function (): void {
    $transport = new RecordingBatchTransport;
    $decision = Decision::about(Context::text('template'))->ask(batchChurn());

    $results = (new DecisionClient($transport, new SystemOneCodec))->decideMany(tickets(3), $decision);

    expect($transport->requests)->toBe([[
        'path' => '/v1/systemone/batch',
        'body' => [
            'states' => ['Ticket 1', 'Ticket 2', 'Ticket 3'],
            'questions' => ['churn' => ['type' => 'noul', 'instructions' => 'Does the customer threaten to cancel?']],
        ],
    ]])
        ->and($results)->toHaveCount(3)
        ->and($results[0]->get(batchChurn())->probability)->toBe(0.001)
        ->and($results[2]->get(batchChurn())->probability)->toBe(0.003)
        ->and($results->usage)->toEqual(new Usage(30, 3));
});

it('splits 130 contexts into 3 requests and keeps the results in input order across the chunk boundaries', function (): void {
    $transport = new RecordingBatchTransport;
    $decision = Decision::about(Context::text('template'))->ask(batchChurn());

    $results = (new DecisionClient($transport, new SystemOneCodec))->decideMany(tickets(130), $decision);

    $sent = array_map(static fn (array $request): array => $request['body']['states'], $transport->requests);
    $probabilities = array_map(static fn ($result): float => $result->get(batchChurn())->probability, iterator_to_array($results));

    expect($transport->requests)->toHaveCount(3)
        ->and(array_column($transport->requests, 'path'))->each->toBe('/v1/systemone/batch')
        ->and(array_map(count(...), $sent))->toBe([64, 64, 2])
        ->and($sent[0][63])->toBe('Ticket 64')
        ->and($sent[1][0])->toBe('Ticket 65')
        ->and($sent[1][63])->toBe('Ticket 128')
        ->and($sent[2])->toBe(['Ticket 129', 'Ticket 130'])
        ->and($results)->toHaveCount(130)
        ->and(array_keys($probabilities))->toBe(range(0, 129))
        ->and($probabilities)->toBe(array_map(static fn (int $n): float => $n / 1000, range(1, 130)))
        ->and($results[63]->get(batchChurn())->probability)->toBe(0.064)
        ->and($results[64]->get(batchChurn())->probability)->toBe(0.065)
        ->and($results[128]->get(batchChurn())->probability)->toBe(0.129)
        ->and($results->usage)->toEqual(new Usage(1300, 130));
});

it('sends the same questions in every chunk', function (): void {
    $transport = new RecordingBatchTransport;
    $decision = Decision::about(Context::text('template'))->ask(batchChurn(), Question::yesNo('refund', 'A refund?'));

    (new DecisionClient($transport, new SystemOneCodec))->decideMany(tickets(65), $decision);

    $questions = array_map(static fn (array $request): mixed => $request['body']['questions'], $transport->requests);

    expect($questions)->toHaveCount(2)
        ->and($questions[1])->toBe($questions[0])
        ->and($questions[0])->toBe((new SystemOneCodec)->encode($decision)['questions']);
});

it('has no usage when a chunk response reports none', function (): void {
    $noUsage = new Response(200, json_encode(['results' => [['answers' => ['churn' => ['type' => 'noul', 'noul' => 0.5]]]]], JSON_THROW_ON_ERROR));
    $transport = new RecordingBatchTransport([1 => $noUsage]);

    $results = (new DecisionClient($transport, new SystemOneCodec))
        ->decideMany(tickets(65), Decision::about(Context::text('template'))->ask(batchChurn()));

    expect($results)->toHaveCount(65)
        ->and($results->usage)->toBeNull();
});

it('sends nothing when any context is invalid, even one in a later chunk', function (): void {
    $transport = new RecordingBatchTransport;
    $contexts = tickets(100);
    $contexts[99] = 'Ticket 100';

    try {
        (new DecisionClient($transport, new SystemOneCodec))
            ->decideMany($contexts, Decision::about(Context::text('template'))->ask(batchChurn()));
    } finally {
        expect($transport->requests)->toBe([]);
    }
})->throws(InvalidDecision::class, 'Context 99 of the batch is string, not a Context; build it with Context::text().');

it('sends nothing for an empty list of contexts', function (): void {
    $transport = new RecordingBatchTransport;

    try {
        (new DecisionClient($transport, new SystemOneCodec))
            ->decideMany([], Decision::about(Context::text('template'))->ask(batchChurn()));
    } finally {
        expect($transport->requests)->toBe([]);
    }
})->throws(InvalidDecision::class, 'decideMany() needs at least one context.');

it('sends nothing for a decision that asks no questions', function (): void {
    $transport = new RecordingBatchTransport;

    try {
        (new DecisionClient($transport, new SystemOneCodec))->decideMany(tickets(2), Decision::about(Context::text('template')));
    } finally {
        expect($transport->requests)->toBe([]);
    }
})->throws(InvalidDecision::class, 'The decision asks no questions; add one with ask().');

it('raises the error of a failing chunk and sends no later chunk', function (): void {
    $transport = new RecordingBatchTransport([1 => new Response(413, '{"detail":"too many states in batch (65 > 64)"}')]);

    try {
        (new DecisionClient($transport, new SystemOneCodec))
            ->decideMany(tickets(130), Decision::about(Context::text('template'))->ask(batchChurn()));
    } finally {
        expect($transport->requests)->toHaveCount(2);
    }
})->throws(RejectedByModel::class, 'too many states in batch (65 > 64)');

it('reads batch results with an equal handle built separately, as decide() results are', function (): void {
    $results = (new DecisionClient(new RecordingBatchTransport, new SystemOneCodec))
        ->decideMany(tickets(1), Decision::about(Context::text('template'))->ask(batchChurn()));

    expect($results[0]->get(Question::yesNo('churn', 'Does the customer threaten to cancel?'))->probability)->toBe(0.001)
        ->and(fn () => $results[0]->get(Question::yesNo('churn', 'Something else?')))
        ->toThrow(UnknownQuestion::class);
});
