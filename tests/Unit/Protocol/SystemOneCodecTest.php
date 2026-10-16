<?php

declare(strict_types=1);

use EarthlingOllie\SystemOneClassification\Domain\Context;
use EarthlingOllie\SystemOneClassification\Domain\Decision;
use EarthlingOllie\SystemOneClassification\Domain\Question;
use EarthlingOllie\SystemOneClassification\Domain\Question\QuestionHandle;
use EarthlingOllie\SystemOneClassification\Exceptions\HostUnavailable;
use EarthlingOllie\SystemOneClassification\Exceptions\InvalidDecision;
use EarthlingOllie\SystemOneClassification\Exceptions\MalformedResponse;
use EarthlingOllie\SystemOneClassification\Exceptions\RejectedByModel;
use EarthlingOllie\SystemOneClassification\Exceptions\UnknownQuestion;
use EarthlingOllie\SystemOneClassification\Protocol\SystemOneCodec;
use EarthlingOllie\SystemOneClassification\Result\Answer\YesNoAnswer;
use EarthlingOllie\SystemOneClassification\Transport\Response;

function churnDecision(): Decision
{
    return Decision::about(Context::text('We will cancel.'))
        ->ask(Question::yesNo('churn', 'Does the customer threaten to cancel?'));
}

/** @param array<mixed> $body */
function okResponse(array $body): Response
{
    return new Response(200, json_encode($body, JSON_THROW_ON_ERROR));
}

it('encodes the context as the state and every question under its key', function (): void {
    $decision = Decision::about(Context::text('We will cancel.'))->ask(
        Question::yesNo('churn', 'Does the customer threaten to cancel?'),
        Question::yesNo('refund', 'Do they ask for a refund?'),
    );

    expect((new SystemOneCodec)->encode($decision))->toBe([
        'state' => 'We will cancel.',
        'questions' => [
            'churn' => ['type' => 'noul', 'instructions' => 'Does the customer threaten to cancel?'],
            'refund' => ['type' => 'noul', 'instructions' => 'Do they ask for a refund?'],
        ],
    ]);
});

it('refuses to encode a decision that asks nothing', function (): void {
    (new SystemOneCodec)->encode(Decision::about(Context::text('We will cancel.')));
})->throws(InvalidDecision::class, 'The decision asks no questions; add one with ask().');

it('decodes each asked noul answer, integer probabilities included', function (int|float $noul, float $probability): void {
    $result = (new SystemOneCodec)->decode(churnDecision(), okResponse([
        'answers' => ['churn' => ['type' => 'noul', 'noul' => $noul]],
    ]));

    expect($result->get(Question::yesNo('churn', 'Does the customer threaten to cancel?'))->probability)->toBe($probability);
})->with([
    [0.97, 0.97],
    [0, 0.0],
    [1, 1.0],
]);

it('accepts every 2xx status', function (int $status): void {
    $body = json_encode(['answers' => ['churn' => ['type' => 'noul', 'noul' => 0.5]]], JSON_THROW_ON_ERROR);

    expect((new SystemOneCodec)->decode(churnDecision(), new Response($status, $body)))->not->toBeNull();
})->with([200, 299]);

it('raises HostUnavailable for a 5xx', function (int $status): void {
    (new SystemOneCodec)->decode(churnDecision(), new Response($status, '{"detail":"server busy"}'));
})->with([500, 503, 599])->throws(HostUnavailable::class, 'The model server is unavailable (HTTP');

it('keeps the 5xx status and detail', function (): void {
    try {
        (new SystemOneCodec)->decode(churnDecision(), new Response(503, '{"detail":"server busy"}'));
    } catch (HostUnavailable $unavailable) {
        expect($unavailable->getMessage())->toBe('The model server is unavailable (HTTP 503): server busy')
            ->and($unavailable->status)->toBe(503);
    }
});

it('reports a 5xx without a JSON detail by its status alone', function (string $body): void {
    (new SystemOneCodec)->decode(churnDecision(), new Response(502, $body));
})->with(['<html>Bad Gateway</html>', '{"detail": 7}', '[]'])
    ->throws(HostUnavailable::class, 'The model server is unavailable (HTTP 502).');

it('raises RejectedByModel for a 4xx', function (int $status): void {
    (new SystemOneCodec)->decode(churnDecision(), new Response($status, '{"detail":"nope"}'));
})->with([400, 404, 499])->throws(RejectedByModel::class, 'nope');

it('reports a 4xx without a JSON detail by its status alone', function (): void {
    try {
        (new SystemOneCodec)->decode(churnDecision(), new Response(404, 'Not Found'));
    } catch (RejectedByModel $rejected) {
        expect($rejected->getMessage())->toBe('The model server rejected the decision (HTTP 404).')
            ->and($rejected->detail)->toBeNull();
    }
});

