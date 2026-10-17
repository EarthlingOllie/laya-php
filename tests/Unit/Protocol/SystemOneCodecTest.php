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
use EarthlingOllie\SystemOneClassification\Model\JevProfile;
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

it('sends the pinned model, and no model field when none is pinned', function (): void {
    $pinned = (new SystemOneCodec)->encode(churnDecision()->withModel('english'));

    expect($pinned)->toBe([
        'state' => 'We will cancel.',
        'model' => 'english',
        'questions' => ['churn' => ['type' => 'noul', 'instructions' => 'Does the customer threaten to cancel?']],
    ])->and((new SystemOneCodec)->encode(churnDecision()))->not->toHaveKey('model');
});

it("sends the connection's default model when the decision pins none", function (): void {
    expect((new SystemOneCodec)->encode(churnDecision(), 'multilingual'))->toBe([
        'state' => 'We will cancel.',
        'model' => 'multilingual',
        'questions' => ['churn' => ['type' => 'noul', 'instructions' => 'Does the customer threaten to cancel?']],
    ]);
});

it("lets the decision's pinned model win over the connection's default", function (): void {
    expect((new SystemOneCodec)->encode(churnDecision()->withModel('english'), 'multilingual')['model'])->toBe('english');
});

it('holds the default model to the profile, as a pinned one', function (): void {
    expect(fn () => (new SystemOneCodec(new JevProfile))->encode(churnDecision(), 'jev-1'))
        ->toThrow(InvalidDecision::class, "The decision pins model 'jev-1', but the jev profile accepts no model values")
        ->and((new SystemOneCodec(new JevProfile(['jev-1'])))->encode(churnDecision(), 'jev-1')['model'])->toBe('jev-1');
});

// JSON carries only UTF-8: such text would fail in the HTTP client's json_encode() instead.
it('refuses context text that is not valid UTF-8', function (): void {
    $decision = Decision::about(Context::text("Billed tw\xB1ce"))->ask(Question::yesNo('churn', 'Cancel?'));

    expect(fn () => (new SystemOneCodec)->encode($decision))
        ->toThrow(InvalidDecision::class, 'The context text is not valid UTF-8, which JSON requires.');
});

it('refuses a question whose text is not valid UTF-8, naming its key', function (): void {
    $decision = Decision::about(Context::text('We will cancel.'))
        ->ask(Question::yesNo('churn', 'Cancel?'), Question::yesNo('refund', "Refund\xFF?"));

    expect(fn () => (new SystemOneCodec)->encode($decision))
        ->toThrow(InvalidDecision::class, "The text of question 'refund' is not valid UTF-8, which JSON requires.");
});

// Review finding: the pinned model was the one part of the body left unchecked; a profile
// built from config can accept a name that is not UTF-8.
it('refuses a pinned model that is not valid UTF-8', function (): void {
    $decision = churnDecision()->withModel("engl\xB1sh");

    expect(fn () => (new SystemOneCodec(new JevProfile(["engl\xB1sh"])))->encode($decision))
        ->toThrow(InvalidDecision::class, 'The pinned model is not valid UTF-8, which JSON requires.');
});

