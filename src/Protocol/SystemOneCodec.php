<?php

declare(strict_types=1);

namespace EarthlingOllie\SystemOneClassification\Protocol;

use BackedEnum;
use Closure;
use EarthlingOllie\SystemOneClassification\Domain\Decision;
use EarthlingOllie\SystemOneClassification\Domain\Question\ChoiceQuestion;
use EarthlingOllie\SystemOneClassification\Domain\Question\QuestionHandle;
use EarthlingOllie\SystemOneClassification\Domain\Question\ScoreQuestion;
use EarthlingOllie\SystemOneClassification\Domain\Question\YesNoQuestion;
use EarthlingOllie\SystemOneClassification\Exceptions\HostUnavailable;
use EarthlingOllie\SystemOneClassification\Exceptions\InvalidDecision;
use EarthlingOllie\SystemOneClassification\Exceptions\MalformedResponse;
use EarthlingOllie\SystemOneClassification\Exceptions\RejectedByModel;
use EarthlingOllie\SystemOneClassification\Result\Answer\Answer;
use EarthlingOllie\SystemOneClassification\Result\Answer\ChoiceAnswer;
use EarthlingOllie\SystemOneClassification\Result\Answer\ScoreAnswer;
use EarthlingOllie\SystemOneClassification\Result\Answer\YesNoAnswer;
use EarthlingOllie\SystemOneClassification\Result\Confidence;
use EarthlingOllie\SystemOneClassification\Result\Distribution;
use EarthlingOllie\SystemOneClassification\Result\Result;
use EarthlingOllie\SystemOneClassification\Transport\Response;
use stdClass;

