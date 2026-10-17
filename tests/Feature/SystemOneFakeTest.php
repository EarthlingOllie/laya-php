<?php

declare(strict_types=1);

use EarthlingOllie\SystemOneClassification\Client\Decider;
use EarthlingOllie\SystemOneClassification\Domain\Context;
use EarthlingOllie\SystemOneClassification\Domain\Decision;
use EarthlingOllie\SystemOneClassification\Domain\Question;
use EarthlingOllie\SystemOneClassification\Domain\Question\ChoiceQuestion;
use EarthlingOllie\SystemOneClassification\Domain\Question\ScoreQuestion;
use EarthlingOllie\SystemOneClassification\Domain\Question\YesNoQuestion;
use EarthlingOllie\SystemOneClassification\Exceptions\InvalidConfiguration;
use EarthlingOllie\SystemOneClassification\Exceptions\InvalidDecision;
use EarthlingOllie\SystemOneClassification\Exceptions\UnscriptedQuestion;
use EarthlingOllie\SystemOneClassification\Laravel\ConnectionResolver;
use EarthlingOllie\SystemOneClassification\Laravel\Facades\SystemOne;
use EarthlingOllie\SystemOneClassification\Laravel\SystemOneManager;
use EarthlingOllie\SystemOneClassification\Laravel\Testing\FakeAnswer;
use EarthlingOllie\SystemOneClassification\Laravel\Testing\SystemOneFake;
use EarthlingOllie\SystemOneClassification\Result\Answer\ChoiceAnswer;
use EarthlingOllie\SystemOneClassification\Result\Answer\ScoreAnswer;
use EarthlingOllie\SystemOneClassification\Result\Answer\YesNoAnswer;
use EarthlingOllie\SystemOneClassification\Result\Confidence;
use EarthlingOllie\SystemOneClassification\Tests\Fixtures\Enums\Department;
use EarthlingOllie\SystemOneClassification\Tests\Fixtures\Enums\TranslatedDepartment;
use EarthlingOllie\SystemOneClassification\Tests\Fixtures\Enums\Urgency;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\AssertionFailedError;

function fakeChurn(string $instructions = 'Does the customer threaten to cancel?'): YesNoQuestion
{
    return Question::yesNo('churn', $instructions);
}

/** @return ChoiceQuestion<Department> */
function fakeDepartmentQuestion(): ChoiceQuestion
{
    return Question::choice('department', Department::class, 'Which department should handle this?');
}

/** @return ScoreQuestion<Urgency> */
function fakeUrgencyQuestion(): ScoreQuestion
{
    return Question::score('urgency', Urgency::class, 'How urgent is this?');
}

function aboutTicket(string $text = 'We were billed twice and will cancel.'): Decision
{
    return Decision::about(Context::text($text));
}

/** Runs an assertion that should fail, and returns its message. */
function failureOf(Closure $assertion): string
{
    try {
        $assertion();
    } catch (AssertionFailedError $failure) {
        return $failure->getMessage();
    }

    throw new LogicException('The assertion passed.');
}

/** App code that injects the concrete manager. */
final readonly class ManagerConsumer
{
    public function __construct(public SystemOneManager $systemOne) {}
}

/** App code that injects the interface, as the docs advise. */
final readonly class ResolverConsumer
{
    public function __construct(public ConnectionResolver $systemOne) {}
}

beforeEach(function (): void {
    // Nothing the fake does may reach a server.
    Http::preventStrayRequests();
});

it('swaps a fake in behind the facade and the container', function (): void {
    $fake = SystemOne::fake();

    expect($fake)->toBeInstanceOf(SystemOneFake::class)
        ->and(SystemOne::getFacadeRoot())->toBe($fake)
        ->and(SystemOne::isFake())->toBeTrue()
        // Code that type-hints the interface gets the fake too.
        ->and(app(ConnectionResolver::class))->toBe($fake);
});