it('keeps the JSON error as the cause of a UTF-8 refusal', function (): void {
    try {
        (new SystemOneCodec)->encode(Decision::about(Context::text("\xB1"))->ask(Question::yesNo('churn', 'Cancel?')));
    } catch (InvalidDecision $exception) {
        expect($exception->getPrevious())->toBeInstanceOf(JsonException::class);

        return;
    }

    test()->fail('No InvalidDecision was raised.');
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

it('names the attempts of a 5xx that persisted through retries', function (int $attempts, string $message): void {
    expect(fn () => (new SystemOneCodec)->decode(churnDecision(), new Response(503, '{"detail":"server busy"}', attempts: $attempts)))
        ->toThrow(HostUnavailable::class, $message);
})->with([
    [1, 'The model server is unavailable (HTTP 503): server busy'],
    [2, 'The model server is unavailable (HTTP 503 after 2 attempts): server busy'],
]);

it('reports a 5xx without a JSON detail by its status alone', function (string $body): void {
    (new SystemOneCodec)->decode(churnDecision(), new Response(502, $body));
})->with(['<html>Bad Gateway</html>', '{"detail": 7}', '[]'])
    ->throws(HostUnavailable::class, 'The model server is unavailable (HTTP 502).');

it('raises RejectedByModel for a 4xx', function (int $status): void {
    (new SystemOneCodec)->decode(churnDecision(), new Response($status, '{"detail":"nope"}'));
})->with([400, 404, 499])->throws(RejectedByModel::class, 'nope');

it('reports a 4xx without a readable message by its status alone', function (string $body): void {
    try {
        (new SystemOneCodec)->decode(churnDecision(), new Response(404, $body));
        test()->fail('Expected RejectedByModel.');
    } catch (RejectedByModel $rejected) {
        expect($rejected->getMessage())->toBe('The model server rejected the decision (HTTP 404).')
            ->and($rejected->detail)->toBeNull();
    }
})->with([
    'empty' => [''],
    'blank' => ["  \n "],
    'an HTML page' => ['<!doctype html><title>404</title>'],
    'HTML after whitespace' => ["\n  <html>Not Found</html>"],
    'a detail that is not text' => ['{"detail": 7}'],
    'a blank detail' => ['{"detail": " "}'],
    'an empty list of errors' => ['{"detail": []}'],
    'errors without a msg' => ['{"detail": [{"loc": ["body"]}, "x", {"msg": 3}]}'],
    'a JSON list' => ['[]'],
    'a JSON number' => ['404'],
    'a blank JSON string' => ['" "'],
    'an error object without a message' => ['{"error": {"code": 7}}'],
]);

it('carries the server message whatever shape the error body takes', function (string $body, string $detail): void {
    try {
        (new SystemOneCodec)->decode(churnDecision(), new Response(401, $body));
        test()->fail('Expected RejectedByModel.');
    } catch (RejectedByModel $rejected) {
        expect($rejected->detail)->toBe($detail)
            ->and($rejected->getMessage())->toBe("The model server rejected the decision (HTTP 401): {$detail}");
    }
})->with([
    'laya-serve and Jev: detail' => ['{"detail":"invalid or missing bearer token"}', 'invalid or missing bearer token'],
    'a gateway: message' => ['{"message":"Unauthorized"}', 'Unauthorized'],
    'error as text' => ['{"error":"invalid_api_key"}', 'invalid_api_key'],
    'error as an object' => ['{"error":{"type":"auth","message":"Incorrect API key provided."}}', 'Incorrect API key provided.'],
    'detail wins over message' => ['{"message":"second","detail":"first"}', 'first'],
    'message wins over error' => ['{"error":"second","message":"first"}', 'first'],
    'trimmed' => ['{"detail":"  spaced  "}', 'spaced'],
    'plain text' => ["Unauthorized\n", 'Unauthorized'],
    // Review finding: a JSON string body is plain text too.
    'a JSON string' => ['"Rate limit exceeded"', 'Rate limit exceeded'],
    'plain text, its whitespace collapsed' => ["Bad\n\n  key", 'Bad key'],
]);

it('cuts a plain-text message to 200 characters, counting characters, not bytes', function (string $body, string $detail): void {
    try {
        (new SystemOneCodec)->decode(churnDecision(), new Response(400, $body));
        test()->fail('Expected RejectedByModel.');
    } catch (RejectedByModel $rejected) {
        expect($rejected->detail)->toBe($detail);
    }
})->with([
    'longer' => ['a'.str_repeat('é', 200), 'a'.str_repeat('é', 199).'…'],
    'exactly 200' => [str_repeat('é', 200), str_repeat('é', 200)],
]);

it('gives the reason of a FastAPI validation error, without the input it echoes', function (): void {
    $body = json_encode(['detail' => [
        ['type' => 'no message', 'loc' => ['body']],
        ['type' => 'missing', 'loc' => ['body', 'state'], 'msg' => 'Field required', 'input' => ['questions' => 'the customer text']],
        ['type' => 'float_type', 'loc' => ['body', 'min_confidence'], 'msg' => 'Input should be a valid number', 'input' => 'private'],
        ['type' => 'x', 'loc' => [], 'msg' => 'No location'],
        ['type' => 'y', 'loc' => ['body', 'questions', 0, 7.5, true], 'msg' => 'Odd location'],
        ['type' => 'z', 'msg' => 'Location left out'],
        ['type' => 'w', 'loc' => 'body', 'msg' => 'Location not a list'],
    ]], JSON_THROW_ON_ERROR);

    try {
        (new SystemOneCodec)->decode(churnDecision(), new Response(422, $body));
        test()->fail('Expected RejectedByModel.');
    } catch (RejectedByModel $rejected) {
        expect($rejected->status)->toBe(422)
            ->and($rejected->detail)->toBe('body.state: Field required; body.min_confidence: Input should be a valid number; No location; body.questions.0: Odd location; Location left out; Location not a list')
            ->and($rejected->getMessage())->not->toContain('the customer text')->not->toContain('private');
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
})->throws(UnknownQuestion::class, "The result's question 'churn' is not the one passed to get(): it differs in its instructions, so the handle is from another decision.");