use function array_column;
use function array_diff;
use function array_is_list;
use function array_keys;
use function array_map;
use function count;
use function implode;
use function is_array;
use function is_float;
use function is_int;
use function is_string;
use function json_decode;
use function json_encode;
use function ksort;

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
            $question instanceof ScoreQuestion => [
                'request' => static fn (): array => [
                    'type' => 'score',
                    'instructions' => $question->instructions,
                    'criteria' => $question->criteria(),
                ],
                'decode' => fn (array $answer, string $body): ScoreAnswer => $this->decodeScore($question, $answer, $body),
            ],
            $question instanceof YesNoQuestion => [
                'request' => static fn (): array => ['type' => 'noul', 'instructions' => $question->instructions],
                'decode' => static fn (array $answer, string $body): YesNoAnswer => self::decodeYesNo($question->key, $answer, $body),
            ],
            default => throw InvalidDecision::unsupportedQuestion($question),
        };
    }

    /**
     * The answer's `type` must be the one the question was sent as.
     *
     * @param  array<mixed>  $answer
     */
    private static function checkType(string $key, array $answer, string $type, string $body): void
    {
        $got = $answer['type'] ?? null;

        if ($got !== $type) {
            throw MalformedResponse::because("The answer for '{$key}' should have type '{$type}', got ".json_encode($got, JSON_THROW_ON_ERROR).'.', $body);
        }
    }

    /**
     * @param  array<mixed>  $answer
     */
    private static function decodeYesNo(string $key, array $answer, string $body): YesNoAnswer
    {
        self::checkType($key, $answer, 'noul', $body);

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
        self::checkType($key, $answer, 'choice', $body);

        $choice = $answer['choice'] ?? null;

        if (! is_string($choice)) {
            throw MalformedResponse::because("The answer for '{$key}' has no string 'choice'.", $body);
        }

        $labelled = [];
        $chosen = null;

        foreach ($question->options() as ['case' => $case]) {
            $label = (string) $case->value;
            $labelled[] = ['label' => $label, 'case' => $case, 'shown' => "'{$label}'"];

            if ($label === $choice) {
                $chosen = $case;
            }
        }

        $distribution = self::distribution($key, $answer, $labelled, "options of {$question->enum}", $body);

        if ($chosen === null) {
            throw MalformedResponse::because("The answer for '{$key}' names '{$choice}', which is not an option of {$question->enum}.", $body);
        }

        // The raw confidence first, so a response wrong in both says so about it, as it always has.
        $confidence = $this->confidence($key, $answer, $body);

        return new ChoiceAnswer(
            $chosen,
            self::answerConfidence($key, $answer, $distribution->probabilityOf($chosen), $body),
            $confidence,
            $distribution,
        );
    }

    /**
     * @template TEnum of BackedEnum
     *
     * @param  ScoreQuestion<TEnum>  $question
     * @param  array<mixed>  $answer
     * @return ScoreAnswer<TEnum>
     */
    private function decodeScore(ScoreQuestion $question, array $answer, string $body): ScoreAnswer
    {
        $key = $question->key;
        self::checkType($key, $answer, 'score', $body);

        // The server knows the levels only by index, lowest first: criteria went out as a
        // list, and legend and probabilities come back keyed "0", "1", ... (int keys here).
        // Its `score` is the expected index; ScoreAnswer takes the expected level over the
        // backing values from the probabilities instead, so the score is only checked.
        $levels = $question->levels();
        $highestIndex = count($levels) - 1;
        $score = $answer['score'] ?? null;

        if (! is_int($score) && ! is_float($score)) {
            throw MalformedResponse::because("The answer for '{$key}' has no numeric 'score'.", $body);
        }

        if ($score < 0 || $score > $highestIndex) {
            throw MalformedResponse::because("The answer for '{$key}' has a 'score' outside the level indexes 0 to {$highestIndex}: {$score}.", $body);
        }

        self::checkLegend($question, $answer, $body);

        $labelled = [];

        foreach ($levels as $index => ['case' => $case]) {
            // The index is not the backing value unless the levels are 0..n-1: name the case too.
            $labelled[] = ['label' => $index, 'case' => $case, 'shown' => "'{$index}' (".$case::class."::{$case->name})"];
        }

        $distribution = self::distribution($key, $answer, $labelled, "levels of {$question->enum}", $body);
        $value = null;
        $highest = 0.0;

        // The server sends no most likely level for a score, only probabilities rounded to 4
        // places, so the lowest of levels equally likely after rounding wins (as np.argmax
        // picks the first). A level with probability 0 is never the most likely one, so all
        // zeros leave no value.
        foreach ($distribution->entries() as ['case' => $case, 'probability' => $probability]) {
            if ($probability > $highest) {
                $value = $case;
                $highest = $probability;
            }
        }

        if ($value === null) {
            throw MalformedResponse::because("The answer for '{$key}' gives no level a probability above 0.", $body);
        }

        $confidence = $this->confidence($key, $answer, $body);

        return new ScoreAnswer(
            $value,
            self::answerConfidence($key, $answer, $highest, $body),
            $confidence,
            $distribution,
        );
    }

    /**
     * The legend must name every level, by index, with the description that was sent: a
     * mismatch means the server scored some other scale.
     *
     * @template TEnum of BackedEnum
     *
     * @param  ScoreQuestion<TEnum>  $question
     * @param  array<mixed>  $answer
     */
    private static function checkLegend(ScoreQuestion $question, array $answer, string $body): void
    {
        $legend = $answer['legend'] ?? null;

        if (! is_array($legend)) {
            throw MalformedResponse::because("The answer for '{$question->key}' has no 'legend' object.", $body);
        }

        $expected = $question->criteria();
        $sorted = $legend;
        ksort($sorted);

        if ($sorted !== $expected) {
            $json = static fn (array $legend): string => json_encode($legend, JSON_THROW_ON_ERROR | JSON_FORCE_OBJECT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

            throw MalformedResponse::because("The answer for '{$question->key}' has a legend that does not match the levels of {$question->enum}: expected {$json($expected)}, got {$json($legend)}.", $body);
        }
    }

    /**
     * The answer's `probabilities`, one per label, as a Distribution in the order given.
     *
     * JSON object keys that are whole numbers come back from json_decode() as int keys, and an
     * object keyed "0", "1", ... as a list; PHP looks a whole-number string label up as that
     * int key, so a label may be either.
     *
     * @template TEnum of BackedEnum
     *
     * @param  array<mixed>  $answer
     * @param  non-empty-list<array{label: int|string, case: TEnum, shown: string}>  $labelled  each
     *                                                                                          label with its case and how a message names it
     * @param  string  $labels  what the labels are, for the message ("options of Department")
     * @return Distribution<TEnum>
     */
    private static function distribution(string $key, array $answer, array $labelled, string $labels, string $body): Distribution
    {
        $probabilities = $answer['probabilities'] ?? null;

        if (! is_array($probabilities)) {
            throw MalformedResponse::because("The answer for '{$key}' has no 'probabilities' object.", $body);
        }

        $entries = [];

        foreach ($labelled as ['label' => $label, 'case' => $case, 'shown' => $shown]) {
            $probability = $probabilities[$label] ?? null;

            if (! is_int($probability) && ! is_float($probability)) {
                throw MalformedResponse::because("The answer for '{$key}' has no numeric probability for {$shown}.", $body);
            }

            if ($probability < 0 || $probability > 1) {
                throw MalformedResponse::because("The answer for '{$key}' has a probability for {$shown} outside [0, 1]: {$probability}.", $body);
            }

            $entries[] = ['case' => $case, 'probability' => (float) $probability];
        }

        // array_diff() compares as strings, so int keys match their whole-number labels.
        $extra = array_diff(array_keys($probabilities), array_column($labelled, 'label'));

        if ($extra !== []) {
            $quoted = implode(', ', array_map(static fn (int|string $label): string => "'{$label}'", $extra));

            throw MalformedResponse::because("The answer for '{$key}' has probabilities for labels that are not {$labels}: {$quoted}.", $body);
        }

        return new Distribution($entries);
    }

    /**
     * The model's raw `confidence`, tagged with this codec's profile.
     *
     * @param  array<mixed>  $answer
     */
    private function confidence(string $key, array $answer, string $body): Confidence
    {
        $confidence = $answer['confidence'] ?? null;

        if (! is_int($confidence) && ! is_float($confidence)) {
            throw MalformedResponse::because("The answer for '{$key}' has no numeric 'confidence'.", $body);
        }

        return new Confidence((float) $confidence, $this->profile);
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
