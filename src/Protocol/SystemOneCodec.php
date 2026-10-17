<?php

declare(strict_types=1);

namespace EarthlingOllie\SystemOneClassification\Protocol;

use BackedEnum;
use Closure;
use EarthlingOllie\SystemOneClassification\Domain\Decision;
use EarthlingOllie\SystemOneClassification\Domain\Question\ChoiceQuestion;
use EarthlingOllie\SystemOneClassification\Domain\Question\QuestionHandle;
use EarthlingOllie\SystemOneClassification\Domain\Question\YesNoQuestion;
use EarthlingOllie\SystemOneClassification\Exceptions\HostUnavailable;
use EarthlingOllie\SystemOneClassification\Exceptions\InvalidDecision;
use EarthlingOllie\SystemOneClassification\Exceptions\MalformedResponse;
use EarthlingOllie\SystemOneClassification\Exceptions\RejectedByModel;
use EarthlingOllie\SystemOneClassification\Result\Answer\Answer;
use EarthlingOllie\SystemOneClassification\Result\Answer\ChoiceAnswer;
use EarthlingOllie\SystemOneClassification\Result\Answer\YesNoAnswer;
use EarthlingOllie\SystemOneClassification\Result\Confidence;
use EarthlingOllie\SystemOneClassification\Result\Distribution;
use EarthlingOllie\SystemOneClassification\Result\Result;
use EarthlingOllie\SystemOneClassification\Transport\Response;
use stdClass;

