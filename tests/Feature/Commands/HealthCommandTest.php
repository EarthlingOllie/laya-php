<?php

declare(strict_types=1);

use Illuminate\Console\Command;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/** laya-serve's answer to an authorised GET /health (tests/Fixtures/laya/provisional/health.json). */
function healthyBody(): array
{
    return [
        'status' => 'ok',
        'loaded' => ['english', 'multilingual'],
        'revisions' => ['english' => 'f00dfeed', 'multilingual' => null],
        'device' => 'cpu',
    ];
}

it('prints the connection, its status and the loaded checkpoints', function (): void {
    config(['system-one.connections.local.url' => 'http://laya.test:8000', 'system-one.connections.local.api_key' => 'sk-1']);
    Http::fake(['*' => Http::response(healthyBody())]);

    $this->artisan('system-one:health')
        ->expectsOutput('Connection: local (http://laya.test:8000)')
        ->expectsOutput('Status: ok')
        ->expectsOutput('Checkpoints: english (revision f00dfeed), multilingual')
        ->expectsOutput('Device: cpu')
        ->assertExitCode(Command::SUCCESS);

    Http::assertSent(fn (Request $request): bool => $request->url() === 'http://laya.test:8000/health'
        && $request->method() === 'GET'
        && $request->hasHeader('Authorization', 'Bearer sk-1'));
});

it('checks the connection named by --connection', function (): void {
    config(['system-one.connections.staging' => ['url' => 'https://staging.test/laya']]);
    Http::fake(['*' => Http::response(healthyBody())]);

    $this->artisan('system-one:health', ['--connection' => 'staging'])
        ->expectsOutput('Connection: staging (https://staging.test/laya)')
        ->assertExitCode(Command::SUCCESS);

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://staging.test/laya/health');
});

it('says when the server is up but has no checkpoint loaded yet', function (): void {
    Http::fake(['*' => Http::response(['status' => 'ok', 'loaded' => [], 'revisions' => []])]);

    $this->artisan('system-one:health')
        ->expectsOutput('Status: ok')
        ->expectsOutput('Checkpoints: none loaded yet (laya-serve loads them at start, or on the first decision when LAYA_PRELOAD=0)')
        ->doesntExpectOutputToContain('Device:')
        ->assertExitCode(Command::SUCCESS);
});

it('says when the server does not list its checkpoints because the API key is missing or wrong', function (): void {
    Http::fake(['*' => Http::response(['status' => 'ok'])]);

    $this->artisan('system-one:health')
        ->expectsOutput('Status: ok')
        ->expectsOutput('Checkpoints: not reported (laya-serve lists them only to requests with its API key: check SYSTEM_ONE_API_KEY)')
        ->assertExitCode(Command::SUCCESS);
});

it('exits with 1 when the server cannot be reached, with the URL and key masked', function (): void {
    config(['system-one.connections.local.url' => 'http://ollie:pw@laya.test:8000', 'system-one.connections.local.api_key' => 'sk-secret']);
    Http::fake(['*' => Http::failedConnection('cURL error 7: refused (sk-secret)')]);

    $this->artisan('system-one:health')
        ->expectsOutput('Connection: local (http://***@laya.test:8000)')
        ->expectsOutput('Unreachable: The model server at http://***@laya.test:8000 could not be reached: cURL error 7: refused (***)')
        ->doesntExpectOutputToContain('sk-secret')
        ->doesntExpectOutputToContain('pw@')
        ->assertExitCode(Command::FAILURE);
});

it('exits with 1 when the server reports itself unhealthy', function (): void {
    Http::fake(['*' => Http::response(['status' => 'loading', 'loaded' => []])]);

    $this->artisan('system-one:health')
        ->expectsOutput('Status: loading')
        ->expectsOutput('Unhealthy: the server reports status "loading", not "ok".')
        ->assertExitCode(Command::FAILURE);
});

it('exits with 1 when the server answers with a 5xx', function (): void {
    Http::fake(['*' => Http::response(['detail' => 'server busy'], 503)]);

    $this->artisan('system-one:health')
        ->expectsOutput('Unhealthy: The model server is unavailable (HTTP 503): server busy')
        ->assertExitCode(Command::FAILURE);
});

it('exits with 1 when the server refuses the health check', function (): void {
    Http::fake(['*' => Http::response(['detail' => 'Not Found'], 404)]);

    $this->artisan('system-one:health')
        ->expectsOutput('Unhealthy: GET /health was refused with HTTP 404: Not Found (is this a laya-serve server?)')
        ->assertExitCode(Command::FAILURE);
});

it('exits with 1 when the server refuses the health check without a detail', function (): void {
    Http::fake(['*' => Http::response('', 401)]);

    $this->artisan('system-one:health')
        ->expectsOutput('Unhealthy: GET /health was refused with HTTP 401 (is this a laya-serve server?)')
        ->assertExitCode(Command::FAILURE);
});

it('exits with 1 when the health answer is not what laya-serve sends', function (): void {
    Http::fake(['*' => Http::response('<html>', 200)]);

    $this->artisan('system-one:health')
        ->expectsOutput('Unhealthy: The response is not a JSON object.')
        ->assertExitCode(Command::FAILURE);
});

it('exits with 2 for a connection that is not configured', function (): void {
    Http::fake();

    $this->artisan('system-one:health', ['--connection' => 'nope'])
        ->expectsOutput("System One connection 'nope' is not configured in config/system-one.php.")
        ->assertExitCode(Command::INVALID);

    Http::assertNothingSent();
});
