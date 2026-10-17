<?php

declare(strict_types=1);

use EarthlingOllie\SystemOneClassification\Domain\Context;
use EarthlingOllie\SystemOneClassification\Domain\Decision;
use EarthlingOllie\SystemOneClassification\Domain\Question;
use EarthlingOllie\SystemOneClassification\Exceptions\HostUnavailable;
use EarthlingOllie\SystemOneClassification\Exceptions\InvalidDecision;
use EarthlingOllie\SystemOneClassification\Exceptions\MalformedResponse;
use EarthlingOllie\SystemOneClassification\Exceptions\RejectedByModel;
use EarthlingOllie\SystemOneClassification\Protocol\SystemOneCodec;
use EarthlingOllie\SystemOneClassification\Result\Usage;
use EarthlingOllie\SystemOneClassification\Transport\Response;

function batchDecision(): Decision
{
    return Decision::about(Context::text('We will cancel.'))
        ->ask(Question::yesNo('churn', 'Does the customer threaten to cancel?'));
}

/** @return list<Context> */
function batchContexts(int $count): array
{
    return array_map(static fn (int $i): Context => Context::text("Ticket {$i}"), range(1, $count));
}

/**
 * A batch response answering `churn` once per probability.
 *
 * @param  list<float>  $probabilities
 * @param  array<string, mixed>  $extra  more top-level fields
 */
function batchResponse(array $probabilities, array $extra = ['total_usage' => ['input_tokens' => 30, 'output_tokens' => 0]]): Response
{
    $results = array_map(static fn (float $p): array => ['answers' => ['churn' => ['type' => 'noul', 'noul' => $p]]], $probabilities);

    return new Response(200, json_encode(['results' => $results, ...$extra], JSON_THROW_ON_ERROR));
}

it('encodes a batch as the contexts as states and the decision questions once', function (): void {
    $bodies = (new SystemOneCodec)->encodeBatches(batchDecision(), [Context::text('First'), Context::text('Second')]);

    expect($bodies)->toBe([[
        'states' => ['First', 'Second'],
        'questions' => ['churn' => ['type' => 'noul', 'instructions' => 'Does the customer threaten to cancel?']],
    ]]);
});

it('builds the rest of each batch body exactly as encode() builds a single request', function (): void {
    $codec = new SystemOneCodec;
    $decision = batchDecision();

    [$body] = $codec->encodeBatches($decision, [Context::text('First')]);
    $single = $codec->encode($decision);
    unset($body['states'], $single['state']);

    expect($body)->toBe($single);
});

it('splits contexts past the server cap into requests of at most 64 states, in order', function (int $count, array $sizes): void {
    $bodies = (new SystemOneCodec)->encodeBatches(batchDecision(), batchContexts($count));

    expect(array_map(static fn (array $body): int => count($body['states']), $bodies))->toBe($sizes)
        ->and(array_merge(...array_column($bodies, 'states')))->toBe(array_map(static fn (int $i): string => "Ticket {$i}", range(1, $count)));
})->with([
    'one' => [1, [1]],
    'exactly the cap' => [64, [64]],
    'one past the cap' => [65, [64, 1]],
    'three requests' => [130, [64, 64, 2]],
]);

it('caps a batch request at laya-serve MAX_BATCH_STATES', function (): void {
    expect(SystemOneCodec::MAX_BATCH_STATES)->toBe(64)
        ->and(SystemOneCodec::BATCH_PATH)->toBe('/v1/systemone/batch');
});

it('caps a batch request body at laya-serve MAX_BODY_BYTES', function (): void {
    expect(SystemOneCodec::MAX_BATCH_BODY_BYTES)->toBe(2 * 1024 * 1024);
});

/** The JSON bytes a body goes out as: what the HTTP client sends (json_encode, default flags). */
function batchBodyBytes(array $body): int
{
    return strlen(json_encode($body, JSON_THROW_ON_ERROR));
}

