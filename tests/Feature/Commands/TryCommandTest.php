<?php

declare(strict_types=1);

use EarthlingOllie\SystemOneClassification\Laravel\Commands\TryCommand;
use Illuminate\Console\Command;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Console\Tester\CommandTester;

/** @param array<string, float> $probabilities keyed by question key */
function fakeAnswers(array $probabilities): void
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
    fakeAnswers(['q1' => 0.91]);

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
    fakeAnswers(['q1' => 0.2, 'q2' => 0.5]);

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
    fakeAnswers(['q1' => 0.91]);

    $this->artisan('system-one:try', ['text' => 'x', '--yes-no' => ['Cancel?'], '--json' => true])
        ->expectsOutput(json_encode([
            'connection' => 'local',
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
    fakeAnswers(['q1' => 0.1234567890123]);
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
    fakeAnswers(['q1' => 0.1]);

    $this->artisan('system-one:try', ['text' => 'x', '--yes-no' => ['Cancel?'], '--connection' => 'staging'])
        ->expectsOutput('Connection: staging (https://staging.test)')
        ->assertExitCode(Command::SUCCESS);

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://staging.test/v1/systemone'
        && $request->hasHeader('Authorization', 'Bearer sk-1'));
});

it('reads the text from --file', function (): void {
    $file = tempnam(sys_get_temp_dir(), 'system-one-try-');
    file_put_contents($file, "Please refund me.\n");
    fakeAnswers(['q1' => 0.7]);

    try {
        $this->artisan('system-one:try', ['--file' => $file, '--yes-no' => ['Refund?']])->assertExitCode(Command::SUCCESS);
    } finally {
        unlink($file);
    }

    Http::assertSent(fn (Request $request): bool => $request->data()['state'] === "Please refund me.\n");
});

it('reads the text from stdin when neither the argument nor --file is given', function (): void {
    fakeAnswers(['q1' => 0.7]);

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
    'unreachable' => [fn () => Http::fake(['*' => Http::failedConnection('refused')]), 'could not be reached: refused'],
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
    'unreachable' => [['text' => 'x', '--yes-no' => ['Q?']], 'could not be reached: refused', Command::FAILURE],
    'no question' => [['text' => 'x'], 'Ask at least one question', Command::INVALID],
    'unknown connection' => [['text' => 'x', '--yes-no' => ['Q?'], '--connection' => 'nope'], "System One connection 'nope' is not configured", Command::INVALID],
]);

it('is registered as system-one:try', function (): void {
    expect(app(Kernel::class)->all()['system-one:try'])->toBeInstanceOf(TryCommand::class);
});