use function array_diff;
use function array_is_list;
use function array_keys;
use function array_map;
use function implode;
use function is_array;
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
     * @param  string  $profile  the model profile whose raw `confidence` values this codec reads;
     *                           every decoded Confidence is tagged with it
     */
    public function __construct(private string $profile = 'laya') {}

    /**
     * @return array{state: string, questions: array<string, array<string, mixed>>} choice criteria are a stdClass, so they always encode as a JSON object
     *
     * @throws InvalidDecision
     */
    public function encode(Decision $decision): array
    {
        $questions = [];

        foreach ($decision->questions() as $question) {
            $questions[$question->key] = $this->wire($question)['request']();
        }

        if ($questions === []) {
            throw InvalidDecision::noQuestions();
        }

        return ['state' => $decision->context->text, 'questions' => $questions];
    }

    /**
     * @throws HostUnavailable on a 5xx
     * @throws RejectedByModel on a 4xx
     * @throws MalformedResponse when the status or body is not what the protocol defines
     * @throws InvalidDecision for a decision encode() refuses, so never for one that was sent
     */
    public function decode(Decision $decision, Response $response): Result
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

            $decoded[$question->key] = ['question' => $question, 'answer' => $this->wire($question)['decode']($answer, $response->body)];
        }

        return new Result($decoded);
    }

    /**
     * The one dispatch on question type: what to send for a question and how to read its answer.
     *
     * encode() and decode() both go through here, so a type the codec knows has both halves and
     * any other type is refused by encode(), before DecisionClient sends anything. Both halves
     * are closures, so each caller builds only the one it uses.
     *
     * @param  QuestionHandle<Answer>  $question
     * @return array{request: Closure(): array<string, mixed>, decode: Closure(array<mixed>, string): Answer}
     *
     * @throws InvalidDecision
     */
    private function wire(QuestionHandle $question): array
    {
        return match (true) {
            $question instanceof ChoiceQuestion => [
                'request' => static fn (): array => [
                    'type' => 'choice',
                    'instructions' => $question->instructions,
                    'criteria' => self::criteria($question),
                ],
                'decode' => fn (array $answer, string $body): ChoiceAnswer => $this->decodeChoice($question, $answer, $body),
            ],
            $question instanceof YesNoQuestion => [
                'request' => static fn (): array => ['type' => 'noul', 'instructions' => $question->instructions],
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

    /**
     * label => description (null for none), as a JSON object even when the labels are whole
     * numbers: PHP would hold `[0 => ..., 1 => ...]` as a list and send a JSON array.
     *
     * @param  ChoiceQuestion<BackedEnum>  $question
     */
    private static function criteria(ChoiceQuestion $question): stdClass
    {
        $criteria = new stdClass;

        foreach ($question->options() as ['case' => $case, 'description' => $description]) {
            $criteria->{$case->value} = $description;
        }

        return $criteria;
    }

    /**
     * @template TEnum of BackedEnum
     *
     * @param  ChoiceQuestion<TEnum>  $question
     * @param  array<mixed>  $answer
     * @return ChoiceAnswer<TEnum>
     */
    private function decodeChoice(ChoiceQuestion $question, array $answer, string $body): ChoiceAnswer
    {
        $key = $question->key;
        $type = $answer['type'] ?? null;

        if ($type !== 'choice') {
            throw MalformedResponse::because("The answer for '{$key}' should have type 'choice', got ".json_encode($type, JSON_THROW_ON_ERROR).'.', $body);
        }

        $choice = $answer['choice'] ?? null;

        if (! is_string($choice)) {
            throw MalformedResponse::because("The answer for '{$key}' has no string 'choice'.", $body);
        }

        // JSON object keys that are whole numbers come back from json_decode() as int keys, and
        // an object keyed "0", "1", ... as a list, so labels are matched as strings.
        $probabilities = $answer['probabilities'] ?? null;

        if (! is_array($probabilities)) {
            throw MalformedResponse::because("The answer for '{$key}' has no 'probabilities' object.", $body);
        }

        $chosen = null;
        $entries = [];
        $labels = [];

        foreach ($question->options() as ['case' => $case]) {
            $label = (string) $case->value;
            $labels[] = $label;
            $probability = $probabilities[$label] ?? null;

            if (! is_int($probability) && ! is_float($probability)) {
                throw MalformedResponse::because("The answer for '{$key}' has no numeric probability for '{$label}'.", $body);
            }

            if ($probability < 0 || $probability > 1) {
                throw MalformedResponse::because("The answer for '{$key}' has a probability for '{$label}' outside [0, 1]: {$probability}.", $body);
            }

            $entries[] = ['case' => $case, 'probability' => (float) $probability];

            if ($label === $choice) {
                $chosen = $case;
            }
        }

        // array_diff() compares as strings, so int keys match their whole-number labels.
        $extra = array_diff(array_keys($probabilities), $labels);

        if ($extra !== []) {
            $quoted = implode(', ', array_map(static fn (int|string $label): string => "'{$label}'", $extra));

            throw MalformedResponse::because("The answer for '{$key}' has probabilities for labels that are not options of {$question->enum}: {$quoted}.", $body);
        }

        if ($chosen === null) {
            throw MalformedResponse::because("The answer for '{$key}' names '{$choice}', which is not an option of {$question->enum}.", $body);
        }

        $confidence = $answer['confidence'] ?? null;

        if (! is_int($confidence) && ! is_float($confidence)) {
            throw MalformedResponse::because("The answer for '{$key}' has no numeric 'confidence'.", $body);
        }

        $distribution = new Distribution($entries);

        return new ChoiceAnswer(
            $chosen,
            self::answerConfidence($key, $answer, $distribution->probabilityOf($chosen), $body),
            new Confidence((float) $confidence, $this->profile),
            $distribution,
        );
    }

    /**
     * The server's `answer_confidence` (laya-serve, possibly calibrated), or, when it sends none
     * (Jev, or LAYA_JEV_STRICT), the probability of the reported answer, which is what it means.
     *
     * @param  array<mixed>  $answer
     */
    private static function answerConfidence(string $key, array $answer, float $fallback, string $body): float
    {
        $value = $answer['answer_confidence'] ?? null;

        if ($value === null) {
            return $fallback;
        }

        if (! is_int($value) && ! is_float($value)) {
            throw MalformedResponse::because("The answer for '{$key}' has no numeric 'answer_confidence'.", $body);
        }

        if ($value < 0 || $value > 1) {
            throw MalformedResponse::because("The answer for '{$key}' has a 'answer_confidence' outside [0, 1]: {$value}.", $body);
        }

        return (float) $value;
    }

    /** The `{"detail": "..."}` message laya-serve and Jev put on every error, if present. */
    private static function detail(mixed $body): ?string
    {
        $detail = is_array($body) ? ($body['detail'] ?? null) : null;

        return is_string($detail) ? $detail : null;
    }
}
