<?php

declare(strict_types=1);

use EarthlingOllie\SystemOneClassification\Domain\Context;
use EarthlingOllie\SystemOneClassification\Domain\Decision;
use EarthlingOllie\SystemOneClassification\Domain\Question;
use EarthlingOllie\SystemOneClassification\Exceptions\InvalidConfiguration;
use EarthlingOllie\SystemOneClassification\Exceptions\InvalidDecision;
use EarthlingOllie\SystemOneClassification\Laravel\Facades\SystemOne;
use EarthlingOllie\SystemOneClassification\Laravel\SystemOneServiceProvider;
use EarthlingOllie\SystemOneClassification\Tests\Fixtures\Enums\Department;
use EarthlingOllie\SystemOneClassification\Tests\Support\OptionsEnum;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\ServiceProvider;

const CONFIG_FILE = __DIR__.'/../../config/system-one.php';

/**
 * Evaluates config/system-one.php with the given environment, as Laravel does at boot.
 *
 * @param  array<string, string|null>  $environment
 * @return array<string, mixed>
 */
function systemOneConfigWith(array $environment): array
{
    $previous = [];

    foreach ($environment as $name => $value) {
        $previous[$name] = getenv($name);
        putenv($value === null ? $name : "{$name}={$value}");
    }

    try {
        return require CONFIG_FILE;
    } finally {
        foreach ($previous as $name => $value) {
            putenv($value === false ? $name : "{$name}={$value}");
        }
    }
}

it('merges the package config so the default connection is local', function (): void {
    expect(config('system-one.default'))->toBe('local')
        ->and(config('system-one.connections.local'))->toBeArray();
});

/** Every environment variable config/system-one.php reads, unset. */
const SYSTEM_ONE_ENVIRONMENT = [
    'SYSTEM_ONE_CONNECTION' => null,
    'SYSTEM_ONE_URL' => null,
    'SYSTEM_ONE_API_KEY' => null,
    'SYSTEM_ONE_TIMEOUT' => null,
    'SYSTEM_ONE_PROFILE' => null,
    'SYSTEM_ONE_TOURNAMENT_GROUPING' => null,
    'SYSTEM_ONE_MODELS' => null,
    'SYSTEM_ONE_MODEL' => null,
    'SYSTEM_ONE_RETRIES' => null,
    'SYSTEM_ONE_RETRY_DELAY' => null,
    'SYSTEM_ONE_LAYA_HOSTED_URL' => null,
    'SYSTEM_ONE_LAYA_HOSTED_API_KEY' => null,
    'SYSTEM_ONE_LAYA_HOSTED_TIMEOUT' => null,
    'SYSTEM_ONE_LAYA_HOSTED_TOURNAMENT_GROUPING' => null,
    'SYSTEM_ONE_LAYA_HOSTED_MODELS' => null,
    'SYSTEM_ONE_LAYA_HOSTED_MODEL' => null,
    'SYSTEM_ONE_LAYA_HOSTED_RETRIES' => null,
    'SYSTEM_ONE_LAYA_HOSTED_RETRY_DELAY' => null,
    'SYSTEM_ONE_JEV_URL' => null,
    'SYSTEM_ONE_JEV_API_KEY' => null,
    'SYSTEM_ONE_JEV_TIMEOUT' => null,
    'SYSTEM_ONE_JEV_MODELS' => null,
    'SYSTEM_ONE_JEV_MODEL' => null,
    'SYSTEM_ONE_JEV_RETRIES' => null,
    'SYSTEM_ONE_JEV_RETRY_DELAY' => null,
];

it('ships local, laya-hosted and jev connections, with local the default, when nothing is set', function (): void {
    expect(systemOneConfigWith(SYSTEM_ONE_ENVIRONMENT))->toBe([
        'default' => 'local',
        'connections' => [
            'local' => [
                'url' => 'http://localhost:8000',
                'api_key' => null,
                'timeout' => 30,
                'profile' => 'laya',
                'tournament_grouping' => false,
                'models' => '',
                'model' => null,
                'retries' => 2,
                'retry_delay' => 200,
            ],
            'laya-hosted' => [
                'remote' => true,
                'url' => 'https://api.laya-ai.com',
                'api_key' => null,
                'timeout' => 30,
                'profile' => 'laya',
                'tournament_grouping' => false,
                'models' => '',
                'model' => null,
                'retries' => 2,
                'retry_delay' => 500,
            ],
            'jev' => [
                'remote' => true,
                'url' => 'https://api.typesafe.ai',
                'api_key' => null,
                'timeout' => 30,
                'profile' => 'jev',
                'models' => '',
                'model' => null,
                'retries' => 2,
                'retry_delay' => 500,
            ],
        ],
    ]);
});

