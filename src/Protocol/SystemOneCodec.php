<?php

declare(strict_types=1);

namespace EarthlingOllie\SystemOneClassification\Protocol;

use Closure;
use EarthlingOllie\SystemOneClassification\Domain\Decision;
use EarthlingOllie\SystemOneClassification\Domain\Question\QuestionHandle;
use EarthlingOllie\SystemOneClassification\Domain\Question\YesNoQuestion;
use EarthlingOllie\SystemOneClassification\Exceptions\HostUnavailable;
use EarthlingOllie\SystemOneClassification\Exceptions\InvalidDecision;
use EarthlingOllie\SystemOneClassification\Exceptions\MalformedResponse;
use EarthlingOllie\SystemOneClassification\Exceptions\RejectedByModel;
use EarthlingOllie\SystemOneClassification\Result\Abstention;
use EarthlingOllie\SystemOneClassification\Result\Answer\Answer;
use EarthlingOllie\SystemOneClassification\Result\Answer\YesNoAnswer;
use EarthlingOllie\SystemOneClassification\Result\Meta;
use EarthlingOllie\SystemOneClassification\Result\Result;
use EarthlingOllie\SystemOneClassification\Transport\Response;

use function array_filter;
use function array_is_list;
use function is_array;
use function is_bool;
use function is_float;
use function is_int;
use function is_string;
use function json_decode;
use function json_encode;

/**
 * Maps a Decision to the System One wire format (`POST /v1/systemone`, shared by laya-serve
 * and Jev) and a response back to a Result.
 *
 * Each question type has one arm in wire(), which gives both its request and its decoder.
 */
