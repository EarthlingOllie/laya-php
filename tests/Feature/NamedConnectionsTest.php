<?php

declare(strict_types=1);

use EarthlingOllie\SystemOneClassification\Domain\Context;
use EarthlingOllie\SystemOneClassification\Domain\Decision;
use EarthlingOllie\SystemOneClassification\Domain\Question;
use EarthlingOllie\SystemOneClassification\Exceptions\HostUnavailable;
use EarthlingOllie\SystemOneClassification\Exceptions\InvalidConfiguration;
use EarthlingOllie\SystemOneClassification\Exceptions\InvalidDecision;
use EarthlingOllie\SystemOneClassification\Exceptions\RejectedByModel;
use EarthlingOllie\SystemOneClassification\Laravel\Facades\SystemOne;
use EarthlingOllie\SystemOneClassification\Laravel\SystemOneManager;
use EarthlingOllie\SystemOneClassification\Tests\Fixtures\Enums\Department;
use Illuminate\Http\Client\Events\ConnectionFailed;
use Illuminate\Http\Client\Events\RequestSending;
use Illuminate\Http\Client\Events\ResponseReceived;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

/*
 * Named connections (decision 14): the same code reaches local laya-serve, hosted Laya or Jev
 * by choosing a connection. A remote one is used only once it has a key.
 */

const NAMED_KEY = 'sk-named-0123456789';

function namedDecision(): Decision
{
    return Decision::about(Context::text('Please refund me or I cancel.'))->ask(Question::yesNo('churn', 'Cancel?'));
}

/** Fakes every host with a yes/no answer and returns the requests it got. */
function fakeAnswers(): ArrayObject
{
    /** @var ArrayObject<int, Request> $sent */
    $sent = new ArrayObject;
    Http::fake(function (Request $request) use ($sent) {
        $sent->append($request);

        return Http::response(['answers' => ['churn' => ['type' => 'noul', 'noul' => 0.9]]]);
    });

    return $sent;
}

it('reaches hosted Laya and Jev by name, each with its own key as the bearer token', function (string $name, string $url, string $profile): void {
    config(["system-one.connections.{$name}.api_key" => "key-for-{$name}"]);
    $department = Question::choice('department', Department::class, 'Which department?');
    Http::fake(['*' => Http::response(['answers' => ['department' => [
        'type' => 'choice', 'choice' => 'billing', 'confidence' => 0.4,
        'probabilities' => ['billing' => 0.8, 'technical' => 0.1, 'other' => 0.1],
    ]]])]);

    $answer = SystemOne::connection($name)->decide(Decision::about(Context::text('x'))->ask($department))->get($department);

    expect($answer->confidence->profile)->toBe($profile);
    Http::assertSent(fn (Request $request): bool => $request->url() === $url
        && $request->header('Authorization') === ["Bearer key-for-{$name}"]);
    Http::assertSentCount(1);
})->with([
    ['laya-hosted', 'https://api.laya-ai.com/v1/systemone', 'laya'],
    ['jev', 'https://api.typesafe.ai/v1/systemone', 'jev'],
]);

it('refuses a remote connection without a key, sending nothing', function (string $name, mixed $key): void {
    config(["system-one.connections.{$name}.api_key" => $key]);
    $sent = fakeAnswers();

    expect(fn () => SystemOne::connection($name)->decide(namedDecision()))
        ->toThrow(InvalidConfiguration::class, "System One connection '{$name}' is a remote host, so it needs an 'api_key'; nothing is sent to it without one.")
        ->and($sent)->toHaveCount(0);
})->with(['laya-hosted', 'jev'])->with(['unset' => null, 'empty' => '']);

// Review finding: a blank key passed the check, and Laravel's withToken() trims it to a bare
// "Bearer" header.
it('treats a blank key as none', function (): void {
    config(['system-one.connections.jev.api_key' => "  \t", 'system-one.connections.local.api_key' => ' ']);
    $sent = fakeAnswers();

    SystemOne::decide(namedDecision());

    expect(fn () => SystemOne::connection('jev'))->toThrow(InvalidConfiguration::class, "System One connection 'jev' is a remote host, so it needs an 'api_key'")
        ->and($sent)->toHaveCount(1)
        ->and($sent[0]->hasHeader('Authorization'))->toBeFalse()
        ->and(app(SystemOneManager::class)->configuration('local')->hasApiKey())->toBeFalse();
});

