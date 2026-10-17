<?php

declare(strict_types=1);

use EarthlingOllie\SystemOneClassification\Client\DecisionClient;
use EarthlingOllie\SystemOneClassification\Domain\Context;
use EarthlingOllie\SystemOneClassification\Domain\Decision;
use EarthlingOllie\SystemOneClassification\Domain\Question;
use EarthlingOllie\SystemOneClassification\Domain\Question\YesNoQuestion;
use EarthlingOllie\SystemOneClassification\Exceptions\HostUnavailable;
use EarthlingOllie\SystemOneClassification\Exceptions\InvalidConfiguration;
use EarthlingOllie\SystemOneClassification\Exceptions\MalformedResponse;
use EarthlingOllie\SystemOneClassification\Laravel\Facades\SystemOne;
use EarthlingOllie\SystemOneClassification\Laravel\SystemOneManager;
use EarthlingOllie\SystemOneClassification\Result\Confidence;
use EarthlingOllie\SystemOneClassification\Result\Verdict;
use EarthlingOllie\SystemOneClassification\Tests\Fixtures\Enums\Department;
use EarthlingOllie\SystemOneClassification\Tests\Support\ComposerManifest;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

function churnQuestion(): YesNoQuestion
{
    return Question::yesNo('churn', 'Does the customer threaten to cancel?');
}

it('binds the manager as a singleton behind the SystemOne facade', function (): void {
    expect(app(SystemOneManager::class))->toBe(app(SystemOneManager::class))
        ->and(SystemOne::getFacadeRoot())->toBe(app(SystemOneManager::class));
});

it('declares the facade alias for auto-discovery', function (): void {
    expect(ComposerManifest::read()['extra']['laravel']['aliases'] ?? null)->toBe(['SystemOne' => SystemOne::class]);
});

it('decides a yes/no question through the facade against the default connection', function (): void {
    config(['system-one.connections.local.url' => 'http://laya.test:8000', 'system-one.connections.local.api_key' => 'k']);
    Http::fake(['*' => Http::response(['answers' => ['churn' => ['type' => 'noul', 'noul' => 0.91]]])]);
    $decision = Decision::about(Context::text('We will cancel.'))->ask(churnQuestion());

    $answer = SystemOne::decide($decision)->get(churnQuestion());

    expect($answer->probability)->toBe(0.91)
        ->and($answer->isYes(threshold: 0.8))->toBeTrue()
        ->and($answer->verdict(yes: 0.8, no: 0.2))->toBe(Verdict::Yes);
    Http::assertSent(fn (Request $request): bool => $request->url() === 'http://laya.test:8000/v1/systemone'
        && $request->hasHeader('Authorization', 'Bearer k'));
});

it('returns a client per named connection', function (): void {
    config(['system-one.connections.other' => ['url' => 'http://other.test', 'api_key' => null, 'timeout' => 5]]);
    Http::fake(['*' => Http::response(['answers' => ['churn' => ['type' => 'noul', 'noul' => 0.1]]])]);

    $client = SystemOne::connection('other');
    $client->decide(Decision::about(Context::text('x'))->ask(churnQuestion()));

    expect($client)->toBeInstanceOf(DecisionClient::class);
    Http::assertSent(fn (Request $request): bool => $request->url() === 'http://other.test/v1/systemone'
        && ! $request->hasHeader('Authorization'));
});

it('uses the configured default connection', function (): void {
    config([
        'system-one.default' => 'other',
        'system-one.connections.other' => ['url' => 'http://other.test'],
    ]);
    Http::fake(['*' => Http::response(['answers' => ['churn' => ['type' => 'noul', 'noul' => 0.1]]])]);

    SystemOne::decide(Decision::about(Context::text('x'))->ask(churnQuestion()));

    Http::assertSent(fn (Request $request): bool => $request->url() === 'http://other.test/v1/systemone');
});

it('applies the connection timeout', function (): void {
    config(['system-one.connections.local.timeout' => 7]);
    $options = null;
    Http::fake(function (Request $request, array $sent) use (&$options) {
        $options = $sent;

        return Http::response(['answers' => ['churn' => ['type' => 'noul', 'noul' => 0.1]]]);
    });

    SystemOne::decide(Decision::about(Context::text('x'))->ask(churnQuestion()));

    expect($options)->toBeArray()->toHaveKey('timeout', 7.0);
});