final readonly class SystemOneCodec
{
    public const string PATH = '/v1/systemone';

    /**
     * @return array<string, mixed> `state`, the request controls that are set, then `questions`
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

        return ['state' => $decision->context->state, ...self::controls($decision), 'questions' => $questions];
    }

    /**
     * @param  ?float  $latencyMs  the round trip's latency, measured by the caller, for the result's meta
     *
     * @throws HostUnavailable on a 5xx
     * @throws RejectedByModel on a 4xx
     * @throws MalformedResponse when the status or body is not what the protocol defines
     * @throws InvalidDecision for a decision encode() refuses, so never for one that was sent
     */
    public function decode(Decision $decision, Response $response, ?float $latencyMs = null): Result
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

        if (! is_array($body) || array_is_list($body)) {
            throw MalformedResponse::because('The response is not a JSON object.', $response->body);
        }

        $answers = $body['answers'] ?? null;

        if (! is_array($answers) || array_is_list($answers)) {
            throw MalformedResponse::because('The response has no answers object.', $response->body);
        }

        $decoded = [];

        foreach ($decision->questions() as $question) {
            $answer = $answers[$question->key] ?? throw MalformedResponse::because("The response has no answer for '{$question->key}'.", $response->body);

            if (! is_array($answer)) {
                throw MalformedResponse::because("The answer for '{$question->key}' is not an object.", $response->body);
            }

            $decoded[$question->key] = ['question' => $question, 'answer' => self::wire($question)['decode']($answer, $response->body, $decision->minConfidence !== null)];
        }

        return new Result($decoded, self::decodeMeta($body, $response->body, $latencyMs));
    }

    /**
     * The request controls the decision sets; one left null is not sent, so the server's default applies.
     *
     * @return array<string, float|int|string>
     */
    private static function controls(Decision $decision): array
    {
        return array_filter([
            'min_confidence' => $decision->minConfidence,
            'lang' => $decision->language,
            'lang_guess' => $decision->languageHint,
            'max_len' => $decision->maxLength,
            'head_max_len' => $decision->headMaxLength,
        ], static fn (float|int|string|null $control): bool => $control !== null);
    }

    /**
     * The one dispatch on question type: what to send for a question and how to read its answer.
     *
     * encode() and decode() both go through here, so a type the codec knows has both halves and
     * any other type is refused by encode(), before DecisionClient sends anything.
     *
     * @param  QuestionHandle<Answer>  $question
     * @return array{request: array<string, mixed>, decode: Closure(array<mixed>, string, bool): Answer}
     *
     * @throws InvalidDecision
     */
    private static function wire(QuestionHandle $question): array
    {
        return match (true) {
            $question instanceof YesNoQuestion => [
                'request' => ['type' => 'noul', 'instructions' => $question->instructions],
                'decode' => static fn (array $answer, string $body, bool $gated): YesNoAnswer => self::decodeYesNo($question->key, $answer, $body, $gated),
            ],
            default => throw InvalidDecision::unsupportedQuestion($question),
        };
    }

    /**
     * @param  array<mixed>  $answer
     * @param  bool  $gated  whether the decision set a minimum confidence
     */
    private static function decodeYesNo(string $key, array $answer, string $body, bool $gated): YesNoAnswer
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

        return new YesNoAnswer((float) $probability, ...self::decodeAbstention($key, $answer, $body, $gated));
    }

    /**
     * The abstention gate's fields, which every answer type carries when the decision set a
     * minimum confidence: `abstention` (passed, abstained or unevaluated), `abstention_threshold`,
     * and `low_confidence: true` on abstained answers only.
     *
     * `abstention` decides. A server that sends only `low_confidence` (none of the recorded
     * ones do) reads as abstained when it is true. With neither field, the answer reads as
     * NotRequested when the decision set no minimum confidence, and as Unevaluated when it did:
     * a gate was asked for but the server gave no verdict, and a missing `low_confidence` is not
     * a pass (Laya also leaves it off answers it could not evaluate).
     *
     * @param  array<mixed>  $answer
     * @param  bool  $gated  whether the decision set a minimum confidence
     * @return array{abstention: Abstention, abstentionThreshold: int|float|null} the answer constructor's named arguments
     */
    private static function decodeAbstention(string $key, array $answer, string $body, bool $gated): array
    {
        $state = $answer['abstention'] ?? null;
        $lowConfidence = $answer['low_confidence'] ?? false;
        $threshold = $answer['abstention_threshold'] ?? null;

        if (! is_bool($lowConfidence)) {
            throw MalformedResponse::because("The answer for '{$key}' has a non-boolean 'low_confidence'.", $body);
        }

        $abstention = match ($state) {
            null => match (true) {
                $lowConfidence => Abstention::Abstained,
                $gated => Abstention::Unevaluated,
                default => Abstention::NotRequested,
            },
            'passed' => Abstention::Passed,
            'abstained' => Abstention::Abstained,
            'unevaluated' => Abstention::Unevaluated,
            default => throw MalformedResponse::because("The answer for '{$key}' has an unknown 'abstention' state: ".json_encode($state, JSON_THROW_ON_ERROR).'.', $body),
        };

        if ($threshold !== null && ! is_int($threshold) && ! is_float($threshold)) {
            throw MalformedResponse::because("The answer for '{$key}' has a non-numeric 'abstention_threshold'.", $body);
        }

        if ($threshold !== null && ($threshold < 0 || $threshold > 1)) {
            throw MalformedResponse::because("The answer for '{$key}' has an 'abstention_threshold' outside [0, 1]: {$threshold}.", $body);
        }

        return ['abstention' => $abstention, 'abstentionThreshold' => $threshold];
    }

    /**
     * The root `model`, `routing` and `usage` fields. Each is optional, since not every host
     * reports them, but one that is present must have the protocol's shape.
     *
     * @param  array<mixed>  $root
     */
    private static function decodeMeta(array $root, string $body, ?float $latencyMs): Meta
    {
        $routing = self::optionalObject($root, 'routing', 'routing', $body);
        $usage = self::optionalObject($root, 'usage', 'usage', $body);

        return new Meta(
            model: self::optionalString($root, 'model', 'model', $body),
            checkpoint: self::optionalString($routing, 'model', 'routing.model', $body),
            routingReason: self::optionalString($routing, 'reason', 'routing.reason', $body),
            inputTokens: self::optionalTokenCount($usage, 'input_tokens', $body),
            outputTokens: self::optionalTokenCount($usage, 'output_tokens', $body),
            latencyMs: $latencyMs,
        );
    }

    /**
     * @param  array<mixed>  $parent
     * @return array<mixed> empty when the field is absent or null
     */
    private static function optionalObject(array $parent, string $field, string $path, string $body): array
    {
        $value = $parent[$field] ?? [];

        // `{}` decodes to [], which array_is_list() also accepts.
        if (! is_array($value) || ($value !== [] && array_is_list($value))) {
            throw MalformedResponse::because("The response's '{$path}' is not an object.", $body);
        }

        return $value;
    }

    /**
     * @param  array<mixed>  $parent
     */
    private static function optionalString(array $parent, string $field, string $path, string $body): ?string
    {
        $value = $parent[$field] ?? null;

        if ($value !== null && ! is_string($value)) {
            throw MalformedResponse::because("The response's '{$path}' is not a string.", $body);
        }

        return $value;
    }

    /**
     * A whole number written with a fraction or exponent (`12.0`, `1e2`) is the same JSON number
     * as `12` or `100`, so it is read as that int. Any other value is still refused.
     *
     * @param  array<mixed>  $usage
     * @return ?non-negative-int
     */
    private static function optionalTokenCount(array $usage, string $field, string $body): ?int
    {
        $value = $usage[$field] ?? null;

        if (is_float($value)) {
            // A fraction, or a value no int can hold, doesn't survive the round trip.
            $whole = (int) $value;
            $value = (float) $whole === $value ? $whole : $value;
        }

        if ($value !== null && (! is_int($value) || $value < 0)) {
            throw MalformedResponse::because("The response's 'usage.{$field}' is not a non-negative integer.", $body);
        }

        return $value;
    }

    /** The `{"detail": "..."}` message laya-serve and Jev put on every error, if present. */
    private static function detail(mixed $body): ?string
    {
        $detail = is_array($body) ? ($body['detail'] ?? null) : null;

        return is_string($detail) ? $detail : null;
    }
}
