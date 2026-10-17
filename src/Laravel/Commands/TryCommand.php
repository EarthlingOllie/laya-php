<?php

declare(strict_types=1);

namespace EarthlingOllie\SystemOneClassification\Laravel\Commands;

use Closure;
use EarthlingOllie\SystemOneClassification\Domain\Context;
use EarthlingOllie\SystemOneClassification\Domain\Decision;
use EarthlingOllie\SystemOneClassification\Domain\Question;
use EarthlingOllie\SystemOneClassification\Domain\Question\YesNoQuestion;
use EarthlingOllie\SystemOneClassification\Exceptions\HostUnavailable;
use EarthlingOllie\SystemOneClassification\Exceptions\InvalidConfiguration;
use EarthlingOllie\SystemOneClassification\Exceptions\InvalidDecision;
use EarthlingOllie\SystemOneClassification\Exceptions\MalformedResponse;
use EarthlingOllie\SystemOneClassification\Exceptions\RejectedByModel;
use EarthlingOllie\SystemOneClassification\Laravel\SystemOneManager;
use EarthlingOllie\SystemOneClassification\Result\Answer\YesNoAnswer;
use EarthlingOllie\SystemOneClassification\Result\Result;
use Illuminate\Console\Command;

use function array_map;
use function file_get_contents;
use function fopen;
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
 * questions about some text from the terminal and prints each answer.
 *
 * The text comes from the argument, `--file`, or stdin (`cat ticket.txt | php artisan
 * system-one:try --yes-no=...`). Questions are keyed q1, q2, ... in the order given.
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

    public function handle(SystemOneManager $manager): int
    {
        // A bare `--yes-no` arrives as null: refused below like any blank question.
        $instructions = array_map(static fn (?string $value): string => $value ?? '', $this->option('yes-no'));
        $option = $this->option('connection');

        if ($instructions === []) {
            $this->problem('Ask at least one question, e.g. --yes-no="Does the customer threaten to cancel?"');

            return self::INVALID;
        }

        $text = $this->text();

        if ($text === null) {
            return self::INVALID;
        }

        try {
            $questions = [];

            foreach ($instructions as $i => $instruction) {
                $questions[] = Question::yesNo('q'.($i + 1), $instruction);
            }

            $decision = Decision::about(Context::text($text))->ask(...$questions);
            $name = is_string($option) ? $option : $manager->defaultConnection();
            $client = $manager->connection($name);
        } catch (InvalidDecision|InvalidConfiguration $invalid) {
            $this->problem($invalid->getMessage());

            return self::INVALID;
        }

        if (! $this->option('json')) {
            $this->line("Connection: {$name} ({$manager->displayUrl($name)})");
        }

        try {
            $result = $client->decide($decision);
        } catch (HostUnavailable|RejectedByModel|MalformedResponse $failed) {
            $this->problem($failed->getMessage());

            return self::FAILURE;
        }

        $this->option('json') ? $this->printJson($name, $questions, $result) : $this->printAnswers($questions, $result);

        return self::SUCCESS;
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

    /** @param list<YesNoQuestion> $questions */
    private function printAnswers(array $questions, Result $result): void
    {
        foreach ($questions as $question) {
            $answer = $result->get($question);
            $this->line("{$question->key} (yes/no): {$question->instructions}");
            $this->line('  value: '.($answer->isYes(self::THRESHOLD) ? 'yes' : 'no')." (probability {$this->number($answer->probability)}, threshold ".self::THRESHOLD.')');
            $this->line('  confidence: '.$this->number(self::confidence($answer)));
            $this->line("  distribution: yes {$this->number($answer->probability)}, no {$this->number(1 - $answer->probability)}");
        }
    }

    /** @param list<YesNoQuestion> $questions */
    private function printJson(string $connection, array $questions, Result $result): void
    {
        $answers = [];

        foreach ($questions as $question) {
            $answer = $result->get($question);
            $answers[$question->key] = [
                'type' => 'yes_no',
                'instructions' => $question->instructions,
                'value' => $answer->isYes(self::THRESHOLD),
                'probability' => $answer->probability,
                'confidence' => self::confidence($answer),
                'distribution' => ['yes' => $answer->probability, 'no' => round(1 - $answer->probability, 12)],
            ];
        }

        $this->line(json_encode(
            ['connection' => $connection, 'answers' => $answers],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        ));
    }

    /** The probability of the reported value, which is laya-serve's `answer_confidence` for a yes/no answer. */
    /** Prints $message as an error on stderr, so stdout carries only the answers (valid JSON under --json). */
    private function problem(string $message): void
    {
        $this->output->getErrorStyle()->writeln("<error>{$message}</error>");
    }

    private static function confidence(YesNoAnswer $answer): float
    {
        return round(max($answer->probability, 1 - $answer->probability), 12);
    }

    private function number(float $value): string
    {
        return (string) round($value, 4);
    }
}