it('leaves code that asks for the final manager itself with the real one, not a TypeError', function (): void {
    $fake = SystemOne::fake();

    $concrete = app(ManagerConsumer::class);
    $injected = app(ResolverConsumer::class);

    expect($concrete->systemOne)->toBeInstanceOf(SystemOneManager::class)
        ->and($injected->systemOne)->toBe($fake);
});

it('needs an application behind the facades to read the connections from', function (): void {
    $app = SystemOne::getFacadeApplication();
    SystemOne::setFacadeApplication(null);

    try {
        expect(fn (): SystemOneFake => SystemOne::fake())
            ->toThrow(InvalidConfiguration::class, 'SystemOne::fake() needs a Laravel application behind the facades, to read config/system-one.php from.');
    } finally {
        SystemOne::setFacadeApplication($app);
    }
});

it('binds the real manager behind the interface when nothing is faked', function (): void {
    expect(app(ConnectionResolver::class))->toBe(app(SystemOneManager::class))
        ->and(app(ConnectionResolver::class))->toBeInstanceOf(SystemOneManager::class);
});

it('answers every question type from its script, without a server', function (): void {
    $churn = fakeChurn();
    $department = fakeDepartmentQuestion();
    $urgency = fakeUrgencyQuestion();
    SystemOne::fake([
        FakeAnswer::yesNo($churn, 0.91),
        FakeAnswer::choice($department, Department::Billing, confidence: 0.8),
        FakeAnswer::score($urgency, Urgency::High, expected: 1.7),
    ]);

    $result = SystemOne::decide(aboutTicket()->ask($churn, $department, $urgency));

    expect($result->get($churn))->toEqual(new YesNoAnswer(0.91))
        ->and($result->get($department))->toEqual(FakeAnswer::choice($department, Department::Billing, confidence: 0.8)->answerFor('laya'))
        ->and($result->get($department)->value)->toBe(Department::Billing)
        ->and($result->get($urgency)->value)->toBe(Urgency::High)
        ->and($result->get($urgency)->expected)->toEqualWithDelta(1.7, 1e-12);
});

it('reads a script with an equal handle built elsewhere', function (): void {
    SystemOne::fake([FakeAnswer::yesNo(fakeChurn(), 0.91)]);

    expect(SystemOne::decide(aboutTicket()->ask(fakeChurn()))->get(fakeChurn())->probability)->toBe(0.91);
});

it('takes the last script given for a question', function (): void {
    SystemOne::fake([FakeAnswer::yesNo(fakeChurn(), 0.1), FakeAnswer::yesNo(fakeChurn(), 0.9)]);

    expect(SystemOne::decide(aboutTicket()->ask(fakeChurn()))->get(fakeChurn())->probability)->toBe(0.9);
});

it('gives a question with no script a certain default: no, the first option, the lowest level', function (): void {
    $department = fakeDepartmentQuestion();
    $urgency = fakeUrgencyQuestion();
    SystemOne::fake();

    $result = SystemOne::decide(aboutTicket()->ask(fakeChurn(), $department, $urgency));

    expect($result->get(fakeChurn())->probability)->toBe(0.0)
        ->and($result->get($department)->value)->toBe(Department::Billing)
        ->and($result->get($department)->answerConfidence)->toBe(1.0)
        ->and($result->get($urgency)->value)->toBe(Urgency::Low)
        ->and($result->get($urgency)->expected)->toBe(0.0);
});

it('does not answer a question from the script of another question with the same key', function (): void {
    SystemOne::fake([FakeAnswer::yesNo(fakeChurn('Will they leave?'), 0.91)]);

    expect(SystemOne::decide(aboutTicket()->ask(fakeChurn()))->get(fakeChurn())->probability)->toBe(0.0);
});

it('throws for a question with no script when strict', function (): void {
    SystemOne::fake([FakeAnswer::yesNo(fakeChurn(), 0.91)])->strict();

    SystemOne::decide(aboutTicket()->ask(fakeChurn(), fakeDepartmentQuestion()));
})->throws(UnscriptedQuestion::class, "The fake has no script for the question 'department'. Script it with FakeAnswer, or leave the fake unstrict to get a default answer.");