it('raises MalformedResponse for a status that is neither success nor error', function (int $status): void {
    (new SystemOneCodec)->decode(churnDecision(), new Response($status, '{}'));
})->with([199, 300, 399])->throws(MalformedResponse::class, 'Unexpected HTTP status');

it('raises MalformedResponse for a body that does not match the expected shape', function (string $body, string $message): void {
    expect(fn () => (new SystemOneCodec)->decode(churnDecision(), new Response(200, $body)))
        ->toThrow(MalformedResponse::class, $message);
})->with([
    'not JSON' => ['<html>', 'The response is not a JSON object.'],
    'a JSON list' => ['[1, 2]', 'The response is not a JSON object.'],
    'a JSON string' => ['"ok"', 'The response is not a JSON object.'],
    'no answers' => ['{"model": "laya"}', 'The response has no answers object.'],
    'answers is a list' => ['{"answers": [1]}', 'The response has no answers object.'],
    'missing answer' => ['{"answers": {"other": {"type": "noul", "noul": 0.5}}}', "The response has no answer for 'churn'."],
    'answer not an object' => ['{"answers": {"churn": 0.5}}', "The answer for 'churn' is not an object."],
    'wrong type' => ['{"answers": {"churn": {"type": "choice", "noul": 0.5}}}', "The answer for 'churn' should have type 'noul', got \"choice\"."],
    'no type' => ['{"answers": {"churn": {"noul": 0.5}}}', "The answer for 'churn' should have type 'noul', got null."],
    'no noul' => ['{"answers": {"churn": {"type": "noul"}}}', "The answer for 'churn' has no numeric 'noul' probability."],
    'noul is a string' => ['{"answers": {"churn": {"type": "noul", "noul": "0.5"}}}', "The answer for 'churn' has no numeric 'noul' probability."],
    'noul above 1' => ['{"answers": {"churn": {"type": "noul", "noul": 1.2}}}', "The answer for 'churn' has a 'noul' probability outside [0, 1]: 1.2."],
    'noul below 0' => ['{"answers": {"churn": {"type": "noul", "noul": -0.1}}}', "The answer for 'churn' has a 'noul' probability outside [0, 1]: -0.1."],
]);

it('keeps the raw body on a MalformedResponse for debugging', function (): void {
    try {
        (new SystemOneCodec)->decode(churnDecision(), new Response(200, '<html>'));
    } catch (MalformedResponse $malformed) {
        expect($malformed->body)->toBe('<html>');
    }
});

function unsupportedHandle(): QuestionHandle
{
    return new class implements QuestionHandle
    {
        public string $key = 'mystery';

        public string $instructions = 'What is it?';

        public function answerType(): string
        {
            return YesNoAnswer::class;
        }
    };
}

it('refuses to encode a question type the wire format does not know', function (): void {
    (new SystemOneCodec)->encode(Decision::about(Context::text('x'))->ask(unsupportedHandle()));
})->throws(InvalidDecision::class, 'The wire format has no question type for '.QuestionHandle::class.'@anonymous.');

// Only a direct caller gets here: DecisionClient encodes first, so it never sends such a decision.
it('refuses to decode a decision it could not have encoded', function (): void {
    (new SystemOneCodec)->decode(
        Decision::about(Context::text('x'))->ask(unsupportedHandle()),
        okResponse(['answers' => ['mystery' => ['type' => 'noul', 'noul' => 0.5]]]),
    );
})->throws(InvalidDecision::class, 'The wire format has no question type for '.QuestionHandle::class.'@anonymous.');

it('ignores answers to questions it did not ask', function (): void {
    $result = (new SystemOneCodec)->decode(churnDecision(), okResponse([
        'answers' => [
            'churn' => ['type' => 'noul', 'noul' => 0.2],
            'extra' => ['type' => 'noul', 'noul' => 0.9],
        ],
    ]));

    $result->get(Question::yesNo('extra', 'Something else?'));
})->throws(UnknownQuestion::class);

it('refuses a handle from another decision that reuses the key with other instructions', function (): void {
    $result = (new SystemOneCodec)->decode(churnDecision(), okResponse([
        'answers' => ['churn' => ['type' => 'noul', 'noul' => 0.2]],
    ]));

    $result->get(Question::yesNo('churn', 'Is the customer happy with the product?'));
})->throws(UnknownQuestion::class, "The result's question 'churn' is not the one passed to get(): it was asked with other instructions or settings, so the handle is from another decision.");
