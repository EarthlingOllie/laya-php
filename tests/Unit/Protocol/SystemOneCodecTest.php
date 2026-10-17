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
use EarthlingOllie\SystemOneClassification\Result\Abstention;
use EarthlingOllie\SystemOneClassification\Result\Answer\YesNoAnswer;
use EarthlingOllie\SystemOneClassification\Result\Meta;
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

// #7's language codes are text in the body too, so a code that is not UTF-8 is named as such.
it('refuses a language code or hint that is not valid UTF-8, naming it', function (Closure $control, string $message): void {
    $refused = null;

    try {
        (new SystemOneCodec)->encode($control(churnDecision()));
    } catch (InvalidDecision $exception) {
        $refused = $exception;
    }

    expect($refused?->getMessage())->toBe($message)
        ->and($refused?->getPrevious())->toBeInstanceOf(JsonException::class);
})->with([
    'language' => [fn (Decision $decision): Decision => $decision->withLanguage("d\xB1"), 'The language code is not valid UTF-8, which JSON requires.'],
    'language hint' => [fn (Decision $decision): Decision => $decision->withLanguageHint("d\xB1"), 'The language hint is not valid UTF-8, which JSON requires.'],
]);

it('keeps the JSON error as the cause of a UTF-8 refusal', function (): void {
    try {
        (new SystemOneCodec)->encode(Decision::about(Context::text("\xB1"))->ask(Question::yesNo('churn', 'Cancel?')));
    } catch (InvalidDecision $exception) {
        expect($exception->getPrevious())->toBeInstanceOf(JsonException::class);

        return;
    }

    test()->fail('No InvalidDecision was raised.');
});

it('encodes the deepest fields a context accepts into a request body that is still valid JSON', function (): void {
    // The body wraps the fields object (body → state → fields), so the deepest accepted field
    // must still fit json_encode's 512 levels once it sits inside the body.
    $nest = function (int $levels): array {
        $leaf = 'leaf';
        for ($i = 0; $i < $levels; $i++) {
            $leaf = ['n' => $leaf];
        }

        return $leaf;
    };
    // The body is level 1 and the fields object level 2, so a field may nest 510 arrays.
    $decision = Decision::about(Context::fields(['deep' => $nest(510)]))
        ->ask(Question::yesNo('churn', 'Does the customer threaten to cancel?'));

    expect(json_encode((new SystemOneCodec)->encode($decision)))->toBeString()
        ->and(fn () => Context::fields(['deep' => $nest(511)]))
        ->toThrow(InvalidDecision::class, "Maximum stack depth exceeded in the field 'deep', which nests arrays deeper than the 512 levels JSON allows.");
});

it('sends each request control under its protocol field, between the state and the questions', function (): void {
    $decision = churnDecision()
        ->withHeadMaxLength(32)
        ->withMaxLength(256)
        ->withLanguageHint('en_GB')
        ->withLanguage('en')
        ->withMinConfidence(0.75);

    $body = (new SystemOneCodec)->encode($decision);

    expect($body)->toBe([
        'state' => 'We will cancel.',
        'min_confidence' => 0.75,
        'lang' => 'en',
        'lang_guess' => 'en_GB',
        'max_len' => 256,
        'head_max_len' => 32,
        'questions' => ['churn' => ['type' => 'noul', 'instructions' => 'Does the customer threaten to cancel?']],
    ]);
});

it('sends structured fields as a JSON object state', function (): void {
    $decision = Decision::about(Context::fields(['subject' => 'Charged twice', 'meta' => ['seats' => 3, 'tags' => []]]))
        ->ask(Question::yesNo('churn', 'Does the customer threaten to cancel?'));

    $body = (new SystemOneCodec)->encode($decision);

    expect($body['state'])->toBe(['subject' => 'Charged twice', 'meta' => ['seats' => 3, 'tags' => []]])
        ->and(json_encode($body['state'], JSON_THROW_ON_ERROR))->toBe('{"subject":"Charged twice","meta":{"seats":3,"tags":[]}}');
});

it('sends only the request controls that were set', function (): void {
    $body = (new SystemOneCodec)->encode(churnDecision()->withMaxLength(64));

    expect(array_keys($body))->toBe(['state', 'max_len', 'questions'])
        ->and($body['max_len'] ?? null)->toBe(64);
});

