<?php

declare(strict_types=1);

namespace EarthlingOllie\SystemOneClassification\Protocol;

use Closure;
use EarthlingOllie\SystemOneClassification\Domain\Context;
use EarthlingOllie\SystemOneClassification\Domain\Decision;
use EarthlingOllie\SystemOneClassification\Domain\Question\QuestionHandle;
use EarthlingOllie\SystemOneClassification\Domain\Question\YesNoQuestion;
use EarthlingOllie\SystemOneClassification\Exceptions\HostUnavailable;
use EarthlingOllie\SystemOneClassification\Exceptions\InvalidDecision;
use EarthlingOllie\SystemOneClassification\Exceptions\MalformedResponse;
use EarthlingOllie\SystemOneClassification\Exceptions\RejectedByModel;
use EarthlingOllie\SystemOneClassification\Result\Answer\Answer;
use EarthlingOllie\SystemOneClassification\Result\Answer\YesNoAnswer;
use EarthlingOllie\SystemOneClassification\Result\Result;
use EarthlingOllie\SystemOneClassification\Result\ResultSet;
use EarthlingOllie\SystemOneClassification\Result\Usage;
use EarthlingOllie\SystemOneClassification\Transport\Response;

use function array_is_list;
use function array_map;
use function count;
use function is_array;
use function is_float;
use function is_int;
use function is_string;
use function json_decode;
use function json_encode;
use function strlen;

/**
 * Maps a Decision to the System One wire format (`POST /v1/systemone`, shared by laya-serve
 * and Jev) and a response back to a Result; and a Decision asked about many contexts to the
 * batch endpoint (`POST /v1/systemone/batch`) and its responses back to a ResultSet.
 *
 * Each question type has one arm in wire(), which gives both its request and its decoder.
 */
