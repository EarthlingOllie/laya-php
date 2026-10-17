<?php

declare(strict_types=1);

use EarthlingOllie\SystemOneClassification\Domain\Context;
use EarthlingOllie\SystemOneClassification\Domain\Decision;
use EarthlingOllie\SystemOneClassification\Domain\Question;
use EarthlingOllie\SystemOneClassification\Exceptions\InvalidConfiguration;
use EarthlingOllie\SystemOneClassification\Laravel\Facades\SystemOne;
use EarthlingOllie\SystemOneClassification\Laravel\SystemOneServiceProvider;
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

it('points the local connection at http://localhost:8000 when SYSTEM_ONE_URL is not set', function (): void {
    $config = systemOneConfigWith([
        'SYSTEM_ONE_CONNECTION' => null,
        'SYSTEM_ONE_URL' => null,
        'SYSTEM_ONE_API_KEY' => null,
        'SYSTEM_ONE_TIMEOUT' => null,
        'SYSTEM_ONE_MODEL' => null,
        'SYSTEM_ONE_EVENTS' => null,
        'SYSTEM_ONE_EVENTS_INCLUDE_CONTEXT' => null,
        'SYSTEM_ONE_CACHE' => null,
        'SYSTEM_ONE_CACHE_STORE' => null,
        'SYSTEM_ONE_CACHE_TTL' => null,
    ]);

    expect($config)->toBe([
        'default' => 'local',
        'connections' => [
            'local' => [
                'url' => 'http://localhost:8000',
                'api_key' => null,
                'timeout' => 30,
                'model' => null,
            ],
        ],
        'events' => ['enabled' => true, 'include_context' => false],
        'cache' => ['enabled' => false, 'store' => null, 'ttl' => 3600],
    ]);
});

it('takes the model, event and cache settings from the environment', function (): void {
    $config = systemOneConfigWith([
        'SYSTEM_ONE_MODEL' => 'multilingual',
        'SYSTEM_ONE_EVENTS' => 'false',
        'SYSTEM_ONE_EVENTS_INCLUDE_CONTEXT' => 'true',
        'SYSTEM_ONE_CACHE' => 'true',
        'SYSTEM_ONE_CACHE_STORE' => 'redis',
        'SYSTEM_ONE_CACHE_TTL' => '600',
    ]);

    expect($config['connections']['local']['model'])->toBe('multilingual')
        ->and($config['events'])->toBe(['enabled' => false, 'include_context' => true])
        ->and($config['cache'])->toBe(['enabled' => true, 'store' => 'redis', 'ttl' => '600']);
});

it('takes the local base URL, key and timeout from the environment', function (): void {
    $config = systemOneConfigWith([
        'SYSTEM_ONE_URL' => 'http://laya:8000',
        'SYSTEM_ONE_API_KEY' => 'secret',
        'SYSTEM_ONE_TIMEOUT' => '5',
    ]);

    expect($config['connections'])->toBe([
        'local' => ['url' => 'http://laya:8000', 'api_key' => 'secret', 'timeout' => '5', 'model' => null],
    ]);
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