/** The raw confidence a connection's answer to a choice question is tagged with. */
function confidenceFrom(string $connection): Confidence
{
    $question = Question::choice('department', Department::class, 'Which department?');
    Http::fake(['*' => Http::response(['answers' => ['department' => [
        'type' => 'choice',
        'choice' => 'billing',
        'probabilities' => ['billing' => 0.9, 'technical' => 0.06, 'other' => 0.04],
        'confidence' => 0.45,
    ]]])]);

    return SystemOne::connection($connection)->decide(Decision::about(Context::text('x'))->ask($question))
        ->get($question)->confidence;
}

it('tags the raw confidence with the profile the connection names', function (string $profile): void {
    config(['system-one.connections.other' => ['url' => 'http://other.test', 'profile' => $profile]]);

    expect(confidenceFrom('other'))->toEqual(new Confidence(0.45, $profile));
})->with(['laya', 'jev']);

it('tags the raw confidence as laya when the connection names no profile', function (mixed $profile): void {
    config(['system-one.connections.other' => ['url' => 'http://other.test', 'profile' => $profile]]);

    expect(confidenceFrom('other'))->toEqual(new Confidence(0.45, 'laya'));
})->with(['missing' => null, 'empty' => '']);

it('rejects a profile it does not know', function (mixed $profile): void {
    config(['system-one.connections.broken' => ['url' => 'http://x', 'profile' => $profile]]);

    SystemOne::connection('broken');
})->with(['unknown' => 'gpt', 'wrong case' => 'Jev', 'not a string' => 5])
    ->throws(InvalidConfiguration::class, "System One connection 'broken' has a 'profile' that is not one of: laya, jev.");

it('rejects an unknown connection name', function (): void {
    SystemOne::connection('nope');
})->throws(InvalidConfiguration::class, "System One connection 'nope' is not configured in config/system-one.php.");

it('rejects a connection without a usable URL', function (array $connection, string $message): void {
    config(['system-one.connections.broken' => $connection]);

    expect(fn () => SystemOne::connection('broken'))->toThrow(InvalidConfiguration::class, $message);
})->with([
    'no url' => [['api_key' => 'k'], "System One connection 'broken' needs a 'url'."],
    'empty url' => [['url' => ''], "System One connection 'broken' needs a 'url'."],
    'bad key' => [['url' => 'http://x', 'api_key' => 5], "System One connection 'broken' has an 'api_key' that is not a string."],
    'bad timeout' => [['url' => 'http://x', 'timeout' => 'soon'], "System One connection 'broken' has a 'timeout' that is not a number of seconds."],
    'zero timeout' => [['url' => 'http://x', 'timeout' => 0], "System One connection 'broken' has a 'timeout' that is not a number of seconds."],
]);

it('rejects a connection that is not an array', function (): void {
    config(['system-one.connections.broken' => 'http://x']);

    SystemOne::connection('broken');
})->throws(InvalidConfiguration::class, "System One connection 'broken' is not configured in config/system-one.php.");

it('rejects a default connection name that is not a string', function (): void {
    config(['system-one.default' => null]);

    SystemOne::connection();
})->throws(InvalidConfiguration::class, 'config/system-one.php has no default connection name.');

it('raises HostUnavailable when the server is down', function (): void {
    Http::fake(['*' => Http::failedConnection('Connection refused')]);

    SystemOne::decide(Decision::about(Context::text('x'))->ask(churnQuestion()));
})->throws(HostUnavailable::class, 'could not be reached');

it('raises HostUnavailable for a 5xx and MalformedResponse for an unexpected body', function (): void {
    $decision = Decision::about(Context::text('x'))->ask(churnQuestion());

    // The local connection tries twice more before giving up on a 5xx.
    Http::fake(['*' => Http::sequence()
        ->push(['detail' => 'inference failed'], 500)
        ->push(['detail' => 'inference failed'], 500)
        ->push(['detail' => 'inference failed'], 500)
        ->push('<html>', 200)]);

    expect(fn () => SystemOne::decide($decision))->toThrow(HostUnavailable::class, 'inference failed')
        ->and(fn () => SystemOne::decide($decision))->toThrow(MalformedResponse::class);
});