it('sends the key without the whitespace around it', function (): void {
    config(['system-one.connections.jev.api_key' => ' '.NAMED_KEY."\n"]);
    $sent = fakeAnswers();

    SystemOne::connection('jev')->decide(namedDecision());

    expect($sent[0]->header('Authorization'))->toBe(['Bearer '.NAMED_KEY])
        ->and(app(SystemOneManager::class)->configuration('jev')->apiKey())->toBe(NAMED_KEY);
});

it('refuses any connection marked remote without a key, and lets one that is not go without', function (): void {
    config([
        'system-one.connections.mine' => ['url' => 'https://mine.test', 'remote' => 'true'],
        'system-one.connections.lan' => ['url' => 'http://laya.lan:8000', 'remote' => false],
    ]);
    $sent = fakeAnswers();

    SystemOne::connection('lan')->decide(namedDecision());

    expect(fn () => SystemOne::connection('mine'))->toThrow(InvalidConfiguration::class, "System One connection 'mine' is a remote host")
        ->and($sent)->toHaveCount(1)
        ->and($sent[0]->hasHeader('Authorization'))->toBeFalse();
});

it('rejects a remote flag that is not true or false', function (mixed $remote): void {
    config(['system-one.connections.odd' => ['url' => 'https://odd.test', 'api_key' => 'k', 'remote' => $remote]]);

    SystemOne::connection('odd');
})->with(['sometimes', 2, [[true]]])
    ->throws(InvalidConfiguration::class, "System One connection 'odd' has a 'remote' that is not true or false.");

it('treats a connection with no or an empty remote flag as local', function (mixed $remote): void {
    config(['system-one.connections.odd' => ['url' => 'http://odd.test', 'remote' => $remote]]);

    expect(SystemOne::connection('odd'))->not->toBeNull();
})->with([null, '', '0', 'false']);

it('sends a remote key only over https, or to this machine', function (string $url): void {
    config(['system-one.connections.jev' => [...config('system-one.connections.jev'), 'url' => $url, 'api_key' => NAMED_KEY]]);
    $sent = fakeAnswers();

    SystemOne::connection('jev')->decide(namedDecision());

    expect($sent)->toHaveCount(1);
})->with(['https://jev.test', 'HTTPS://jev.test', 'http://localhost:9001', 'http://127.0.0.1:9001', 'http://127.8.9.10', 'http://[::1]:9001', 'http://LOCALHOST']);

it('refuses to send a remote key over plain http to another machine', function (string $url): void {
    config(['system-one.connections.jev' => [...config('system-one.connections.jev'), 'url' => $url, 'api_key' => NAMED_KEY]]);
    $sent = fakeAnswers();

    try {
        SystemOne::connection('jev')->decide(namedDecision());
        $this->fail('Expected InvalidConfiguration.');
    } catch (InvalidConfiguration $invalid) {
        expect($invalid->getMessage())->toBe("System One connection 'jev' is a remote host, so its 'url' must use https (plain http is allowed only to localhost).")
            ->and($sent)->toHaveCount(0);
    }
})->with(['http://jev.test', 'http://localhost.jev.test', 'http://127.jev.test', 'http://128.0.0.1', 'ftp://jev.test', 'jev.test', 'http:///nohost']);

// Review finding: Laravel's HTTP client dispatches RequestSending, ResponseReceived and
// ConnectionFailed with the whole request, Authorization header included, to any listener.
it('dispatches no HTTP client event, so no listener ever sees the key', function (): void {
    config(['system-one.connections.jev' => [...config('system-one.connections.jev'), 'api_key' => NAMED_KEY, 'retries' => 1]]);
    $seen = [];
    Event::listen([RequestSending::class, ResponseReceived::class, ConnectionFailed::class], function (object $event) use (&$seen): void {
        $seen[] = $event::class;
    });
    Http::fake(['*' => Http::sequence()->pushFailedConnection('refused')->push(['answers' => ['churn' => ['type' => 'noul', 'noul' => 0.9]]])->push('ok')]);

    SystemOne::connection('jev')->decide(namedDecision());
    // An app's own requests still dispatch them.
    Http::get('https://example.test');

    expect($seen)->toBe([RequestSending::class, ResponseReceived::class]);
    // Http::fake() still sees the package's requests, the key included.
    Http::assertSent(fn (Request $request): bool => $request->header('Authorization') === ['Bearer '.NAMED_KEY]);
});