it('takes the local base URL, key, timeout, model and retries from the environment', function (): void {
    $config = systemOneConfigWith([
        'SYSTEM_ONE_URL' => 'http://laya:8000',
        'SYSTEM_ONE_API_KEY' => 'secret',
        'SYSTEM_ONE_TIMEOUT' => '5',
        'SYSTEM_ONE_MODEL' => 'english',
        'SYSTEM_ONE_RETRIES' => '4',
        'SYSTEM_ONE_RETRY_DELAY' => '50',
    ]);

    expect($config['connections']['local'])->toBe([
        'url' => 'http://laya:8000', 'api_key' => 'secret', 'timeout' => '5', 'profile' => 'laya',
        'tournament_grouping' => false, 'models' => '', 'model' => 'english', 'retries' => '4', 'retry_delay' => '50',
    ]);
});

it('takes every laya-hosted setting from the environment', function (): void {
    $config = systemOneConfigWith([
        'SYSTEM_ONE_LAYA_HOSTED_URL' => 'https://eu.laya.test',
        'SYSTEM_ONE_LAYA_HOSTED_API_KEY' => 'lh-key',
        'SYSTEM_ONE_LAYA_HOSTED_TIMEOUT' => '9',
        'SYSTEM_ONE_LAYA_HOSTED_TOURNAMENT_GROUPING' => 'true',
        'SYSTEM_ONE_LAYA_HOSTED_MODELS' => 'laya-rl-agent',
        'SYSTEM_ONE_LAYA_HOSTED_MODEL' => 'laya-rl-agent',
        'SYSTEM_ONE_LAYA_HOSTED_RETRIES' => '1',
        'SYSTEM_ONE_LAYA_HOSTED_RETRY_DELAY' => '1000',
    ]);

    expect($config['connections']['laya-hosted'])->toBe([
        'remote' => true, 'url' => 'https://eu.laya.test', 'api_key' => 'lh-key', 'timeout' => '9', 'profile' => 'laya',
        'tournament_grouping' => true, 'models' => 'laya-rl-agent', 'model' => 'laya-rl-agent', 'retries' => '1', 'retry_delay' => '1000',
    ]);
});

it('takes every jev setting from the environment', function (): void {
    $config = systemOneConfigWith([
        'SYSTEM_ONE_JEV_URL' => 'https://jev.test',
        'SYSTEM_ONE_JEV_API_KEY' => 'jev-key',
        'SYSTEM_ONE_JEV_TIMEOUT' => '7',
        'SYSTEM_ONE_JEV_MODELS' => 'jev-1,jev-2',
        'SYSTEM_ONE_JEV_MODEL' => 'jev-2',
        'SYSTEM_ONE_JEV_RETRIES' => '0',
        'SYSTEM_ONE_JEV_RETRY_DELAY' => '',
    ]);

    expect($config['connections']['jev'])->toBe([
        'remote' => true, 'url' => 'https://jev.test', 'api_key' => 'jev-key', 'timeout' => '7', 'profile' => 'jev',
        'models' => 'jev-1,jev-2', 'model' => 'jev-2', 'retries' => '0', 'retry_delay' => '',
    ]);
});

it('keeps local the default when the remote connections have keys', function (): void {
    $config = systemOneConfigWith([
        ...SYSTEM_ONE_ENVIRONMENT,
        'SYSTEM_ONE_LAYA_HOSTED_API_KEY' => 'lh-key',
        'SYSTEM_ONE_JEV_API_KEY' => 'jev-key',
    ]);
    config(['system-one' => $config]);
    Http::fake(['*' => Http::response(['answers' => ['churn' => ['type' => 'noul', 'noul' => 0.1]]])]);

    SystemOne::decide(Decision::about(Context::text('x'))->ask(Question::yesNo('churn', 'Cancel?')));

    expect($config['default'])->toBe('local');
    Http::assertSent(fn (Request $request): bool => $request->url() === 'http://localhost:8000/v1/systemone' && ! $request->hasHeader('Authorization'));
});

/**
 * Loads config/system-one.php into the app with the given environment, then asks one question
 * through the facade against a faked host.
 *
 * @param  array<string, string|null>  $environment
 * @return array{Request, array<string, mixed>} the request sent and the HTTP client options
 */
function decideWithEnvironment(array $environment): array
{
    config(['system-one' => systemOneConfigWith($environment)]);
    $sent = [];
    Http::fake(function (Request $request, array $options) use (&$sent) {
        $sent = [$request, $options];

        return Http::response(['answers' => ['churn' => ['type' => 'noul', 'noul' => 0.1]]]);
    });

    SystemOne::decide(Decision::about(Context::text('x'))->ask(Question::yesNo('churn', 'Cancel?')));

    return $sent;
}

it('takes the local profile from SYSTEM_ONE_PROFILE', function (): void {
    expect(systemOneConfigWith(['SYSTEM_ONE_PROFILE' => 'jev'])['connections']['local']['profile'])->toBe('jev');
});