it('names what differs when strict and the script with that key is for another question', function (): void {
    SystemOne::fake([FakeAnswer::yesNo(fakeChurn('Will they leave?'), 0.91)])->strict();

    SystemOne::decide(aboutTicket()->ask(fakeChurn()));
})->throws(UnscriptedQuestion::class, "The fake has no script for the question 'churn'. A script for 'churn' is for another question: it differs in its instructions. Script it with FakeAnswer, or leave the fake unstrict to get a default answer.");

it('names a different class when the script with that key is another question type', function (): void {
    $other = Question::choice('churn', Department::class, 'Which department?');
    SystemOne::fake([FakeAnswer::choice($other, Department::Billing)])->strict();

    SystemOne::decide(aboutTicket()->ask(fakeChurn()));
})->throws(UnscriptedQuestion::class, "A script for 'churn' is for another question: it differs in its class.");

it('refuses a decision the real client refuses, before recording it', function (): void {
    $fake = SystemOne::fake();

    expect(fn (): mixed => SystemOne::decide(aboutTicket()))->toThrow(InvalidDecision::class, 'The decision asks no questions; add one with ask().');
    $fake->assertNothingDecided();
});

it('answers on named connections, tagging raw confidence with their profile', function (): void {
    config(['system-one.connections.jev' => ['url' => 'https://jev.test', 'profile' => 'jev']]);
    $department = fakeDepartmentQuestion();
    $fake = SystemOne::fake([FakeAnswer::choice($department, Department::Technical, confidence: 0.6)]);

    $connection = SystemOne::connection('jev');
    $onJev = $connection->decide(aboutTicket()->ask($department))->get($department);
    $onDefault = SystemOne::decide(aboutTicket()->ask($department))->get($department);

    expect($connection)->toBeInstanceOf(Decider::class)
        ->and($onJev)->toBeInstanceOf(ChoiceAnswer::class)
        ->and($onJev->value)->toBe(Department::Technical)
        ->and($onJev->confidence->profile)->toBe('jev')
        ->and($onDefault->confidence->profile)->toBe('laya');
    $fake->assertDecided(fn (Decision $decision, string $connection): bool => $connection === 'jev');
    $fake->assertDecided(fn (Decision $decision, string $connection): bool => $connection === 'local');
});

it('tags with Laya when a connection names no profile, as the real client does', function (?string $profile): void {
    config(['system-one.connections.bare' => ['url' => 'http://bare.test', 'profile' => $profile]]);
    $urgency = fakeUrgencyQuestion();
    SystemOne::fake();

    $answer = SystemOne::connection('bare')->decide(aboutTicket()->ask($urgency))->get($urgency);

    expect($answer)->toBeInstanceOf(ScoreAnswer::class)
        ->and($answer->confidence)->toEqual(new Confidence(1.0, 'laya'));
})->with(['unset' => [null], 'empty' => ['']]);

it('answers on the configured default connection', function (): void {
    config(['system-one.default' => 'other', 'system-one.connections.other' => ['url' => 'http://other.test']]);
    $fake = SystemOne::fake();

    SystemOne::decide(aboutTicket()->ask(fakeChurn()));

    $fake->assertDecided(fn (Decision $decision, string $connection): bool => $connection === 'other');
    // Not the fake's assertions alone: Pest passes a test without assertions (failOnRisky is
    // not honoured), so a mutant that empties assertDecided() would slip through here.
    expect(failureOf(fn () => $fake->assertDecided(fn (Decision $decision, string $connection): bool => $connection === 'local')))
        ->toStartWith('No decision matching the callback was made (1 decision was made).');
});

it('refuses a connection that is not configured, as the real client does', function (): void {
    SystemOne::fake();

    SystemOne::connection('typo');
})->throws(InvalidConfiguration::class, "System One connection 'typo' is not configured in config/system-one.php.");