it("keeps the application's global HTTP middleware, options and stray-request guard", function (): void {
    Http::globalRequestMiddleware(fn ($request) => $request->withHeader('X-App', 'mine'));
    Http::globalOptions(['headers' => ['X-Option' => 'set']]);
    Http::preventStrayRequests();
    Http::allowStrayRequests(['http://allowed.test/*']);
    Http::fake(['http://faked.test/*' => Http::response(['answers' => ['churn' => ['type' => 'noul', 'noul' => 0.9]]])]);
    config([
        'system-one.connections.faked' => ['url' => 'http://faked.test'],
        'system-one.connections.stray' => ['url' => 'http://stray.test'],
        'system-one.connections.allowed' => ['url' => 'http://allowed.test', 'timeout' => 1],
    ]);

    SystemOne::connection('faked')->decide(namedDecision());

    Http::assertSent(fn (Request $request): bool => $request->header('X-App') === ['mine'] && $request->header('X-Option') === ['set']);
    expect(fn () => SystemOne::connection('stray')->decide(namedDecision()))->toThrow(RuntimeException::class, 'Attempted request to [http://stray.test/v1/systemone] without a matching fake.')
        // Allowed through for real: nothing listens there, so it cannot be reached.
        ->and(fn () => SystemOne::connection('allowed')->decide(namedDecision()))->toThrow(HostUnavailable::class, 'could not be reached');
});

it('keeps the key out of every exception, however it fails', function (): void {
    config(['system-one.connections.jev' => [...config('system-one.connections.jev'), 'api_key' => NAMED_KEY, 'retries' => 0]]);
    $messages = [];

    foreach ([
        Http::response(['detail' => 'key '.NAMED_KEY.' is not valid'], 401),
        Http::response(['detail' => [['loc' => ['header', NAMED_KEY], 'msg' => 'bad '.NAMED_KEY]]], 422),
        Http::response('upstream said '.NAMED_KEY, 502),
        Http::failedConnection('cURL error 28 for Bearer '.NAMED_KEY),
    ] as $response) {
        Http::fake(['*' => $response]);

        try {
            SystemOne::connection('jev')->decide(namedDecision());
        } catch (RejectedByModel|HostUnavailable $exception) {
            $messages[] = (string) $exception;
        }
    }

    config(['system-one.connections.jev.url' => 'http://jev.test']);

    try {
        SystemOne::connection('jev');
    } catch (InvalidConfiguration $exception) {
        $messages[] = (string) $exception;
    }

    expect($messages)->toHaveCount(5);

    foreach ($messages as $message) {
        expect($message)->not->toContain(NAMED_KEY)->not->toContain(substr(NAMED_KEY, 0, 12));
    }
});

it('maps every refusal to RejectedByModel with the server message, and sends it once', function (int $status, string $detail): void {
    config(['system-one.connections.jev.api_key' => NAMED_KEY]);
    Http::fake(['*' => Http::response(['detail' => $detail], $status)]);

    try {
        SystemOne::connection('jev')->decide(namedDecision());
        $this->fail('Expected RejectedByModel.');
    } catch (RejectedByModel $rejected) {
        expect($rejected->status)->toBe($status)
            ->and($rejected->detail)->toBe($detail);
    }

    Http::assertSentCount(1);
    Sleep::assertNeverSlept();
})->with([
    [401, 'invalid or missing bearer token'],
    [403, 'key not allowed for this model'],
    [404, 'Not Found'],
    [413, "too many choice options for 'label' (101 > 100)"],
    [422, 'min_confidence must be a float in [0.0, 1.0], got 1.5'],
    [429, 'rate limited'],
]);

