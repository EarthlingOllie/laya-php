<?php

declare(strict_types=1);

use EarthlingOllie\SystemOneClassification\Domain\Decision;
use EarthlingOllie\SystemOneClassification\Domain\Question;
use EarthlingOllie\SystemOneClassification\Laravel\Commands\TryCommand;
use EarthlingOllie\SystemOneClassification\Laravel\Facades\SystemOne;
use EarthlingOllie\SystemOneClassification\Laravel\Testing\FakeAnswer;
use Illuminate\Console\Command;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Console\Tester\CommandTester;

/** @param array<string, float> $probabilities keyed by question key */
function fakeTryAnswers(array $probabilities): void
{
    $answers = [];

    foreach ($probabilities as $key => $probability) {
        $answers[$key] = ['type' => 'noul', 'noul' => $probability, 'confidence' => 0.5, 'answer_confidence' => 0.5];
    }

    Http::fake(['*' => Http::response(['model' => 'laya-rl-agent', 'answers' => $answers, 'routing' => ['model' => 'english', 'reason' => 'English']])]);
}

/**
 * Runs the command with $stdin as what it reads when no text is given, as
 * `echo text | php artisan system-one:try` does.
 */
function tryWithStdin(string $stdin, array $arguments, ?Closure $isTerminal = null): CommandTester
{
    $path = (string) tempnam(sys_get_temp_dir(), 'system-one-stdin-');
    file_put_contents($path, $stdin);
    $command = new TryCommand($path, $isTerminal);
    $command->setLaravel(app());
    $tester = new CommandTester($command);

    try {
        $tester->execute($arguments, ['interactive' => false]);
    } finally {
        unlink($path);
    }

    return $tester;
}

it('asks a yes/no question about the text argument and prints value, confidence and distribution', function (): void {
    config(['system-one.connections.local.url' => 'http://laya.test:8000']);
    fakeTryAnswers(['q1' => 0.91]);

    $this->artisan('system-one:try', ['text' => 'We will cancel.', '--yes-no' => ['Does the customer threaten to cancel?']])
        ->expectsOutput('Connection: local (http://laya.test:8000)')
        ->expectsOutput('q1 (yes/no): Does the customer threaten to cancel?')
        ->expectsOutput('  value: yes (probability 0.91, threshold 0.5)')
        ->expectsOutput('  confidence: 0.91')
        ->expectsOutput('  distribution: yes 0.91, no 0.09')
        ->assertExitCode(Command::SUCCESS);

    Http::assertSent(fn (Request $request): bool => $request->url() === 'http://laya.test:8000/v1/systemone'
        && $request->data() === ['state' => 'We will cancel.', 'questions' => ['q1' => ['type' => 'noul', 'instructions' => 'Does the customer threaten to cancel?']]]);
});

it('asks every --yes-no question in one decision, keyed q1, q2, ...', function (): void {
    fakeTryAnswers(['q1' => 0.2, 'q2' => 0.5]);

    $this->artisan('system-one:try', ['text' => 'x', '--yes-no' => ['Cancel?', 'Refund?']])
        ->expectsOutput('q1 (yes/no): Cancel?')
        ->expectsOutput('  value: no (probability 0.2, threshold 0.5)')
        ->expectsOutput('  confidence: 0.8')
        ->expectsOutput('  distribution: yes 0.2, no 0.8')
        ->expectsOutput('q2 (yes/no): Refund?')
        ->expectsOutput('  value: yes (probability 0.5, threshold 0.5)')
        ->assertExitCode(Command::SUCCESS);

    Http::assertSentCount(1);
});