it('splits long contexts into requests that each fit the server body limit, in order', function (): void {
    // 64 states of 40,000 characters: each under laya-serve's 50,000, together about 2.5 MB.
    $texts = array_map(static fn (int $i): string => str_pad("Ticket {$i} ", 40_000, 'x'), range(1, 64));

    $bodies = (new SystemOneCodec)->encodeBatches(batchDecision(), array_map(Context::text(...), $texts));

    expect(count($bodies))->toBe(2)
        ->and(array_map(batchBodyBytes(...), $bodies))->each->toBeLessThanOrEqual(SystemOneCodec::MAX_BATCH_BODY_BYTES)
        ->and(array_merge(...array_column($bodies, 'states')))->toBe($texts);
});

it('fills a request up to exactly the body limit and starts another one byte past it', function (bool $afterAnother, int $extra, array $sizes): void {
    $codec = new SystemOneCodec;
    $empty = $codec->encodeBatches(batchDecision(), [Context::text('x')])[0];
    $empty['states'] = [];
    // Four states add their quoted texts and three commas to the body around them.
    $first = str_repeat('a', 1_000_000);
    $second = str_repeat('b', 500_000);
    $third = str_repeat('c', 250_000);
    $fourth = str_repeat('d', SystemOneCodec::MAX_BATCH_BODY_BYTES - batchBodyBytes($empty) - strlen($first) - strlen($second) - strlen($third) - 11 + $extra);
    // A state too large to share a request fills one of its own first, so the four start the next.
    $lead = $afterAnother ? [str_repeat('h', SystemOneCodec::MAX_BATCH_BODY_BYTES)] : [];

    $bodies = $codec->encodeBatches(batchDecision(), array_map(Context::text(...), [...$lead, $first, $second, $third, $fourth]));

    expect(array_map(static fn (array $body): int => count($body['states']), $bodies))->toBe($sizes)
        ->and(batchBodyBytes($bodies[count($lead)]))->toBeLessThanOrEqual(SystemOneCodec::MAX_BATCH_BODY_BYTES);
})->with([
    'exactly the limit' => [false, 0, [4]],
    'one byte over' => [false, 1, [3, 1]],
    'exactly the limit, in a later request' => [true, 0, [1, 4]],
    'one byte over, in a later request' => [true, 1, [1, 3, 1]],
]);

it('measures a state as the HTTP client encodes it, escaped Unicode included', function (): void {
    // 200,000 "é" are 400,000 UTF-8 bytes but 1,200,000 once json_encode escapes them as \u00e9.
    $text = str_repeat('é', 200_000);

    $bodies = (new SystemOneCodec)->encodeBatches(batchDecision(), [Context::text($text), Context::text($text)]);

    expect(array_map(static fn (array $body): int => count($body['states']), $bodies))->toBe([1, 1]);
});

it('sends a state too large for any request on its own, for the server to refuse', function (): void {
    $huge = str_repeat('h', SystemOneCodec::MAX_BATCH_BODY_BYTES);

    $bodies = (new SystemOneCodec)->encodeBatches(batchDecision(), [Context::text($huge), Context::text('Small'), Context::text($huge)]);

    expect(array_column($bodies, 'states'))->toBe([[$huge], ['Small'], [$huge]]);
});

it('refuses an empty list of contexts', function (): void {
    (new SystemOneCodec)->encodeBatches(batchDecision(), []);
})->throws(InvalidDecision::class, 'decideMany() needs at least one context.');

it('refuses contexts that are not a list', function (): void {
    (new SystemOneCodec)->encodeBatches(batchDecision(), ['a' => Context::text('First')]);
})->throws(InvalidDecision::class, 'decideMany() takes the contexts as a list (keys 0, 1, 2, ...), so each result can be read back at its position.');

it('refuses anything in the contexts that is not a Context', function (mixed $value, string $type): void {
    expect(fn () => (new SystemOneCodec)->encodeBatches(batchDecision(), [Context::text('First'), $value]))
        ->toThrow(InvalidDecision::class, "Context 1 of the batch is {$type}, not a Context; build it with Context::text().");
})->with([
    'a string' => ['Second', 'string'],
    'null' => [null, 'null'],
    'another object' => [new stdClass, 'stdClass'],
]);

it('refuses a batch whose decision the codec cannot encode, as encode() does', function (): void {
    (new SystemOneCodec)->encodeBatches(Decision::about(Context::text('x')), [Context::text('First')]);
})->throws(InvalidDecision::class, 'The decision asks no questions; add one with ask().');

