<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Orchestra\Testbench\Concerns\WithWorkbench;

/*
 * The workbench picks a named connection with ?connection= on every question page, and lists
 * the configured connections on /connections, without ever showing a key.
 */

uses(WithWorkbench::class);

const WORKBENCH_KEY = 'sk-workbench-secret-42';

it('asks the connection named by ?connection=, with its key as the bearer token', function (string $page, array $answer): void {
    config(['system-one.connections.jev.api_key' => WORKBENCH_KEY]);
    Http::fake(['*' => Http::response(['answers' => $answer])]);

    $this->get("{$page}?connection=jev")
        ->assertOk()
        ->assertSee('data-connection="jev"', escape: false)
        ->assertDontSee(WORKBENCH_KEY);

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.typesafe.ai/v1/systemone'
        && $request->header('Authorization') === ['Bearer '.WORKBENCH_KEY]);
})->with([
    '/decide' => ['/decide', ['churn' => ['type' => 'noul', 'noul' => 0.5]]],
    '/choose' => ['/choose', ['department' => [
        'type' => 'choice', 'choice' => 'billing', 'confidence' => 0.4,
        'probabilities' => ['billing' => 0.8, 'technical' => 0.1, 'other' => 0.1],
    ]]],
    '/score' => ['/score', ['urgency' => [
        'type' => 'score', 'score' => 1.0, 'confidence' => 0.4,
        'legend' => ['0' => 'can wait', '1' => 'soon', '2' => 'today', '3' => 'blocking'],
        'probabilities' => ['0' => 0.1, '1' => 0.7, '2' => 0.1, '3' => 0.1],
    ]]],
]);

it('uses the default connection without a usable ?connection=', function (string $query): void {
    Http::fake(['*' => Http::response(['answers' => ['churn' => ['type' => 'noul', 'noul' => 0.5]]])]);

    $this->get('/decide?'.$query)->assertOk()->assertSee('data-connection="local"', escape: false);

    Http::assertSent(fn (Request $request): bool => $request->url() === 'http://localhost:8000/v1/systemone');
})->with(['none' => '', 'blank' => 'connection=%20', 'array' => 'connection[]=jev']);

it('shows the configuration error for a remote connection without a key, sending nothing', function (string $name): void {
    config(["system-one.connections.{$name}.api_key" => '']);
    Http::fake();

    $this->get("/decide?connection={$name}")
        ->assertStatus(500)
        ->assertSee('data-error="InvalidConfiguration"', escape: false)
        ->assertSee("System One connection &#039;{$name}&#039; is a remote host, so it needs an &#039;api_key&#039;", escape: false);

    Http::assertNothingSent();
})->with(['laya-hosted', 'jev']);

it('shows the configuration error for a connection that is not configured', function (): void {
    $this->get('/choose?connection=nope')
        ->assertStatus(500)
        ->assertSee('System One connection &#039;nope&#039; is not configured', escape: false);
});

it('asks the 101-topic choice on the chosen connection, showing its profile', function (): void {
    config(['system-one.connections.jev.api_key' => WORKBENCH_KEY]);
    Http::fake(['*' => Http::response(['detail' => 'nope'], 422)]);

    $this->get('/limits?connection=jev')
        ->assertStatus(502)
        ->assertSee('data-connection="jev"', escape: false)
        ->assertSee('data-profile="jev"', escape: false);

    Http::assertSentCount(1);
});

it('lists every configured connection, whether it has a key, and never the key', function (): void {
    config([
        'system-one.connections.jev.api_key' => WORKBENCH_KEY,
        'system-one.connections.laya-hosted.api_key' => null,
        'system-one.connections.broken' => ['url' => ''],
    ]);

    $this->get('/connections')
        ->assertOk()
        ->assertSee('<tr data-connection="local" data-default="yes" data-url="http://localhost:8000" data-remote="no" data-key="no" data-profile="laya" data-model="" data-retries="2" data-retry-delay="200" data-usable="yes">', escape: false)
        ->assertSee('<tr data-connection="laya-hosted" data-default="no" data-url="https://api.laya-ai.com" data-remote="yes" data-key="no" data-profile="laya" data-model="" data-retries="2" data-retry-delay="500" data-usable="no">', escape: false)
        ->assertSee('<tr data-connection="jev" data-default="no" data-url="https://api.typesafe.ai" data-remote="yes" data-key="yes" data-profile="jev" data-model="" data-retries="2" data-retry-delay="500" data-usable="yes">', escape: false)
        ->assertSee('<tr data-connection="broken" data-usable="no">', escape: false)
        ->assertSee('System One connection &#039;broken&#039; needs a &#039;url&#039;.', escape: false)
        ->assertSee('href="/decide?connection=jev"', escape: false)
        ->assertDontSee(WORKBENCH_KEY);
});

// Review finding: /limits showed the profile as the raw config wrote it, even one the manager
// refuses; it now shows what the manager reads.
it('shows no profile on /limits for a config the manager refuses', function (): void {
    config(['system-one.connections.local.profile' => 'JEV']);
    Http::fake();

    $this->get('/limits')
        ->assertStatus(500)
        ->assertSee('data-profile=""', escape: false)
        ->assertSee('data-error="InvalidConfiguration"', escape: false);

    Http::assertNothingSent();
});

// Review finding: the page printed each URL as configured, credentials included.
it('masks the credentials in a connection URL', function (): void {
    config(['system-one.connections.lan' => ['url' => 'http://ollie:s3cret-pass@laya.lan:8000/laya?api_key=q-secret']]);

    $this->get('/connections')
        ->assertOk()
        ->assertSee('data-url="http://***@laya.lan:8000/laya?api_key=***"', escape: false)
        ->assertDontSee('s3cret-pass')
        ->assertDontSee('q-secret')
        ->assertDontSee('ollie');
});

it('links to the connections page from the status page', function (): void {
    $this->get('/')->assertOk()->assertSee('href="/connections"', escape: false);
});