it('prints the answers as JSON with --json', function (): void {
    fakeTryAnswers(['q1' => 0.91]);

    $this->artisan('system-one:try', ['text' => 'x', '--yes-no' => ['Cancel?'], '--json' => true])
        ->expectsOutput(json_encode([
            'connection' => 'local',
            'routing' => ['model' => 'laya-rl-agent', 'checkpoint' => 'english', 'reason' => 'English', 'input_tokens' => null, 'output_tokens' => null],
            'answers' => [
                'q1' => [
                    'type' => 'yes_no',
                    'instructions' => 'Cancel?',
                    'value' => true,
                    'probability' => 0.91,
                    'confidence' => 0.91,
                    'distribution' => ['yes' => 0.91, 'no' => 0.09],
                ],
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR))
        ->doesntExpectOutputToContain('Connection:')
        ->assertExitCode(Command::SUCCESS);
});

it('rounds what it prints to 4 decimals and the JSON to 12, keeping slashes and accents as they are', function (): void {
    fakeTryAnswers(['q1' => 0.1234567890123]);
    $question = 'Résilier / quitter ?';

    $this->artisan('system-one:try', ['text' => 'x', '--yes-no' => [$question]])
        ->expectsOutput('q1 (yes/no): Résilier / quitter ?')
        ->expectsOutput('  value: no (probability 0.1235, threshold 0.5)')
        ->expectsOutput('  confidence: 0.8765')
        ->expectsOutput('  distribution: yes 0.1235, no 0.8765')
        ->assertExitCode(Command::SUCCESS);

    $this->artisan('system-one:try', ['text' => 'x', '--yes-no' => [$question], '--json' => true])
        // One write holds the whole document, and each expectation consumes the write it matches.
        ->expectsOutputToContain(<<<'JSON'
                        "instructions": "Résilier / quitter ?",
                        "value": false,
                        "probability": 0.1234567890123,
                        "confidence": 0.876543210988,
                        "distribution": {
                            "yes": 0.1234567890123,
                            "no": 0.876543210988
                        }
            JSON)
        ->assertExitCode(Command::SUCCESS);
});

it('sends to the connection named by --connection', function (): void {
    config(['system-one.connections.staging' => ['url' => 'https://staging.test', 'api_key' => 'sk-1']]);
    fakeTryAnswers(['q1' => 0.1]);

    $this->artisan('system-one:try', ['text' => 'x', '--yes-no' => ['Cancel?'], '--connection' => 'staging'])
        ->expectsOutput('Connection: staging (https://staging.test)')
        ->assertExitCode(Command::SUCCESS);

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://staging.test/v1/systemone'
        && $request->hasHeader('Authorization', 'Bearer sk-1'));
});

it('reads the text from --file', function (): void {
    $file = tempnam(sys_get_temp_dir(), 'system-one-try-');
    file_put_contents($file, "Please refund me.\n");
    fakeTryAnswers(['q1' => 0.7]);

    try {
        $this->artisan('system-one:try', ['--file' => $file, '--yes-no' => ['Refund?']])->assertExitCode(Command::SUCCESS);
    } finally {
        unlink($file);
    }

    Http::assertSent(fn (Request $request): bool => $request->data()['state'] === "Please refund me.\n");
});

it('reads the text from stdin when neither the argument nor --file is given', function (): void {
    fakeTryAnswers(['q1' => 0.7]);

    $tester = tryWithStdin('Piped ticket text', ['--yes-no' => ['Refund?']]);

    expect($tester->getStatusCode())->toBe(Command::SUCCESS)
        ->and($tester->getDisplay())->toContain('q1 (yes/no): Refund?');
    Http::assertSent(fn (Request $request): bool => $request->data()['state'] === 'Piped ticket text');
});

it('exits with 2 when stdin is empty and no text is given', function (): void {
    Http::fake();

    $tester = tryWithStdin('', ['--yes-no' => ['Refund?']]);

    expect($tester->getStatusCode())->toBe(Command::INVALID)
        ->and($tester->getDisplay())->toContain('The context text must not be empty.');
    Http::assertNothingSent();
});

it('exits with 2 instead of waiting for input when no text is given and stdin is a terminal', function (): void {
    Http::fake();
    $checked = null;

    $tester = tryWithStdin('never read', ['--yes-no' => ['Refund?']], function (mixed $stdin) use (&$checked): bool {
        $checked = $stdin;

        return true;
    });

    expect($tester->getStatusCode())->toBe(Command::INVALID)
        ->and($tester->getDisplay())->toContain('No text to ask about: give it as an argument, with --file, or on stdin (cat ticket.txt | php artisan system-one:try ...).')
        ->and(is_resource($checked))->toBeTrue();
    Http::assertNothingSent();
});

it('exits with 2 when stdin cannot be opened', function (): void {
    Http::fake();
    $command = new TryCommand('/nonexistent/system-one-stdin');
    $command->setLaravel(app());
    $tester = new CommandTester($command);

    $tester->execute(['--yes-no' => ['Refund?']], ['interactive' => false]);

    expect($tester->getStatusCode())->toBe(Command::INVALID)
        ->and($tester->getDisplay())->toContain('Cannot read the text from stdin.');
    Http::assertNothingSent();
});

it('exits with 2 for input it cannot use, before sending anything', function (array $arguments, string $message): void {
    Http::fake();

    $this->artisan('system-one:try', $arguments)
        ->expectsOutputToContain($message)
        ->assertExitCode(Command::INVALID);

    Http::assertNothingSent();
})->with([
    'no question' => [['text' => 'x'], 'Ask at least one question, e.g. --yes-no="Does the customer threaten to cancel?"'],
    'text and --file' => [['text' => 'x', '--file' => '/tmp/x', '--yes-no' => ['Q?']], 'Give the text as an argument or with --file, not both.'],
    'missing file' => [['--file' => '/nonexistent/system-one.txt', '--yes-no' => ['Q?']], 'Cannot read --file /nonexistent/system-one.txt.'],
    'a directory' => [['--file' => sys_get_temp_dir(), '--yes-no' => ['Q?']], 'Cannot read --file '.sys_get_temp_dir().'.'],
    'blank text' => [['text' => '   ', '--yes-no' => ['Q?']], 'The context text must not be empty.'],
    'blank question' => [['text' => 'x', '--yes-no' => ['']], "Question 'q1' needs instructions."],
    'bare --yes-no' => [['text' => 'x', '--yes-no' => [null]], "Question 'q1' needs instructions."],
    'unknown connection' => [['text' => 'x', '--yes-no' => ['Q?'], '--connection' => 'nope'], "System One connection 'nope' is not configured"],
]);

it('exits with 1 when the server cannot be reached, refuses the decision or answers nonsense', function (Closure $fake, string $message): void {
    $fake();

    $this->artisan('system-one:try', ['text' => 'x', '--yes-no' => ['Q?']])
        ->expectsOutputToContain($message)
        ->assertExitCode(Command::FAILURE);
})->with([
    'unreachable' => [fn () => Http::fake(['*' => Http::failedConnection('refused')]), 'could not be reached after 3 attempts: refused'],
    'server error' => [fn () => Http::fake(['*' => Http::response(['detail' => 'server busy'], 503)]), 'server busy'],
    'rejected' => [fn () => Http::fake(['*' => Http::response(['detail' => 'too many options'], 413)]), 'too many options'],
    'malformed' => [fn () => Http::fake(['*' => Http::response('<html>')]), 'The response is not a JSON object.'],
]);

it('writes errors to stderr, leaving stdout to the answers', function (array $arguments, string $message, int $exitCode): void {
    Http::fake(['*' => Http::failedConnection('refused')]);
    $command = new TryCommand;
    $command->setLaravel(app());
    $tester = new CommandTester($command);

    $tester->execute([...$arguments, '--json' => true], ['interactive' => false, 'capture_stderr_separately' => true]);

    expect($tester->getStatusCode())->toBe($exitCode)
        ->and($tester->getDisplay())->toBe('')
        ->and($tester->getErrorOutput())->toContain($message);
})->with([
    'unreachable' => [['text' => 'x', '--yes-no' => ['Q?']], 'could not be reached after 3 attempts: refused', Command::FAILURE],
    'no question' => [['text' => 'x'], 'Ask at least one question', Command::INVALID],
    'unknown connection' => [['text' => 'x', '--yes-no' => ['Q?'], '--connection' => 'nope'], "System One connection 'nope' is not configured", Command::INVALID],
]);

it('is registered as system-one:try', function (): void {
    expect(app(Kernel::class)->all()['system-one:try'])->toBeInstanceOf(TryCommand::class);
});

/*
 * Choice and score questions (#4, #5) and routing (#7's meta). Their options come from an enum
 * of the app, named before the instructions: --choice="App\Enums\Department:Which department?".
 */

const TRY_DEPARTMENT = 'EarthlingOllie\\SystemOneClassification\\Tests\\Fixtures\\Enums\\Department';
const TRY_URGENCY = 'EarthlingOllie\\SystemOneClassification\\Tests\\Fixtures\\Enums\\Urgency';

/** Answers q1 yes/no, q2 choice over Department, q3 score over Urgency, with the given routing. */
function fakeMixedAnswers(?array $routing = ['model' => 'english', 'reason' => 'English Latin text']): void
{
    Http::fake(['*' => Http::response([
        'model' => 'laya-rl-agent',
        'answers' => [
            'q1' => ['type' => 'noul', 'noul' => 0.2],
            'q2' => ['type' => 'choice', 'choice' => 'technical', 'confidence' => 0.3, 'answer_confidence' => 0.7, 'probabilities' => ['billing' => 0.2, 'technical' => 0.7, 'other' => 0.1]],
            'q3' => ['type' => 'score', 'score' => 2.1, 'confidence' => 0.4, 'legend' => ['can wait', 'soon', 'today', 'blocking'], 'probabilities' => ['0' => 0.05, '1' => 0.15, '2' => 0.4, '3' => 0.4]],
        ],
        ...($routing === null ? [] : ['routing' => $routing]),
        'usage' => ['input_tokens' => 80, 'output_tokens' => 0],
    ])]);
}

it('asks choice and score questions over the named enums, after the yes/no ones, and prints the routing', function (): void {
    fakeMixedAnswers();

    $this->artisan('system-one:try', [
        'text' => 'The app crashes.',
        '--yes-no' => ['Cancel?'],
        '--choice' => [TRY_DEPARTMENT.':Which department?'],
        '--score' => [TRY_URGENCY.':How urgent?'],
    ])
        ->expectsOutput('Routing: model laya-rl-agent, checkpoint english (English Latin text), 80 input tokens')
        ->expectsOutput('q1 (yes/no): Cancel?')
        ->expectsOutput('q2 (choice over Department): Which department?')
        ->expectsOutput('  value: technical (Department::Technical)')
        ->expectsOutput('  confidence: 0.7')
        ->expectsOutput('  distribution: billing 0.2, technical 0.7, other 0.1')
        ->expectsOutput('q3 (score over Urgency): How urgent?')
        ->expectsOutput('  value: 2 (Urgency::High), expected 2.15')
        ->expectsOutput('  confidence: 0.4')
        ->expectsOutput('  distribution: 0 0.05, 1 0.15, 2 0.4, 3 0.4')
        ->assertExitCode(Command::SUCCESS);

    Http::assertSent(fn (Request $request): bool => array_keys((array) $request['questions']) === ['q1', 'q2', 'q3']
        && $request['questions']['q2']['type'] === 'choice'
        && $request['questions']['q3']['criteria'] === ['can wait', 'soon', 'today', 'blocking']);
});

it('says when the server reports no routing', function (): void {
    fakeMixedAnswers(routing: null);

    $this->artisan('system-one:try', ['text' => 'x', '--yes-no' => ['Cancel?']])
        ->expectsOutput('Routing: model laya-rl-agent, 80 input tokens')
        ->assertExitCode(Command::SUCCESS);
});

it('prints choice and score answers and the routing as JSON', function (): void {
    fakeMixedAnswers();
    $command = new TryCommand;
    $command->setLaravel(app());
    $tester = new CommandTester($command);

    $tester->execute(['text' => 'x', '--yes-no' => ['Cancel?'], '--choice' => [TRY_DEPARTMENT.':Which department?'], '--score' => [TRY_URGENCY.':How urgent?'], '--json' => true]);
    $printed = json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);

    expect($tester->getStatusCode())->toBe(Command::SUCCESS)
        ->and($printed['routing'])->toBe(['model' => 'laya-rl-agent', 'checkpoint' => 'english', 'reason' => 'English Latin text', 'input_tokens' => 80, 'output_tokens' => 0])
        ->and($printed['answers']['q2'])->toBe([
            'type' => 'choice',
            'enum' => TRY_DEPARTMENT,
            'instructions' => 'Which department?',
            'value' => 'technical',
            'case' => 'Technical',
            'confidence' => 0.7,
            'distribution' => ['billing' => 0.2, 'technical' => 0.7, 'other' => 0.1],
        ])
        ->and($printed['answers']['q3'])->toBe([
            'type' => 'score',
            'enum' => TRY_URGENCY,
            'instructions' => 'How urgent?',
            'value' => 2,
            'case' => 'High',
            'expected' => 2.15,
            'confidence' => 0.4,
            'distribution' => [0.05, 0.15, 0.4, 0.4],
        ])
        // The levels stay JSON object keys, so a reader sees which level each probability is for.
        ->and($tester->getDisplay())->toContain('"distribution": {
                "0": 0.05,');
});

it('refuses a --choice or --score that does not name a backed enum, sending nothing', function (array $arguments, string $message): void {
    Http::fake();

    $this->artisan('system-one:try', ['text' => 'x', ...$arguments])
        ->expectsOutputToContain($message)
        ->assertExitCode(Command::INVALID);

    Http::assertNothingSent();
})->with([
    'no enum' => [['--choice' => ['Which department?']], "--choice needs an enum class before a colon, as in --choice=\"App\\Enums\\Department:Which department?\"; got 'Which department?'."],
    'an unknown class' => [['--score' => ['App\\Nope:How urgent?']], "--score needs an enum class before a colon, as in --score=\"App\\Enums\\Urgency:How urgent is this?\"; got 'App\\Nope:How urgent?'."],
    'blank instructions' => [['--choice' => [TRY_DEPARTMENT.':  ']], "Question 'q1' needs instructions."],
    'a string-backed score' => [['--score' => [TRY_DEPARTMENT.':How urgent?']], 'Department'],
]);

it('keeps a colon in the instructions of a --choice', function (): void {
    fakeMixedAnswers();

    $this->artisan('system-one:try', ['text' => 'x', '--yes-no' => ['Cancel?'], '--choice' => [TRY_DEPARTMENT.':Department: which one?']])
        ->expectsOutput('q2 (choice over Department): Department: which one?')
        ->assertExitCode(Command::SUCCESS);
});

it('names only the option at fault when a --choice names no enum', function (): void {
    Http::fake();
    $command = new TryCommand;
    $command->setLaravel(app());
    $tester = new CommandTester($command);

    $tester->execute(['text' => 'x', '--choice' => ['Which department?']], ['interactive' => false, 'capture_stderr_separately' => true]);

    expect($tester->getStatusCode())->toBe(Command::INVALID)
        ->and(trim($tester->getErrorOutput()))->toBe("--choice needs an enum class before a colon, as in --choice=\"App\\Enums\\Department:Which department?\"; got 'Which department?'.");
});

it('rounds a score\'s expected level in the JSON to 12 decimals', function (): void {
    Http::fake(['*' => Http::response(['answers' => ['q1' => [
        'type' => 'score', 'score' => 1.9, 'confidence' => 0.1, 'legend' => ['can wait', 'soon', 'today', 'blocking'],
        // Not normalised (the server rounds each probability), so the mean is 1.7 / 0.9.
        'probabilities' => ['0' => 0.1, '1' => 0.2, '2' => 0.3, '3' => 0.3],
    ]]])]);
    $command = new TryCommand;
    $command->setLaravel(app());
    $tester = new CommandTester($command);

    $tester->execute(['text' => 'x', '--score' => [TRY_URGENCY.':How urgent?'], '--json' => true]);

    expect(json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR)['answers']['q1']['expected'])->toBe(1.888888888889);
});

