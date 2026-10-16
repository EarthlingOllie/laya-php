<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Orchestra\Testbench\Concerns\WithWorkbench;

/*
 * The workbench's /decide page asks one yes/no question through the SystemOne facade and
 * renders the answer, or the package exception when the decision fails.
 */

uses(WithWorkbench::class);

it('renders the probability and verdict of a yes/no question', function (): void {
    Http::fake(['*' => Http::response(['answers' => ['churn' => ['type' => 'noul', 'noul' => 0.91]]])]);

    $this->get('/decide?text='.urlencode('We will cancel our plan.'))
        ->assertOk()
        ->assertSee('data-probability="0.91"', escape: false)
        ->assertSee('data-verdict="Yes"', escape: false)
        ->assertSee('data-is-yes="yes"', escape: false)
        ->assertSee('We will cancel our plan.');

    Http::assertSent(fn (Request $request): bool => $request['state'] === 'We will cancel our plan.'
        && $request['questions'] === ['churn' => ['type' => 'noul', 'instructions' => 'Does the customer threaten to cancel or leave?']]);
});

it('renders Unsure and No verdicts', function (float $noul, string $verdict, string $isYes): void {
    Http::fake(['*' => Http::response(['answers' => ['churn' => ['type' => 'noul', 'noul' => $noul]]])]);

    $this->get('/decide')
        ->assertOk()
        ->assertSee("data-verdict=\"{$verdict}\"", escape: false)
        ->assertSee("data-is-yes=\"{$isYes}\"", escape: false);
})->with([[0.5, 'Unsure', 'yes'], [0.1, 'No', 'no']]);

it('uses a default ticket when no text is given', function (): void {
    Http::fake(['*' => Http::response(['answers' => ['churn' => ['type' => 'noul', 'noul' => 0.5]]])]);

    $this->get('/decide')->assertOk()->assertSee('billed twice for March');
});

it('renders HostUnavailable as a 503 page when the server is down', function (): void {
    Http::fake(['*' => Http::failedConnection('Connection refused')]);

    $this->get('/decide')
        ->assertStatus(503)
        ->assertSee('data-error="HostUnavailable"', escape: false)
        ->assertSee('could not be reached');
});

it('escapes the text and the error message', function (): void {
    Http::fake(['*' => Http::response(['detail' => '<b>busy</b>'], 503)]);

    $this->get('/decide?text='.urlencode('<script>x</script>'))
        ->assertStatus(503)
        ->assertDontSee('<b>busy</b>', escape: false)
        ->assertSee('&lt;b&gt;busy&lt;/b&gt;', escape: false);
});

it('renders what the model server got wrong with a 502 status', function (mixed $body, int $status, string $error): void {
    Http::fake(['*' => Http::response($body, $status)]);

    $this->get('/decide')
        ->assertStatus(502)
        ->assertSee("data-error=\"{$error}\"", escape: false);
})->with([
    'malformed' => ['<html>', 200, 'MalformedResponse'],
    'rejected' => [['detail' => 'invalid or missing bearer token'], 401, 'RejectedByModel'],
]);

it('renders a configuration error with a 500 status', function (): void {
    config(['system-one.default' => 'nope']);

    $this->get('/decide')
        ->assertStatus(500)
        ->assertSee('data-error="InvalidConfiguration"', escape: false)
        ->assertSee("System One connection 'nope' is not configured");
});

it('falls back to the default ticket when the text is not a string or is blank', function (string $query): void {
    Http::fake(['*' => Http::response(['answers' => ['churn' => ['type' => 'noul', 'noul' => 0.5]]])]);

    $this->get('/decide?'.$query)->assertOk()->assertSee('billed twice for March');

    Http::assertSent(fn (Request $request): bool => str_contains((string) $request['state'], 'billed twice for March'));
})->with(['array' => 'text[]=x', 'nested array' => 'text[a][b]=x', 'blank' => 'text=%20%20', 'empty' => 'text=']);