final readonly class SystemOneCodec
{
    public const string PATH = '/v1/systemone';

    public const string BATCH_PATH = '/v1/systemone/batch';

    /**
     * The most states one batch request may carry: laya-serve's MAX_BATCH_STATES, a fixed
     * constant (not configurable) above which it answers 413 "too many states in batch
     * (65 > 64)". encodeBatches() splits longer batches into several requests.
     */
    public const int MAX_BATCH_STATES = 64;

    /**
     * The largest request body laya-serve reads: its MAX_BODY_BYTES (2 MiB), above which it
     * answers 413 before parsing. 64 states under its 50,000-character limit can still add up
     * to more, so encodeBatches() also splits a batch whose body would not fit.
     */
    public const int MAX_BATCH_BODY_BYTES = 2 * 1024 * 1024;

    /**
     * @return array{state: string, questions: array<string, array<string, mixed>>}
     *
     * @throws InvalidDecision
     */
    public function encode(Decision $decision): array
    {
        $questions = [];

        foreach ($decision->questions() as $question) {
            $questions[$question->key] = self::wire($question)['request'];
        }

        if ($questions === []) {
            throw InvalidDecision::noQuestions();
        }

        return ['state' => $decision->context->text, 'questions' => $questions];
    }

    /**
     * The batch requests that ask the decision's questions about each context, in order. A
     * request takes as many of the next contexts as fit both MAX_BATCH_STATES and, encoded as
     * the HTTP client sends it, MAX_BATCH_BODY_BYTES; a context too large to fit even alone
     * goes in a request of its own, for the server to refuse as decide() would. Each body is
     * what encode() builds for the decision, with its `state` replaced by the `states` of its
     * contexts, so decide() and decideMany() send the questions and every request setting the
     * same way.
     *
     * Every request is encoded before any is returned, so a batch with one bad context sends
     * nothing.
     *
     * @param  array<mixed>  $contexts  a list of Context
     * @return non-empty-list<array{states: non-empty-list<string>, questions: array<string, array<string, mixed>>}>
     *
     * @throws InvalidDecision when there are no contexts, they are not a list, one is not a
     *                         Context, or encode() refuses the decision
     */
    public function encodeBatches(Decision $decision, array $contexts): array
    {
        if ($contexts === []) {
            throw InvalidDecision::noContexts();
        }

        if (! array_is_list($contexts)) {
            throw InvalidDecision::contextsNotList();
        }

        $shared = $this->encode($decision);
        unset($shared['state']);
        $states = [];

        foreach ($contexts as $position => $context) {
            if (! $context instanceof Context) {
                throw InvalidDecision::notAContext($position, $context);
            }

            $states[] = $this->encode($decision->withContext($context))['state'];
        }

        return array_map(
            static fn (array $chunk): array => ['states' => $chunk, ...$shared],
            self::chunk($states, self::jsonBytes(['states' => [], ...$shared])),
        );
    }

    /**
     * @throws HostUnavailable on a 5xx
     * @throws RejectedByModel on a 4xx
     * @throws MalformedResponse when the status or body is not what the protocol defines
     * @throws InvalidDecision for a decision encode() refuses, so never for one that was sent
     */
    public function decode(Decision $decision, Response $response): Result
    {
        return self::result($decision, self::successBody($response), $response->body);
    }

    /**
     * Reads one batch response: a Result per state sent, in order, and the response's
     * `total_usage` (null when the server reports none).
     *
     * @param  int  $states  how many states the request sent
     *
     * @throws HostUnavailable on a 5xx
     * @throws RejectedByModel on a 4xx
     * @throws MalformedResponse when the status or body is not what the protocol defines,
     *                           or there is not exactly one result per state
     * @throws InvalidDecision for a decision encode() refuses, so never for one that was sent
     */
    public function decodeBatch(Decision $decision, int $states, Response $response): ResultSet
    {
        $body = self::successBody($response);
        $results = $body['results'] ?? null;

        if (! is_array($results) || ! array_is_list($results)) {
            throw MalformedResponse::because('The batch response has no results list.', $response->body);
        }

        if (count($results) !== $states) {
            throw MalformedResponse::because('The batch response has '.count($results)." results for {$states} states.", $response->body);
        }

        $decoded = [];

        foreach ($results as $position => $payload) {
            try {
                $decoded[] = self::result($decision, self::object($payload, $response->body), $response->body);
            } catch (MalformedResponse $malformed) {
                throw MalformedResponse::because("Result {$position} of the batch response: {$malformed->getMessage()}", $response->body);
            }
        }

        return new ResultSet($decoded, self::usage($body['total_usage'] ?? null, $response->body));
    }

    /**
     * Splits the states, in order, into chunks that fit one batch request each.
     *
     * @param  non-empty-list<string>  $states
     * @param  int  $overhead  the bytes of the body around its `states` list
     * @return non-empty-list<non-empty-list<string>>
     */
    private static function chunk(array $states, int $overhead): array
    {
        $chunks = [];
        $chunk = [];
        $bytes = $overhead;

        foreach ($states as $state) {
            $size = self::jsonBytes($state);

            // One more state also adds the comma before it.
            if ($chunk !== [] && (count($chunk) === self::MAX_BATCH_STATES || $bytes + 1 + $size > self::MAX_BATCH_BODY_BYTES)) {
                $chunks[] = $chunk;
                $chunk = [];
                $bytes = $overhead;
            }

            $bytes += ($chunk === [] ? 0 : 1) + $size;
            $chunk[] = $state;
        }

        $chunks[] = $chunk;

        return $chunks;
    }

    /**
     * The bytes a value takes in a request body. HttpTransport's client encodes with
     * json_encode()'s default flags, so this is the size it sends; those flags escape slashes
     * and non-ASCII characters, so no other choice of flags would send more.
     */
    private static function jsonBytes(mixed $value): int
    {
        return strlen(json_encode($value, JSON_THROW_ON_ERROR));
    }

    /**
     * The JSON object of a 2xx response, or the typed error any other status stands for.
     *
     * @return array<mixed>
     */
    private static function successBody(Response $response): array
    {
        $body = json_decode($response->body, true);
        $status = $response->status;

        if ($status >= 500) {
            throw HostUnavailable::serverError($status, self::detail($body));
        }

        if ($status >= 400) {
            throw RejectedByModel::withStatus($status, self::detail($body));
        }

        if ($status < 200 || $status >= 300) {
            throw MalformedResponse::because("Unexpected HTTP status {$status}.", $response->body);
        }

        return self::object($body, $response->body);
    }

    /**
     * @return array<mixed>
     */
    private static function object(mixed $value, string $body): array
    {
        if (! is_array($value) || array_is_list($value)) {
            throw MalformedResponse::because('The response is not a JSON object.', $body);
        }

        return $value;
    }

    /**
     * The Result in one single-call payload (`{answers, ...}`): the whole body of a decide()
     * response, or one entry of a batch response's `results`.
     *
     * @param  array<mixed>  $payload
     */
    private static function result(Decision $decision, array $payload, string $body): Result
    {
        $answers = $payload['answers'] ?? null;

        if (! is_array($answers) || array_is_list($answers)) {
            throw MalformedResponse::because('The response has no answers object.', $body);
        }

        $decoded = [];

        foreach ($decision->questions() as $question) {
            $answer = $answers[$question->key] ?? throw MalformedResponse::because("The response has no answer for '{$question->key}'.", $body);

            if (! is_array($answer)) {
                throw MalformedResponse::because("The answer for '{$question->key}' is not an object.", $body);
            }

            $decoded[$question->key] = ['question' => $question, 'answer' => self::wire($question)['decode']($answer, $body)];
        }

        return new Result($decoded);
    }

    /** A `{input_tokens, output_tokens, ...}` usage object, or null when the server sent none. */
    private static function usage(mixed $usage, string $body): ?Usage
    {
        if ($usage === null) {
            return null;
        }

        $input = is_array($usage) ? ($usage['input_tokens'] ?? null) : null;
        $output = is_array($usage) ? ($usage['output_tokens'] ?? null) : null;

        if (! is_int($input) || ! is_int($output) || $input < 0 || $output < 0) {
            throw MalformedResponse::because('The batch response has a total_usage without non-negative integer input_tokens and output_tokens.', $body);
        }

        return new Usage($input, $output);
    }

    /**
     * The one dispatch on question type: what to send for a question and how to read its answer.
     *
     * encode() and decode() both go through here, so a type the codec knows has both halves and
     * any other type is refused by encode(), before DecisionClient sends anything.
     *
     * @param  QuestionHandle<Answer>  $question
     * @return array{request: array<string, mixed>, decode: Closure(array<mixed>, string): Answer}
     *
     * @throws InvalidDecision
     */
    private static function wire(QuestionHandle $question): array
    {
        return match (true) {
            $question instanceof YesNoQuestion => [
                'request' => ['type' => 'noul', 'instructions' => $question->instructions],
                'decode' => static fn (array $answer, string $body): YesNoAnswer => self::decodeYesNo($question->key, $answer, $body),
            ],
            default => throw InvalidDecision::unsupportedQuestion($question),
        };
    }

    /**
     * @param  array<mixed>  $answer
     */
    private static function decodeYesNo(string $key, array $answer, string $body): YesNoAnswer
    {
        $type = $answer['type'] ?? null;

        if ($type !== 'noul') {
            throw MalformedResponse::because("The answer for '{$key}' should have type 'noul', got ".json_encode($type, JSON_THROW_ON_ERROR).'.', $body);
        }

        $probability = $answer['noul'] ?? null;

        if (! is_int($probability) && ! is_float($probability)) {
            throw MalformedResponse::because("The answer for '{$key}' has no numeric 'noul' probability.", $body);
        }

        if ($probability < 0 || $probability > 1) {
            throw MalformedResponse::because("The answer for '{$key}' has a 'noul' probability outside [0, 1]: {$probability}.", $body);
        }

        return new YesNoAnswer((float) $probability);
    }

    /** The `{"detail": "..."}` message laya-serve and Jev put on every error, if present. */
    private static function detail(mixed $body): ?string
    {
        $detail = is_array($body) ? ($body['detail'] ?? null) : null;

        return is_string($detail) ? $detail : null;
    }
}
