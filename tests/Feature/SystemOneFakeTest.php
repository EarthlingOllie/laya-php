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
use EarthlingOllie\SystemOneClassification\Exceptions\InvalidFakeAnswer;
use EarthlingOllie\SystemOneClassification\Exceptions\UnscriptedQuestion;
use EarthlingOllie\SystemOneClassification\Laravel\ConnectionResolver;
use EarthlingOllie\SystemOneClassification\Laravel\Facades\SystemOne;
use EarthlingOllie\SystemOneClassification\Laravel\SystemOneManager;
use EarthlingOllie\SystemOneClassification\Laravel\Testing\FakeAnswer;
use EarthlingOllie\SystemOneClassification\Laravel\Testing\SystemOneFake;
use EarthlingOllie\SystemOneClassification\Result\Abstention;
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

it('fakes a remote connection that has no API key, since nothing is sent to it', function (): void {
    // The shipped jev connection is remote and, with no SYSTEM_ONE_JEV_API_KEY, keyless: the
    // real manager refuses to send to it, but a test of the app's jev path needs no real key.
    config(['system-one.connections.jev.api_key' => null]);
    $churn = fakeChurn();
    $fake = SystemOne::fake([FakeAnswer::yesNo($churn, 0.8)]);

    $answer = SystemOne::connection('jev')->decide(aboutTicket()->ask($churn))->get($churn);

    expect($answer->probability)->toBe(0.8)
        ->and(fn (): mixed => app(SystemOneManager::class)->connection('jev'))
        ->toThrow(InvalidConfiguration::class, "System One connection 'jev' is a remote host, so it needs an 'api_key'");
    $fake->assertDecided(fn (Decision $decision, string $connection): bool => $connection === 'jev');
});

it('still checks a remote connection\'s url under the fake', function (): void {
    config(['system-one.connections.jev.url' => 'http://jev.example']);
    SystemOne::fake();

    SystemOne::connection('jev');
})->throws(InvalidConfiguration::class, "System One connection 'jev' is a remote host, so its 'url' must use https");

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

    $fake->assertDecided(fn (Decision $decision): bool => $decision->context->state === 'second');

    expect(failureOf(fn () => $fake->assertDecided(fn (Decision $decision): bool => $decision->context->state === 'third')))
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

// #7's abstention, which the issue's "abstained answer" helper needs.
it('answers an abstained script as abstained at the decision\'s minimum confidence, keeping the best guess', function (): void {
    $department = fakeDepartmentQuestion();
    $urgency = fakeUrgencyQuestion();
    $churn = fakeChurn();
    SystemOne::fake([
        FakeAnswer::abstained(FakeAnswer::choice($department, Department::Technical, confidence: 0.4)),
        FakeAnswer::abstained(FakeAnswer::score($urgency, Urgency::High)),
        FakeAnswer::abstained(FakeAnswer::yesNo($churn, 0.55)),
    ]);

    $result = SystemOne::decide(aboutTicket()->ask($department, $urgency, $churn)->withMinConfidence(0.8));

    foreach ([$department, $urgency, $churn] as $question) {
        expect($result->get($question)->abstained)->toBeTrue()
            ->and($result->get($question)->abstention)->toBe(Abstention::Abstained)
            ->and($result->get($question)->abstentionThreshold)->toBe(0.8);
    }

    expect($result->get($department)->value)->toBe(Department::Technical)
        ->and($result->get($department)->answerConfidence)->toBe(0.4)
        ->and($result->get($urgency)->value)->toBe(Urgency::High)
        ->and($result->get($churn)->probability)->toBe(0.55);
});

it('answers every other question on a gated decision as passed, as a server reports it', function (): void {
    $churn = fakeChurn();
    $urgency = fakeUrgencyQuestion();
    SystemOne::fake([FakeAnswer::yesNo($churn, 0.9)]);

    $result = SystemOne::decide(aboutTicket()->ask($churn, $urgency)->withMinConfidence(0.6));

    foreach ([$churn, $urgency] as $question) {
        expect($result->get($question)->abstained)->toBeFalse()
            ->and($result->get($question)->abstention)->toBe(Abstention::Passed)
            ->and($result->get($question)->abstentionThreshold)->toBe(0.6);
    }
});

it('answers a decision without a minimum confidence as not gated, an unused abstained script included', function (): void {
    $churn = fakeChurn();
    $urgency = fakeUrgencyQuestion();
    SystemOne::fake([FakeAnswer::yesNo($churn, 0.9), FakeAnswer::abstained(FakeAnswer::score($urgency, Urgency::Low))]);

    $answer = SystemOne::decide(aboutTicket()->ask($churn))->get($churn);

    expect($answer->abstention)->toBe(Abstention::NotRequested)
        ->and($answer->abstentionThreshold)->toBeNull();
});

it('refuses an abstained script for a decision that set no minimum confidence, which no server would abstain on', function (): void {
    $churn = fakeChurn();
    $fake = SystemOne::fake([FakeAnswer::abstained(FakeAnswer::yesNo($churn, 0.5))]);

    expect(fn (): mixed => SystemOne::decide(aboutTicket()->ask($churn)))
        ->toThrow(InvalidFakeAnswer::class, "The answer for 'churn' is scripted as abstained, but the decision sets no minimum confidence (withMinConfidence()), so no server would abstain on it.");
    $fake->assertNothingDecided();
});

