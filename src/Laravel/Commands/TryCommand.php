<?php

declare(strict_types=1);

namespace EarthlingOllie\SystemOneClassification\Laravel\Commands;

use BackedEnum;
use Closure;
use EarthlingOllie\SystemOneClassification\Domain\Context;
use EarthlingOllie\SystemOneClassification\Domain\Decision;
use EarthlingOllie\SystemOneClassification\Domain\Question;
use EarthlingOllie\SystemOneClassification\Domain\Question\QuestionHandle;
use EarthlingOllie\SystemOneClassification\Domain\Question\ScoreQuestion;
use EarthlingOllie\SystemOneClassification\Exceptions\HostUnavailable;
use EarthlingOllie\SystemOneClassification\Exceptions\InvalidConfiguration;
use EarthlingOllie\SystemOneClassification\Exceptions\InvalidDecision;
use EarthlingOllie\SystemOneClassification\Exceptions\MalformedResponse;
use EarthlingOllie\SystemOneClassification\Exceptions\RejectedByModel;
use EarthlingOllie\SystemOneClassification\Laravel\ConnectionResolver;
use EarthlingOllie\SystemOneClassification\Laravel\SystemOneManager;
use EarthlingOllie\SystemOneClassification\Result\Answer\Answer;
use EarthlingOllie\SystemOneClassification\Result\Answer\ChoiceAnswer;
use EarthlingOllie\SystemOneClassification\Result\Answer\ScoreAnswer;
use EarthlingOllie\SystemOneClassification\Result\Answer\YesNoAnswer;
use EarthlingOllie\SystemOneClassification\Result\Meta;
use EarthlingOllie\SystemOneClassification\Result\Result;
use Illuminate\Console\Command;
use ReflectionClass;

use function array_map;
use function count;
use function enum_exists;
use function explode;
use function file_get_contents;
use function fopen;
use function implode;
use function is_file;
use function is_readable;
use function is_string;
use function json_encode;
use function max;
use function round;
use function stream_get_contents;
use function stream_isatty;

/**
 * `php artisan system-one:try "text" --yes-no="Does the customer threaten to cancel?"`: asks
 * questions about some text from the terminal and prints each answer and how the server
 * routed the decision.
 *
 * Choice and score questions take their options from a backed enum of the app, as they do in
 * code, named before the instructions: `--choice="App\Enums\Department:Which department?"`,
 * `--score="App\Enums\Urgency:How urgent is this?"`.
 *
 * The text comes from the argument, `--file`, or stdin (`cat ticket.txt | php artisan
 * system-one:try --yes-no=...`). Questions are keyed q1, q2, ...: the yes/no ones in the order
 * given, then the choices, then the scores.
 *
 * Exit codes: 0 answered, 1 the server could not be reached, refused the decision or answered
 * something unreadable, 2 the input or the connection config cannot be used (nothing is sent).
 */
final class TryCommand extends Command
{
    /** Where isYes() puts the line between yes and no. */
    private const float THRESHOLD = 0.5;

    /** @var string */
    protected $signature = 'system-one:try
        {text? : The text to ask about (default: --file, or stdin)}
        {--file= : Read the text from this file}
        {--yes-no=* : A yes/no question, as its instructions; repeat for more}
        {--choice=* : A choice question, as Enum\\Class:instructions; repeat for more}
        {--score=* : A score question, as Enum\\Class:instructions (an int-backed enum); repeat for more}
        {--connection= : The connection to use (default: system-one.default)}
        {--json : Print the answers as JSON}';

    /** @var string */
    protected $description = 'Ask System One questions about some text and print the answers';

    /** @var Closure(resource): bool */
    private readonly Closure $isTerminal;

    /**
     * @param  string  $stdin  where the text is read from when neither the argument nor --file gives it
     * @param  (Closure(resource): bool)|null  $isTerminal  whether that stream is a terminal, where
     *                                                      reading would wait for the user (default: stream_isatty)
     */
    public function __construct(private readonly string $stdin = 'php://stdin', ?Closure $isTerminal = null)
    {
        parent::__construct();
        $this->isTerminal = $isTerminal ?? stream_isatty(...);
    }