// Review finding: encode() refuses some decisions only once decide() runs (profile limits, text
// that is not UTF-8); those are input the command cannot use, so exit 2 with nothing sent.
it('exits with 2 for a decision the connection refuses before sending', function (array $arguments, string $message): void {
    Http::fake();
    $command = new TryCommand;
    $command->setLaravel(app());
    $tester = new CommandTester($command);

    $tester->execute(['text' => 'x', ...$arguments], ['interactive' => false, 'capture_stderr_separately' => true]);

    expect($tester->getStatusCode())->toBe(Command::INVALID)
        ->and($tester->getErrorOutput())->toContain($message);
    Http::assertNothingSent();
})->with([
    'text that is not UTF-8' => [['text' => "caf\xE9", '--yes-no' => ['Cancel?']], 'The context text is not valid UTF-8'],
    'a choice over more options than the profile allows' => [['--choice' => ['Workbench\\App\\Enums\\Topic:Which topic?']], "Choice question 'q1' has 101 options"],
]);

// Review finding: injected code is fakeable only through ConnectionResolver (#10).
it('answers from SystemOne::fake() in an app test, which then sees the decision', function (): void {
    Http::fake();
    $fake = SystemOne::fake([FakeAnswer::yesNo(Question::yesNo('q1', 'Cancel?'), 0.9)]);

    $this->artisan('system-one:try', ['text' => 'We will cancel.', '--yes-no' => ['Cancel?']])
        ->expectsOutput('  value: yes (probability 0.9, threshold 0.5)')
        ->assertExitCode(Command::SUCCESS);

    $fake->assertDecided(fn (Decision $decision): bool => $decision->context->state === 'We will cancel.');
    Http::assertNothingSent();
});

it('prints only the routing the server reports', function (array $root, string $routing): void {
    Http::fake(['*' => Http::response(['answers' => ['q1' => ['type' => 'noul', 'noul' => 0.2]], ...$root])]);

    $this->artisan('system-one:try', ['text' => 'x', '--yes-no' => ['Cancel?']])
        ->expectsOutput("Routing: {$routing}")
        ->assertExitCode(Command::SUCCESS);
})->with([
    'a checkpoint without a reason' => [['routing' => ['model' => 'english']], 'checkpoint english'],
    'nothing' => [[], 'not reported'],
]);

it('asks for a question, and says only that, when none is given', function (): void {
    Http::fake();
    $command = new TryCommand;
    $command->setLaravel(app());
    $tester = new CommandTester($command);

    $tester->execute(['text' => 'x'], ['interactive' => false, 'capture_stderr_separately' => true]);

    expect($tester->getStatusCode())->toBe(Command::INVALID)
        ->and(trim($tester->getErrorOutput()))->toBe('Ask at least one question, e.g. --yes-no="Does the customer threaten to cancel?"');
    Http::assertNothingSent();
});