// #8's decideMany() under the fake: each context is one recorded decision.
it('answers decideMany() with one result per context, in order, recording each as a decision', function (): void {
    $churn = fakeChurn();
    $fake = SystemOne::fake([FakeAnswer::yesNo($churn, 0.7)]);
    $template = aboutTicket('the template')->ask($churn)->withMinConfidence(0.5);

    $results = SystemOne::decideMany([Context::text('first'), Context::fields(['body' => 'second'])], $template);

    expect($results)->toHaveCount(2)
        ->and($results->usage)->toBeNull()
        ->and($results[0]->get($churn)->probability)->toBe(0.7)
        ->and($results[1]->get($churn)->abstention)->toBe(Abstention::Passed);
    $fake->assertDecidedTimes(2);
    $fake->assertDecided(fn (Decision $decision): bool => $decision->context->state === 'first' && $decision->minConfidence === 0.5);
    $fake->assertDecided(fn (Decision $decision): bool => $decision->context->state === ['body' => 'second']);
    expect(failureOf(fn () => $fake->assertDecided(fn (Decision $decision): bool => $decision->context->state === 'the template')))
        ->toStartWith('No decision matching the callback was made (2 decisions were made).');
});

it('answers decideMany() on a named connection', function (): void {
    config(['system-one.connections.jev' => ['url' => 'https://jev.test', 'profile' => 'jev']]);
    $department = fakeDepartmentQuestion();
    $fake = SystemOne::fake();

    $results = SystemOne::connection('jev')->decideMany([Context::text('a'), Context::text('b')], aboutTicket()->ask($department));

    expect($results[1]->get($department)->confidence->profile)->toBe('jev');
    $fake->assertDecided(fn (Decision $decision, string $connection): bool => $connection === 'jev' && $decision->context->state === 'b');
});

it('refuses in decideMany() what the real client refuses, recording nothing', function (array $contexts, string $message): void {
    $fake = SystemOne::fake();

    expect(fn (): mixed => SystemOne::decideMany($contexts, aboutTicket()->ask(fakeChurn())))
        ->toThrow(InvalidDecision::class, $message);
    $fake->assertNothingDecided();
})->with([
    'no contexts' => [[], 'decideMany() needs at least one context.'],
    'not a list' => [[1 => Context::text('a')], 'decideMany() takes the contexts as a list'],
    'not a context' => [[Context::text('a'), 'b'], 'Context 1 of the batch is string, not a Context'],
    'text that is not UTF-8' => [[Context::text('a'), Context::text("tw\xB1ce")], 'The context text is not valid UTF-8'],
]);

// Review finding: #12's event and cache settings are part of what the real client refuses.
it('refuses event and cache settings the real client refuses', function (array $settings, string $message): void {
    config($settings);
    $fake = SystemOne::fake();

    expect(fn (): mixed => SystemOne::decide(aboutTicket()->ask(fakeChurn())))->toThrow(InvalidConfiguration::class, $message)
        ->and(fn (): mixed => app(SystemOneManager::class)->connection())->toThrow(InvalidConfiguration::class, $message);
    $fake->assertNothingDecided();
})->with([
    'events.enabled' => [['system-one.events.enabled' => 'maybe'], "config/system-one.php has a 'events.enabled' that is not a boolean."],
    'events.include_context' => [['system-one.events.include_context' => 'maybe'], "config/system-one.php has a 'events.include_context' that is not a boolean."],
    'cache.ttl' => [['system-one.cache.enabled' => true, 'system-one.cache.ttl' => 'abc'], "config/system-one.php has a 'cache.ttl' that is not a whole number of seconds above zero."],
    'cache.store' => [['system-one.cache.enabled' => true, 'system-one.cache.store' => ['redis']], "config/system-one.php has a 'cache.store' that is not a store name."],
]);

// Review finding: Laya's gate compares answer_confidence with min_confidence (docs/PLAN.md, Phase
// 0), so a script below the decision's threshold abstains, as the server's answer would.
it('abstains on a script whose answer confidence is below the decision\'s threshold, as the gate does', function (): void {
    $churn = fakeChurn();
    $department = fakeDepartmentQuestion();
    $urgency = fakeUrgencyQuestion();
    SystemOne::fake([
        FakeAnswer::yesNo($churn, 0.3),
        FakeAnswer::choice($department, Department::Billing, confidence: 0.6),
        FakeAnswer::score($urgency, Urgency::High, expected: 1.2),
    ]);

    $result = SystemOne::decide(aboutTicket()->ask($churn, $department, $urgency)->withMinConfidence(0.65));

    // yes/no: the reported "no" at 0.7 passes; the choice at 0.6 abstains; the score's case
    // keeps 0.6 of the probability (the rest on Low) to reach 1.2, so it abstains.
    expect($result->get($churn)->abstention)->toBe(Abstention::Passed)
        ->and($result->get($department)->abstention)->toBe(Abstention::Abstained)
        ->and($result->get($department)->value)->toBe(Department::Billing)
        ->and($result->get($urgency)->answerConfidence)->toBeLessThan(0.65)
        ->and($result->get($urgency)->abstention)->toBe(Abstention::Abstained);

    expect(SystemOne::decide(aboutTicket()->ask($department)->withMinConfidence(0.6))->get($department)->abstention)->toBe(Abstention::Passed);

    // A yes/no of 0.4 reports "no" at 0.6: below a 0.65 gate it abstains, above 0.55 it passes.
    SystemOne::fake([FakeAnswer::yesNo($churn, 0.4)]);

    expect(SystemOne::decide(aboutTicket()->ask($churn)->withMinConfidence(0.65))->get($churn)->abstention)->toBe(Abstention::Abstained)
        ->and(SystemOne::decide(aboutTicket()->ask($churn)->withMinConfidence(0.55))->get($churn)->abstention)->toBe(Abstention::Passed);
});
