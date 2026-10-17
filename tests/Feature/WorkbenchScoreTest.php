<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Orchestra\Testbench\Concerns\WithWorkbench;

/*
 * The workbench's /score page asks one score question (Workbench\App\Enums\Urgency) through
 * the SystemOne facade and renders the most likely level, the expected level, both
 * confidences and the distribution, or the package exception when the decision fails.
 */

uses(WithWorkbench::class);

/** @return array<string, mixed> */
function urgencyResponse(array $overrides = []): array
{
    return ['answers' => ['urgency' => [
        'type' => 'score',
        'score' => 2.1875,
        'legend' => ['0' => 'can wait', '1' => 'soon', '2' => 'today', '3' => 'blocking'],
        'probabilities' => ['0' => 0.0625, '1' => 0.0625, '2' => 0.5, '3' => 0.375],
        'confidence' => 0.3043,
        'answer_confidence' => 0.5,
        ...$overrides,
    ]]];
}

it('renders the most likely level, the expected level, both confidences and the distribution in level order', function (): void {
    Http::fake(['*' => Http::response(urgencyResponse())]);

    $this->get('/score?text='.urlencode('The site is down.'))
        ->assertOk()
        ->assertSee('data-value="High"', escape: false)
        ->assertSee('data-expected="2.1875"', escape: false)
        ->assertSee('data-answer-confidence="0.5"', escape: false)
        ->assertSee('data-confidence="0.3043"', escape: false)
        ->assertSee('data-profile="laya"', escape: false)
        ->assertSee('data-confident="no"', escape: false)
        ->assertSeeInOrder(['data-case="Low"', 'data-case="Medium"', 'data-case="High"', 'data-case="Critical"'], escape: false)
        ->assertSee('data-level="3" data-probability="0.375"', escape: false)
        ->assertSee('The site is down.');

    // The levels go out as a list of descriptions, lowest backing value first.
    Http::assertSent(fn (Request $request): bool => json_decode($request->body(), true) === ['state' => 'The site is down.', 'questions' => ['urgency' => [
        'type' => 'score',
        'instructions' => 'How urgent is this?',
        'criteria' => ['can wait', 'soon', 'today', 'blocking'],
    ]]]);
});

it('uses a default ticket when no usable text is given', function (string $query): void {
    Http::fake(['*' => Http::response(urgencyResponse())]);

    $this->get('/score?'.$query)->assertOk()->assertSee('billed twice for March');
})->with(['none' => '', 'array' => 'text[]=x', 'blank' => 'text=%20']);

it('renders a legend that does not match the enum as a 502 MalformedResponse page', function (): void {
    Http::fake(['*' => Http::response(urgencyResponse(['legend' => ['0' => 'calm', '1' => 'soon', '2' => 'today', '3' => 'blocking']]))]);

    $this->get('/score')
        ->assertStatus(502)
        ->assertSee('data-error="MalformedResponse"', escape: false)
        ->assertSee('has a legend that does not match the levels of', escape: false);
});

it('renders an unreachable server as a 503 page', function (): void {
    Http::fake(['*' => Http::failedConnection('Connection refused')]);

    $this->get('/score')
        ->assertStatus(503)
        ->assertSee('data-error="HostUnavailable"', escape: false);
});

it('links to the score page from the status page', function (): void {
    $this->get('/')->assertOk()->assertSee('href="/score"', escape: false);
});
