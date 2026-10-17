<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Orchestra\Testbench\Concerns\WithWorkbench;

/*
 * The workbench's /choose page asks one choice question (Workbench\App\Enums\Department)
 * through the SystemOne facade and renders the chosen case, both confidences and the
 * distribution, or the package exception when the decision fails.
 */

uses(WithWorkbench::class);

/** @return array<string, mixed> */
function departmentResponse(string $choice = 'billing'): array
{
    return ['answers' => ['department' => [
        'type' => 'choice',
        'choice' => $choice,
        'probabilities' => ['billing' => 0.9281, 'technical' => 0.0412, 'other' => 0.0307],
        'confidence' => 0.4534,
        'answer_confidence' => 0.9281,
    ]]];
}

it('renders the chosen case, both confidences and the distribution in enum order', function (): void {
    Http::fake(['*' => Http::response(departmentResponse())]);

    $this->get('/choose?text='.urlencode('We were billed twice.'))
        ->assertOk()
        ->assertSee('data-value="Billing"', escape: false)
        ->assertSee('data-answer-confidence="0.9281"', escape: false)
        ->assertSee('data-confidence="0.4534"', escape: false)
        ->assertSee('data-profile="laya"', escape: false)
        ->assertSee('data-confident="yes"', escape: false)
        ->assertSeeInOrder(['data-case="Billing"', 'data-case="Technical"', 'data-case="Other"'], escape: false)
        ->assertSee('data-probability="0.0412"', escape: false)
        ->assertSee('We were billed twice.');

    // The body as sent: criteria are a JSON object on the wire (a stdClass in the codec).
    Http::assertSent(fn (Request $request): bool => json_decode($request->body(), true) === ['state' => 'We were billed twice.', 'questions' => ['department' => [
        'type' => 'choice',
        'instructions' => 'Which department should handle this?',
        'criteria' => [
            'billing' => 'invoices, payments, refunds',
            'technical' => 'bugs, outages, system errors',
            'other' => 'everything else',
        ],
    ]]]);
});

it('uses a default ticket when no usable text is given', function (string $query): void {
    Http::fake(['*' => Http::response(departmentResponse())]);

    $this->get('/choose?'.$query)->assertOk()->assertSee('billed twice for March');
})->with(['none' => '', 'array' => 'text[]=x', 'blank' => 'text=%20']);

it('renders a label outside the enum as a 502 MalformedResponse page', function (): void {
    Http::fake(['*' => Http::response(departmentResponse('sales'))]);

    $this->get('/choose')
        ->assertStatus(502)
        ->assertSee('data-error="MalformedResponse"', escape: false)
        ->assertSee('names &#039;sales&#039;, which is not an option', escape: false);
});

it('renders an unreachable server as a 503 page', function (): void {
    Http::fake(['*' => Http::failedConnection('Connection refused')]);

    $this->get('/choose')
        ->assertStatus(503)
        ->assertSee('data-error="HostUnavailable"', escape: false);
});

it('links to the choice page from the status page', function (): void {
    $this->get('/')->assertOk()->assertSee('href="/choose"', escape: false);
});