it('refuses a connection the real client would refuse, so tests catch broken config', function (array $connection, string $message): void {
    config(['system-one.connections.broken' => $connection]);
    SystemOne::fake();

    expect(fn (): mixed => SystemOne::connection('broken'))->toThrow(InvalidConfiguration::class, $message);
})->with([
    'a misspelt profile' => [['url' => 'http://x.test', 'profile' => 'jevv'], "System One connection 'broken' has a 'profile' that is not one of: laya, jev."],
    'a profile that is not a string' => [['url' => 'http://x.test', 'profile' => ['jev']], "System One connection 'broken' has a 'profile' that is not one of: laya, jev."],
    'no url' => [['profile' => 'jev'], "System One connection 'broken' needs a 'url'."],
    'a bad timeout' => [['url' => 'http://x.test', 'timeout' => -1], "System One connection 'broken' has a 'timeout' that is not a number of seconds."],
]);

it('refuses a missing default connection, as the real client does', function (): void {
    config(['system-one.default' => null]);
    SystemOne::fake();

    SystemOne::decide(aboutTicket()->ask(fakeChurn()));
})->throws(InvalidConfiguration::class);

it('asserts a decision matching a callback was made', function (): void {
    $fake = SystemOne::fake();
    SystemOne::decide(aboutTicket('first')->ask(fakeChurn()));
    SystemOne::decide(aboutTicket('second')->ask(fakeChurn()));

    $fake->assertDecided(fn (Decision $decision): bool => $decision->context->text === 'second');

    expect(failureOf(fn () => $fake->assertDecided(fn (Decision $decision): bool => $decision->context->text === 'third')))
        ->toStartWith('No decision matching the callback was made (2 decisions were made).');
});

it('asserts how many decisions were made', function (): void {
    $fake = SystemOne::fake();
    SystemOne::decide(aboutTicket()->ask(fakeChurn()));
    SystemOne::connection('local')->decide(aboutTicket()->ask(fakeChurn()));

    $fake->assertDecidedTimes(2);

    expect(failureOf(fn () => $fake->assertDecidedTimes(3)))->toStartWith('Expected 3 decisions to be made, but 2 were.')
        ->and(failureOf(fn () => $fake->assertDecidedTimes(1)))->toStartWith('Expected 1 decision to be made, but 2 were.');
});

it('asserts nothing was decided', function (): void {
    $fake = SystemOne::fake();

    $fake->assertNothingDecided();
    SystemOne::decide(aboutTicket()->ask(fakeChurn()));

    $once = failureOf(fn () => $fake->assertNothingDecided());
    SystemOne::decide(aboutTicket()->ask(fakeChurn()));

    expect($once)->toStartWith('Expected no decisions, but 1 decision was made.')
        ->and(failureOf(fn () => $fake->assertNothingDecided()))->toStartWith('Expected no decisions, but 2 decisions were made.');
});

it('asserts a question was asked, by an equal handle', function (): void {
    $fake = SystemOne::fake();
    SystemOne::decide(aboutTicket()->ask(fakeDepartmentQuestion()));
    SystemOne::decide(aboutTicket()->ask(fakeChurn()));

    $fake->assertAsked(fakeChurn());
    $fake->assertAsked(fakeDepartmentQuestion());

    expect(failureOf(fn () => $fake->assertAsked(fakeChurn('Will they leave?'))))
        ->toStartWith("The question 'churn' was not asked.")
        // A handle rebuilt with other case descriptions asks another question.
        ->and(failureOf(fn () => $fake->assertAsked(Question::choice('department', TranslatedDepartment::class, 'Which department should handle this?'))))
        ->toStartWith("The question 'department' was not asked.");
});

it('runs the assertions through the facade', function (): void {
    SystemOne::fake();
    SystemOne::decide(aboutTicket()->ask(fakeChurn()));

    SystemOne::assertDecidedTimes(1);
    SystemOne::assertAsked(fakeChurn());
    SystemOne::assertDecided(fn (Decision $decision): bool => true);
});