it('raises HostUnavailable for a 5xx only after the configured retries, backing off', function (): void {
    config(['system-one.connections.jev' => [...config('system-one.connections.jev'), 'api_key' => NAMED_KEY, 'retries' => '3', 'retry_delay' => '100']]);
    Http::fake(['*' => Http::response(['detail' => 'server busy'], 503)]);

    expect(fn () => SystemOne::connection('jev')->decide(namedDecision()))
        ->toThrow(HostUnavailable::class, 'The model server is unavailable (HTTP 503 after 4 attempts): server busy');

    Http::assertSentCount(4);
    Sleep::assertSequence([Sleep::usleep(100_000), Sleep::usleep(200_000), Sleep::usleep(400_000)]);
});

it('raises HostUnavailable for a host it cannot reach only after the configured retries', function (): void {
    config(['system-one.connections.laya-hosted' => [...config('system-one.connections.laya-hosted'), 'api_key' => NAMED_KEY]]);
    Http::fake(['*' => Http::failedConnection('Connection timed out')]);

    expect(fn () => SystemOne::connection('laya-hosted')->decide(namedDecision()))
        ->toThrow(HostUnavailable::class, 'The model server at https://api.laya-ai.com could not be reached after 3 attempts: Connection timed out');

    Sleep::assertSequence([Sleep::usleep(500_000), Sleep::usleep(1_000_000)]);
});

it('answers once a retry gets through', function (): void {
    Http::fake(['*' => Http::sequence()
        ->push(['detail' => 'server busy'], 503)
        ->push(['answers' => ['churn' => ['type' => 'noul', 'noul' => 0.25]]])]);
    $churn = Question::yesNo('churn', 'Cancel?');

    expect(SystemOne::decide(Decision::about(Context::text('x'))->ask($churn))->get($churn)->probability)->toBe(0.25);
    Sleep::assertSequence([Sleep::usleep(200_000)]);
});

it('retries nothing for a connection that sets no retries', function (mixed $retries): void {
    config(['system-one.connections.other' => ['url' => 'http://other.test', 'retries' => $retries, 'retry_delay' => 10]]);
    Http::fake(['*' => Http::response(['detail' => 'down'], 500)]);

    expect(fn () => SystemOne::connection('other')->decide(namedDecision()))->toThrow(HostUnavailable::class)
        ->and(app(SystemOneManager::class)->configuration('other')->retry->retries)->toBe(0);
    Http::assertSentCount(1);
})->with(['unset' => null, 'empty' => '', 'zero' => 0, 'zero string' => '0']);

it('waits 200 ms before the first retry when the connection sets no delay', function (mixed $delay): void {
    config(['system-one.connections.other' => ['url' => 'http://other.test', 'retries' => 1, 'retry_delay' => $delay]]);
    Http::fake(['*' => Http::response(['detail' => 'down'], 500)]);

    expect(fn () => SystemOne::connection('other')->decide(namedDecision()))->toThrow(HostUnavailable::class);
    Sleep::assertSequence([Sleep::usleep(200_000)]);
})->with(['unset' => null, 'empty' => '']);

it('retries without waiting for a delay of zero', function (): void {
    config(['system-one.connections.other' => ['url' => 'http://other.test', 'retries' => 1, 'retry_delay' => '0']]);
    Http::fake(['*' => Http::response(['detail' => 'down'], 500)]);

    expect(fn () => SystemOne::connection('other')->decide(namedDecision()))->toThrow(HostUnavailable::class);
    Http::assertSentCount(2);
    Sleep::assertSequence([Sleep::usleep(0)]);
});

it('rejects retries or a retry delay that is not a whole number of zero or more', function (string $key, mixed $value): void {
    config(['system-one.connections.other' => ['url' => 'http://other.test', $key => $value]]);

    expect(fn () => SystemOne::connection('other'))
        ->toThrow(InvalidConfiguration::class, "System One connection 'other' has a '{$key}' that is not a whole number of zero or more.");
})->with(['retries', 'retry_delay'])->with(['negative' => -1, 'fraction' => 1.5, 'fraction string' => '1.5', 'words' => 'often', 'true' => true, 'list' => [[2]]]);