it('sends a minimum confidence of zero rather than dropping it', function (): void {
    expect((new SystemOneCodec)->encode(churnDecision()->withMinConfidence(0.0)))->toHaveKey('min_confidence', 0.0);
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

/** @param array<string, mixed> $gate the abstention fields of the churn answer */
function churnAnswerWith(array $gate): YesNoAnswer
{
    return (new SystemOneCodec)->decode(churnDecision(), okResponse([
        'answers' => ['churn' => ['type' => 'noul', 'noul' => 0.73, ...$gate]],
    ]))->get(Question::yesNo('churn', 'Does the customer threaten to cancel?'));
}

it('reads the abstention gate of an answer', function (array $gate, Abstention $abstention, bool $abstained, ?float $threshold): void {
    $answer = churnAnswerWith($gate);

    expect($answer->abstention)->toBe($abstention)
        ->and($answer->abstained)->toBe($abstained)
        ->and($answer->abstentionThreshold)->toBe($threshold)
        ->and($answer->probability)->toBe(0.73);
})->with([
    'no gate ran' => [[], Abstention::NotRequested, false, null],
    'abstained' => [['low_confidence' => true, 'abstention' => 'abstained', 'abstention_threshold' => 0.9], Abstention::Abstained, true, 0.9],
    'passed' => [['abstention' => 'passed', 'abstention_threshold' => 0.6], Abstention::Passed, false, 0.6],
    'unevaluated' => [['abstention' => 'unevaluated', 'abstention_threshold' => 0.6], Abstention::Unevaluated, false, 0.6],
    'integer threshold' => [['abstention' => 'passed', 'abstention_threshold' => 0], Abstention::Passed, false, 0.0],
    'threshold of 1' => [['abstention' => 'abstained', 'abstention_threshold' => 1.0], Abstention::Abstained, true, 1.0],
    'integer threshold of 1' => [['abstention' => 'abstained', 'abstention_threshold' => 1], Abstention::Abstained, true, 1.0],
    'low_confidence alone' => [['low_confidence' => true], Abstention::Abstained, true, null],
    'low_confidence with a threshold' => [['low_confidence' => true, 'abstention_threshold' => 0.8], Abstention::Abstained, true, 0.8],
    'low_confidence false' => [['low_confidence' => false], Abstention::NotRequested, false, null],
    'abstention wins over low_confidence' => [['low_confidence' => true, 'abstention' => 'passed', 'abstention_threshold' => 0.5], Abstention::Passed, false, 0.5],
]);

it('reads a gated answer that reports no gate verdict as Unevaluated, neither NotRequested nor Passed', function (array $gate, Abstention $abstention, bool $abstained): void {
    $answer = (new SystemOneCodec)->decode(churnDecision()->withMinConfidence(0.6), okResponse([
        'answers' => ['churn' => ['type' => 'noul', 'noul' => 0.73, ...$gate]],
    ]))->get(Question::yesNo('churn', 'Does the customer threaten to cancel?'));

    expect($answer->abstention)->toBe($abstention)
        ->and($answer->abstained)->toBe($abstained)
        ->and($answer->abstentionThreshold)->toBeNull();
})->with([
    'no gate fields' => [[], Abstention::Unevaluated, false],
    'low_confidence false' => [['low_confidence' => false], Abstention::Unevaluated, false],
    'low_confidence true' => [['low_confidence' => true], Abstention::Abstained, true],
]);

it('raises MalformedResponse for abstention fields of the wrong shape', function (array $gate, string $message): void {
    expect(fn () => churnAnswerWith($gate))->toThrow(MalformedResponse::class, $message);
})->with([
    'unknown state' => [['abstention' => 'maybe'], "The answer for 'churn' has an unknown 'abstention' state: \"maybe\"."],
    'state not a string' => [['abstention' => true], "The answer for 'churn' has an unknown 'abstention' state: true."],
    'threshold a string' => [['abstention' => 'passed', 'abstention_threshold' => '0.5'], "The answer for 'churn' has a non-numeric 'abstention_threshold'."],
    'threshold above 1' => [['abstention' => 'passed', 'abstention_threshold' => 1.5], "The answer for 'churn' has an 'abstention_threshold' outside [0, 1]: 1.5."],
    'threshold below 0' => [['abstention' => 'passed', 'abstention_threshold' => -0.5], "The answer for 'churn' has an 'abstention_threshold' outside [0, 1]: -0.5."],
    'low_confidence not a bool' => [['low_confidence' => 'yes'], "The answer for 'churn' has a non-boolean 'low_confidence'."],
]);

/** @param array<string, mixed> $root the response's root fields beside `answers` */
function metaFrom(array $root, ?float $latencyMs = null): Meta
{
    return (new SystemOneCodec)->decode(churnDecision(), okResponse([
        'answers' => ['churn' => ['type' => 'noul', 'noul' => 0.5]],
        ...$root,
    ]), $latencyMs)->meta;
}

it('reads the model, routing and token usage the server reported', function (): void {
    $meta = metaFrom([
        'model' => 'laya-rl-agent',
        'usage' => ['input_tokens' => 57, 'output_tokens' => 3, 'state_tokens' => 22, 'truncated' => false],
        'routing' => ['model' => 'english', 'repo' => '/somewhere', 'reason' => 'English Latin text', 'detection' => null],
    ], 12.5);

    expect($meta)->toEqual(new Meta(
        model: 'laya-rl-agent',
        checkpoint: 'english',
        routingReason: 'English Latin text',
        inputTokens: 57,
        outputTokens: 3,
        latencyMs: 12.5,
    ));
});

it('leaves out what the server did not report, as Jev does with routing', function (array $root, Meta $meta): void {
    expect(metaFrom($root))->toEqual($meta);
})->with([
    'nothing' => [[], new Meta],
    'nulls' => [['model' => null, 'usage' => null, 'routing' => null], new Meta],
    'usage only' => [['usage' => ['input_tokens' => 5, 'output_tokens' => 0]], new Meta(inputTokens: 5, outputTokens: 0)],
    'routing without reason' => [['routing' => ['model' => 'english']], new Meta(checkpoint: 'english')],
    'routing with null fields' => [['routing' => ['model' => null, 'reason' => null]], new Meta],
    'usage with one count' => [['usage' => ['input_tokens' => 9]], new Meta(inputTokens: 9)],
]);

it('raises MalformedResponse for metadata of the wrong shape', function (array $root, string $message): void {
    expect(fn () => metaFrom($root))->toThrow(MalformedResponse::class, $message);
})->with([
    'model not a string' => [['model' => 7], "The response's 'model' is not a string."],
    'routing not an object' => [['routing' => 'english'], "The response's 'routing' is not an object."],
    'routing a list' => [['routing' => ['english']], "The response's 'routing' is not an object."],
    'routing model not a string' => [['routing' => ['model' => 1]], "The response's 'routing.model' is not a string."],
    'routing reason not a string' => [['routing' => ['reason' => false]], "The response's 'routing.reason' is not a string."],
    'usage not an object' => [['usage' => 74], "The response's 'usage' is not an object."],
    'usage a list' => [['usage' => [1, 2]], "The response's 'usage' is not an object."],
    'input tokens a string' => [['usage' => ['input_tokens' => '57']], "The response's 'usage.input_tokens' is not a non-negative integer."],
    'input tokens negative' => [['usage' => ['input_tokens' => -1]], "The response's 'usage.input_tokens' is not a non-negative integer."],
    'output tokens a float' => [['usage' => ['output_tokens' => 1.5]], "The response's 'usage.output_tokens' is not a non-negative integer."],
]);

/** @param string $root the response's root fields beside `answers`, as raw JSON members, so `12.0` stays a float */
function metaFromJson(string $root): Meta
{
    return (new SystemOneCodec)->decode(
        churnDecision(),
        new Response(200, '{"answers": {"churn": {"type": "noul", "noul": 0.5}}, '.$root.'}'),
    )->meta;
}

it('reads a whole-number token count written with a fraction, which is the same JSON number', function (string $usage, int $input, int $output): void {
    $meta = metaFromJson('"usage": '.$usage);

    expect($meta->inputTokens)->toBe($input)
        ->and($meta->outputTokens)->toBe($output);
})->with([
    'zero fraction' => ['{"input_tokens": 12.0, "output_tokens": 0.0}', 12, 0],
    'exponent' => ['{"input_tokens": 1e2, "output_tokens": 3E0}', 100, 3],
    'negative zero' => ['{"input_tokens": -0.0, "output_tokens": 1}', 0, 1],
]);

it('still refuses token counts that are not whole non-negative numbers', function (string $usage, string $message): void {
    expect(fn () => metaFromJson('"usage": '.$usage))->toThrow(MalformedResponse::class, $message);
})->with([
    'fractional' => ['{"input_tokens": 12.5}', "The response's 'usage.input_tokens' is not a non-negative integer."],
    'negative whole float' => ['{"input_tokens": -1.0}', "The response's 'usage.input_tokens' is not a non-negative integer."],
    'one past the largest int' => ['{"output_tokens": 9223372036854775808}', "The response's 'usage.output_tokens' is not a non-negative integer."],
    'beyond an int' => ['{"output_tokens": 1e19}', "The response's 'usage.output_tokens' is not a non-negative integer."],
    'infinite' => ['{"output_tokens": 1e400}', "The response's 'usage.output_tokens' is not a non-negative integer."],
]);

it('has no latency when decode() is not given one', function (): void {
    expect(metaFrom(['model' => 'laya-rl-agent'])->latencyMs)->toBeNull();
});

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

it('sends a model between the state and the request controls when one is given', function (): void {
    $body = (new SystemOneCodec)->encode(churnDecision()->withMaxLength(64), 'english');

    expect(array_keys($body))->toBe(['state', 'model', 'max_len', 'questions'])
        ->and($body['model'] ?? null)->toBe('english');
});

it('sends no model when none is given, so the server routes', function (): void {
    expect((new SystemOneCodec)->encode(churnDecision()))->not->toHaveKey('model')
        ->and((new SystemOneCodec)->encode(churnDecision(), null))->not->toHaveKey('model');
});

it('reads the checkpoint revisions from a health response, ordered by checkpoint', function (): void {
    $response = okResponse(['status' => 'ok', 'loaded' => ['multilingual', 'english'], 'revisions' => ['multilingual' => 'bbb', 'english' => 'aaa']]);

    expect((new SystemOneCodec)->decodeRevisions($response))->toBe(['english' => 'aaa', 'multilingual' => 'bbb'])
        ->and(SystemOneCodec::HEALTH_PATH)->toBe('/health');
});

it('reads a checkpoint without a revision as null, and no loaded checkpoint as no revisions', function (): void {
    expect((new SystemOneCodec)->decodeRevisions(okResponse(['revisions' => ['english' => null]])))->toBe(['english' => null])
        ->and((new SystemOneCodec)->decodeRevisions(new Response(200, '{"revisions":{}}')))->toBe([]);
});

it('reads a revisions object whose checkpoint names are whole numbers from zero, which PHP would make a list', function (): void {
    expect((new SystemOneCodec)->decodeRevisions(new Response(200, '{"revisions":{"0":"a","1":"b"}}')))->toBe([0 => 'a', 1 => 'b']);
});

it('raises the decision errors for a health response with an error status', function (int $status, string $exception): void {
    expect(fn () => (new SystemOneCodec)->decodeRevisions(new Response($status, '{"detail":"nope"}')))
        ->toThrow($exception, 'nope');
})->with([
    [503, HostUnavailable::class],
    [401, RejectedByModel::class],
    [404, RejectedByModel::class],
]);

it('raises MalformedResponse for a health response without usable revisions', function (int $status, string $body, string $message): void {
    expect(fn () => (new SystemOneCodec)->decodeRevisions(new Response($status, $body)))
        ->toThrow(MalformedResponse::class, $message);
})->with([
    'redirect' => [302, '{}', 'Unexpected HTTP status 302.'],
    'not json' => [200, '<html>', 'The health response is not a JSON object.'],
    'a list' => [200, '[1]', 'The health response is not a JSON object.'],
    'no revisions' => [200, '{"status":"ok"}', "The health response has no 'revisions' object."],
    'revisions a list' => [200, '{"revisions":["abc"]}', "The health response has no 'revisions' object."],
    'revisions a string' => [200, '{"revisions":"abc"}', "The health response has no 'revisions' object."],
    'a numeric revision' => [200, '{"revisions":{"english":7}}', "The health response's revision for 'english' is not a string."],
]);