it('tags the raw confidence with the SYSTEM_ONE_PROFILE taken from the environment', function (): void {
    config(['system-one' => systemOneConfigWith(['SYSTEM_ONE_URL' => 'http://jev.test', 'SYSTEM_ONE_PROFILE' => 'jev'])]);
    $department = Question::choice('department', Department::class, 'Which department?');
    Http::fake(['*' => Http::response(['answers' => ['department' => [
        'type' => 'choice', 'choice' => 'billing', 'confidence' => 0.4,
        'probabilities' => ['billing' => 0.8, 'technical' => 0.1, 'other' => 0.1],
    ]]])]);

    $answer = SystemOne::decide(Decision::about(Context::text('x'))->ask($department))->get($department);

    expect($answer->confidence->profile)->toBe('jev');
});

it('takes tournament grouping and extra models for the local connection from the environment', function (): void {
    $local = systemOneConfigWith(['SYSTEM_ONE_TOURNAMENT_GROUPING' => 'true', 'SYSTEM_ONE_MODELS' => 'acme/a,acme/b'])['connections']['local'];

    expect($local['tournament_grouping'])->toBeTrue()
        ->and($local['models'])->toBe('acme/a,acme/b');
});

it('applies SYSTEM_ONE_TOURNAMENT_GROUPING and SYSTEM_ONE_MODELS taken from the environment', function (): void {
    config(['system-one' => systemOneConfigWith([
        'SYSTEM_ONE_URL' => 'http://laya.test',
        'SYSTEM_ONE_PROFILE' => null,
        'SYSTEM_ONE_TOURNAMENT_GROUPING' => '1',
        'SYSTEM_ONE_MODELS' => 'acme/a, acme/b',
    ])]);
    Http::fake(['*' => Http::response(['answers' => ['churn' => ['type' => 'noul', 'noul' => 0.1]]])]);

    SystemOne::decide(Decision::about(Context::text('x'))->ask(Question::yesNo('churn', 'Cancel?'))->withModel('acme/b'));

    Http::assertSent(fn (Request $request): bool => $request->data()['model'] === 'acme/b');
    expect(fn () => SystemOne::decide(Decision::about(Context::text('x'))
        ->ask(Question::choice('label', OptionsEnum::withCases(256), 'Which?'))))
        ->toThrow(InvalidDecision::class, 'more than the 255 the laya profile allows');
});

it('uses the 30 second timeout when SYSTEM_ONE_TIMEOUT is set but empty', function (string $value): void {
    [, $options] = decideWithEnvironment(['SYSTEM_ONE_URL' => 'http://laya.test', 'SYSTEM_ONE_TIMEOUT' => $value]);

    expect($options)->toHaveKey('timeout', 30.0);
})->with(['empty' => '', 'null' => 'null', '(null)' => '(null)']);

it('applies a SYSTEM_ONE_TIMEOUT taken from the environment', function (): void {
    [, $options] = decideWithEnvironment(['SYSTEM_ONE_URL' => 'http://laya.test', 'SYSTEM_ONE_TIMEOUT' => '2.5']);

    expect($options)->toHaveKey('timeout', 2.5);
});

it('rejects a SYSTEM_ONE_TIMEOUT that is not a positive number of seconds', function (string $value): void {
    expect(fn () => decideWithEnvironment(['SYSTEM_ONE_URL' => 'http://laya.test', 'SYSTEM_ONE_TIMEOUT' => $value]))
        ->toThrow(InvalidConfiguration::class, "System One connection 'local' has a 'timeout' that is not a number of seconds.");
})->with(['zero' => '0', 'negative' => '-1', 'words' => 'soon']);

it('sends no API key when SYSTEM_ONE_API_KEY is set but empty', function (): void {
    [$request] = decideWithEnvironment(['SYSTEM_ONE_URL' => 'http://laya.test', 'SYSTEM_ONE_API_KEY' => '']);

    expect($request->hasHeader('Authorization'))->toBeFalse();
});

it('says the connection needs a URL when SYSTEM_ONE_URL is set but empty', function (): void {
    expect(fn () => decideWithEnvironment(['SYSTEM_ONE_URL' => '']))
        ->toThrow(InvalidConfiguration::class, "System One connection 'local' needs a 'url'.");
});

it('takes the default connection from SYSTEM_ONE_CONNECTION', function (): void {
    expect(systemOneConfigWith(['SYSTEM_ONE_CONNECTION' => 'jev'])['default'])->toBe('jev');
});

it('publishes the config file under the system-one-config tag', function (): void {
    expect(ServiceProvider::pathsToPublish(SystemOneServiceProvider::class, 'system-one-config'))
        ->toBe([realpath(CONFIG_FILE) => config_path('system-one.php')]);
});

it('copies the config into the application with vendor:publish', function (): void {
    $target = config_path('system-one.php');
    @unlink($target);

    try {
        $this->artisan('vendor:publish', ['--tag' => 'system-one-config'])->assertSuccessful();

        expect($target)->toBeFile()
            ->and(file_get_contents($target))->toBe(file_get_contents(CONFIG_FILE));
    } finally {
        @unlink($target);
    }
});