    /**
     * @param  SystemOneManager  $manager  names the connection and its URL
     * @param  ConnectionResolver  $systemOne  decides, so an app test's SystemOne::fake() answers
     */
    public function handle(SystemOneManager $manager, ConnectionResolver $systemOne): int
    {
        // A bare `--yes-no` arrives as null: refused below like any blank question.
        $instructions = array_map(static fn (?string $value): string => $value ?? '', $this->option('yes-no'));
        $choices = $this->enumQuestions('choice', 'Department:Which department?');
        $scores = $this->enumQuestions('score', 'Urgency:How urgent is this?');
        $option = $this->option('connection');

        if ($choices === null || $scores === null) {
            return self::INVALID;
        }

        if ($instructions === [] && $choices === [] && $scores === []) {
            $this->problem('Ask at least one question, e.g. --yes-no="Does the customer threaten to cancel?"');

            return self::INVALID;
        }

        $text = $this->text();

        if ($text === null) {
            return self::INVALID;
        }

        try {
            $questions = [];

            foreach ($instructions as $instruction) {
                $questions[] = Question::yesNo('q'.(count($questions) + 1), $instruction);
            }

            foreach ($choices as [$enum, $instruction]) {
                $questions[] = Question::choice('q'.(count($questions) + 1), $enum, $instruction);
            }

            foreach ($scores as [$enum, $instruction]) {
                $questions[] = Question::score('q'.(count($questions) + 1), $enum, $instruction);
            }

            $decision = Decision::about(Context::text($text))->ask(...$questions);
            $name = is_string($option) ? $option : $manager->defaultConnection();
            $client = $systemOne->connection($name);
        } catch (InvalidDecision|InvalidConfiguration $invalid) {
            $this->problem($invalid->getMessage());

            return self::INVALID;
        }

        if (! $this->option('json')) {
            $this->line("Connection: {$name} ({$manager->displayUrl($name)})");
        }

        try {
            $result = $client->decide($decision);
        } catch (InvalidDecision $invalid) {
            // Refused by the connection before sending: a profile limit, or text that is not UTF-8.
            $this->problem($invalid->getMessage());

            return self::INVALID;
        } catch (HostUnavailable|RejectedByModel|MalformedResponse $failed) {
            $this->problem($failed->getMessage());

            return self::FAILURE;
        }

        if ($this->option('json')) {
            $this->printJson($name, $questions, $result);
        } else {
            $this->line('Routing: '.self::routing($result->meta));
            $this->printAnswers($questions, $result);
        }

        return self::SUCCESS;
    }

    /**
     * The --choice or --score options as [enum, instructions], or null (with the reason
     * printed) when one names no enum.
     *
     * @param  string  $example  what the option looks like, after the namespace, for the message
     * @return list<array{class-string<BackedEnum>, string}>|null
     */
    private function enumQuestions(string $option, string $example): ?array
    {
        $questions = [];
        /** @var list<?string> $options a bare --choice arrives as null */
        $options = $this->option($option);

        foreach ($options as $given) {
            $given ??= '';
            $parts = explode(':', $given, 2);
            [$enum, $instructions] = [$parts[0], $parts[1] ?? ''];

            // Question::choice() and ::score() refuse an enum that is not backed (or, for a
            // score, not int-backed), as they do in code.
            if (! enum_exists($enum)) {
                $this->problem("--{$option} needs an enum class before a colon, as in --{$option}=\"App\\Enums\\{$example}\"; got '{$given}'.");

                return null;
            }

            /** @var class-string<BackedEnum> $enum */
            $questions[] = [$enum, $instructions];
        }

        return $questions;
    }

    /** The text to ask about, or null (with the reason printed) when it cannot be had. */
    private function text(): ?string
    {
        $argument = $this->argument('text');
        $file = $this->option('file');

        if (is_string($argument) && is_string($file)) {
            $this->problem('Give the text as an argument or with --file, not both.');

            return null;
        }

        if (is_string($argument)) {
            return $argument;
        }

        if (is_string($file)) {
            if (! is_file($file) || ! is_readable($file)) {
                $this->problem("Cannot read --file {$file}.");

                return null;
            }

            return (string) file_get_contents($file);
        }

        $stdin = @fopen($this->stdin, 'rb');

        if ($stdin === false) {
            $this->problem('Cannot read the text from stdin.');

            return null;
        }

        // Nothing piped in: reading a terminal would hang until Ctrl-D with no hint why.
        if (($this->isTerminal)($stdin)) {
            $this->problem('No text to ask about: give it as an argument, with --file, or on stdin (cat ticket.txt | php artisan system-one:try ...).');

            return null;
        }

        return (string) stream_get_contents($stdin);
    }