// Review finding: a typo such as SYSTEM_ONE_RETRIES=2000000 was accepted, and one decision
// against a host that is down would then wait for days.
it('allows at most ten retries', function (): void {
    config([
        'system-one.connections.ten' => ['url' => 'http://ten.test', 'retries' => '10'],
        'system-one.connections.eleven' => ['url' => 'http://eleven.test', 'retries' => 11],
    ]);

    expect(app(SystemOneManager::class)->configuration('ten')->retry->retries)->toBe(10)
        ->and(fn () => SystemOne::connection('eleven'))
        ->toThrow(InvalidConfiguration::class, "System One connection 'eleven' has 'retries' above 10.");
});

it("sends the connection's default model, held to its profile and accepted as one of its models", function (string $name, mixed $model, ?string $sent): void {
    config(["system-one.connections.{$name}.api_key" => NAMED_KEY, "system-one.connections.{$name}.model" => $model]);
    $requests = fakeAnswers();

    SystemOne::connection($name)->decide(namedDecision());

    expect($requests[0]->data()['model'] ?? null)->toBe($sent);
})->with([
    'jev, not listed in models' => ['jev', 'jev-large', 'jev-large'],
    'hosted laya, a name laya-serve does not know' => ['laya-hosted', 'laya-rl-agent', 'laya-rl-agent'],
    'local, trimmed' => ['local', ' english ', 'english'],
    'empty: the server routes' => ['local', '', null],
    'unset' => ['local', null, null],
]);

it('lists the default model once among the models a decision may pin', function (): void {
    config(['system-one.connections.jev' => [...config('system-one.connections.jev'), 'api_key' => NAMED_KEY, 'models' => 'jev-1', 'model' => 'jev-1']]);

    expect(fn () => SystemOne::connection('jev')->decide(namedDecision()->withModel('jev-9')))
        ->toThrow(InvalidDecision::class, "The decision pins model 'jev-9', which the jev profile does not accept; use one of: jev-1.");
});

it("lets a decision's pinned model win over the connection's default", function (): void {
    config(['system-one.connections.local.model' => 'multilingual']);
    $requests = fakeAnswers();

    SystemOne::decide(namedDecision()->withModel('english'));

    expect($requests[0]->data()['model'])->toBe('english');
});

it('rejects a default model that is not a string', function (mixed $model): void {
    config(['system-one.connections.local.model' => $model]);

    SystemOne::connection('local');
})->with([5, true, [['english']]])
    ->throws(InvalidConfiguration::class, "System One connection 'local' has a 'model' that is not a string.");

it('describes a connection without requiring its key, and never shows the key', function (): void {
    config(['system-one.connections.jev.api_key' => NAMED_KEY, 'system-one.connections.laya-hosted.api_key' => null]);
    $manager = app(SystemOneManager::class);

    $jev = $manager->configuration('jev');
    $hosted = $manager->configuration('laya-hosted');
    $local = $manager->configuration();

    expect([$jev->name, $jev->url, $jev->remote, $jev->hasApiKey(), $jev->profile->name, $jev->timeout, $jev->retry->retries, $jev->retry->delayMilliseconds])
        ->toBe(['jev', 'https://api.typesafe.ai', true, true, 'jev', 30.0, 2, 500])
        ->and([$hosted->name, $hosted->remote, $hosted->hasApiKey(), $hosted->model])->toBe(['laya-hosted', true, false, null])
        ->and([$local->name, $local->remote])->toBe(['local', false])
        ->and($jev->apiKey())->toBe(NAMED_KEY)
        ->and(print_r($jev, true))->not->toContain(NAMED_KEY)
        ->and(var_export($jev, true))->not->toContain(NAMED_KEY)
        ->and(json_encode($jev, JSON_THROW_ON_ERROR))->not->toContain(NAMED_KEY)
        ->and(fn () => $manager->connection('laya-hosted'))->toThrow(InvalidConfiguration::class, 'needs an \'api_key\'');
});