it('decodes one result per state, in order, with the total usage', function (): void {
    $set = (new SystemOneCodec)->decodeBatch(batchDecision(), 3, batchResponse([0.1, 0.5, 0.9]));
    $churn = Question::yesNo('churn', 'Does the customer threaten to cancel?');

    expect($set)->toHaveCount(3)
        ->and($set[0]->get($churn)->probability)->toBe(0.1)
        ->and($set[1]->get($churn)->probability)->toBe(0.5)
        ->and($set[2]->get($churn)->probability)->toBe(0.9)
        ->and($set->usage)->toEqual(new Usage(30, 0));
});

it('has no usage when the batch response reports none', function (): void {
    $set = (new SystemOneCodec)->decodeBatch(batchDecision(), 1, batchResponse([0.5], []));

    expect($set->usage)->toBeNull();
});

it('raises the same errors as decode() for error statuses', function (int $status, string $exception): void {
    expect(fn () => (new SystemOneCodec)->decodeBatch(batchDecision(), 1, new Response($status, '{"detail":"too many states in batch (65 > 64)"}')))
        ->toThrow($exception, 'too many states in batch (65 > 64)');
})->with([
    '413' => [413, RejectedByModel::class],
    '503' => [503, HostUnavailable::class],
]);

it('raises MalformedResponse for a batch body that does not match the expected shape', function (string $body, string $message): void {
    expect(fn () => (new SystemOneCodec)->decodeBatch(batchDecision(), 2, new Response(200, $body)))
        ->toThrow(MalformedResponse::class, $message);
})->with([
    'not JSON' => ['<html>', 'The response is not a JSON object.'],
    'no results' => ['{"total_usage": {"input_tokens": 1, "output_tokens": 0}}', 'The batch response has no results list.'],
    'results is an object' => ['{"results": {"a": 1}}', 'The batch response has no results list.'],
    'too few results' => ['{"results": [{"answers": {"churn": {"type": "noul", "noul": 0.5}}}]}', 'The batch response has 1 results for 2 states.'],
    'too many results' => ['{"results": [{}, {}, {}]}', 'The batch response has 3 results for 2 states.'],
    'a result is not an object' => ['{"results": [{"answers": {"churn": {"type": "noul", "noul": 0.5}}}, 7]}', 'Result 1 of the batch response: The response is not a JSON object.'],
    'a result is a list' => ['{"results": [[1], {"answers": {"churn": {"type": "noul", "noul": 0.5}}}]}', 'Result 0 of the batch response: The response is not a JSON object.'],
    'a result has no answers' => ['{"results": [{"answers": {"churn": {"type": "noul", "noul": 0.5}}}, {"model": "x"}]}', 'Result 1 of the batch response: The response has no answers object.'],
    'a result has a bad answer' => ['{"results": [{"answers": {"churn": {"type": "noul", "noul": 2}}}, {"answers": {"churn": {"type": "noul", "noul": 0.5}}}]}', "Result 0 of the batch response: The answer for 'churn' has a 'noul' probability outside [0, 1]: 2."],
]);

it('raises MalformedResponse for a total_usage it cannot read', function (string $usage): void {
    $body = '{"results": [{"answers": {"churn": {"type": "noul", "noul": 0.5}}}], "total_usage": '.$usage.'}';

    expect(fn () => (new SystemOneCodec)->decodeBatch(batchDecision(), 1, new Response(200, $body)))
        ->toThrow(MalformedResponse::class, 'The batch response has a total_usage without non-negative integer input_tokens and output_tokens.');
})->with([
    'a number' => ['7'],
    'no input tokens' => ['{"output_tokens": 0}'],
    'no output tokens' => ['{"input_tokens": 3}'],
    'a float count' => ['{"input_tokens": 3.5, "output_tokens": 0}'],
    'a string count' => ['{"input_tokens": 3, "output_tokens": "0"}'],
    'a negative input count' => ['{"input_tokens": -1, "output_tokens": 0}'],
    'a negative output count' => ['{"input_tokens": 1, "output_tokens": -1}'],
]);

it('accepts zero token counts', function (): void {
    $set = (new SystemOneCodec)->decodeBatch(batchDecision(), 1, batchResponse([0.5], ['total_usage' => ['input_tokens' => 0, 'output_tokens' => 0]]));

    expect($set->usage)->toEqual(new Usage(0, 0));
});