    /** @param list<QuestionHandle<Answer>> $questions */
    private function printAnswers(array $questions, Result $result): void
    {
        foreach ($questions as $question) {
            $answer = $result->get($question);

            if ($answer instanceof YesNoAnswer) {
                $this->line("{$question->key} (yes/no): {$question->instructions}");
                $this->line('  value: '.($answer->isYes(self::THRESHOLD) ? 'yes' : 'no')." (probability {$this->number($answer->probability)}, threshold ".self::THRESHOLD.')');
                $this->line('  confidence: '.$this->number(self::confidence($answer)));
                $this->line("  distribution: yes {$this->number($answer->probability)}, no {$this->number(1 - $answer->probability)}");

                continue;
            }

            /** @var ChoiceAnswer<BackedEnum>|ScoreAnswer<BackedEnum> $answer */
            $enum = self::shortName($answer->value::class);
            $kind = $question instanceof ScoreQuestion ? 'score' : 'choice';
            $expected = $answer instanceof ScoreAnswer ? ", expected {$this->number($answer->expected)}" : '';
            $this->line("{$question->key} ({$kind} over {$enum}): {$question->instructions}");
            $this->line("  value: {$answer->value->value} ({$enum}::{$answer->value->name}){$expected}");
            $this->line("  confidence: {$this->number($answer->answerConfidence)}");
            $this->line('  distribution: '.implode(', ', array_map(
                fn (array $entry): string => "{$entry['case']->value} {$this->number($entry['probability'])}",
                $answer->distribution->entries(),
            )));
        }
    }

    /** @param list<QuestionHandle<Answer>> $questions */
    private function printJson(string $connection, array $questions, Result $result): void
    {
        $answers = [];

        foreach ($questions as $question) {
            $answer = $result->get($question);

            if ($answer instanceof YesNoAnswer) {
                $answers[$question->key] = [
                    'type' => 'yes_no',
                    'instructions' => $question->instructions,
                    'value' => $answer->isYes(self::THRESHOLD),
                    'probability' => $answer->probability,
                    'confidence' => self::confidence($answer),
                    'distribution' => ['yes' => $answer->probability, 'no' => round(1 - $answer->probability, 12)],
                ];

                continue;
            }

            /** @var ChoiceAnswer<BackedEnum>|ScoreAnswer<BackedEnum> $answer */
            $distribution = [];

            foreach ($answer->distribution->entries() as ['case' => $case, 'probability' => $probability]) {
                $distribution[$case->value] = $probability;
            }

            $answers[$question->key] = [
                'type' => $question instanceof ScoreQuestion ? 'score' : 'choice',
                'enum' => $answer->value::class,
                'instructions' => $question->instructions,
                'value' => $answer->value->value,
                'case' => $answer->value->name,
                ...($answer instanceof ScoreAnswer ? ['expected' => round($answer->expected, 12)] : []),
                'confidence' => $answer->answerConfidence,
                // An object, keyed by label or level: (object) keeps level keys 0, 1, ... as keys.
                'distribution' => (object) $distribution,
            ];
        }

        $meta = $result->meta;

        $this->line(json_encode(
            [
                'connection' => $connection,
                'routing' => ['model' => $meta->model, 'checkpoint' => $meta->checkpoint, 'reason' => $meta->routingReason, 'input_tokens' => $meta->inputTokens, 'output_tokens' => $meta->outputTokens],
                'answers' => $answers,
            ],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        ));
    }

    /** How the server says it routed the decision (#7's meta): what it reported of it. */
    private static function routing(Meta $meta): string
    {
        $parts = [];

        if ($meta->model !== null) {
            $parts[] = "model {$meta->model}";
        }

        if ($meta->checkpoint !== null) {
            $parts[] = "checkpoint {$meta->checkpoint}".($meta->routingReason === null ? '' : " ({$meta->routingReason})");
        }

        if ($meta->inputTokens !== null) {
            $parts[] = "{$meta->inputTokens} input tokens";
        }

        return $parts === [] ? 'not reported' : implode(', ', $parts);
    }

    /** @param class-string $class */
    private static function shortName(string $class): string
    {
        return new ReflectionClass($class)->getShortName();
    }

    /** Prints $message as an error on stderr, so stdout carries only the answers (valid JSON under --json). */
    private function problem(string $message): void
    {
        $this->output->getErrorStyle()->writeln("<error>{$message}</error>");
    }

    /** The probability of the reported value, which is laya-serve's `answer_confidence` for a yes/no answer. */
    private static function confidence(YesNoAnswer $answer): float
    {
        return round(max($answer->probability, 1 - $answer->probability), 12);
    }

    private function number(float $value): string
    {
        return (string) round($value, 4);
    }
}
