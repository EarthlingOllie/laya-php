<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Orchestra\Testbench\Concerns\WithWorkbench;

/*
 * The workbench's /decide-many page asks one yes/no question about several texts through
 * SystemOne::decideMany() and renders one row per text, in order, with the batch usage.
 */

uses(WithWorkbench::class);

/** A fake batch endpoint answering each state with the given probabilities, in turn. */
function fakeBatch(float ...$probabilities): void
{
    Http::fake(['*' => Http::response([
        'results' => array_map(static fn (float $p): array => ['answers' => ['churn' => ['type' => 'noul', 'noul' => $p]]], $probabilities),
        'total_usage' => ['input_tokens' => 99, 'output_tokens' => 0],
    ])]);
}

it('renders one row per text, in order, with the batch usage', function (): void {
    fakeBatch(0.9, 0.1);

    $this->get('/decide-many?text[]='.urlencode('We will cancel.').'&text[]='.urlencode('Thanks!'))
        ->assertOk()
        ->assertSeeInOrder([
            'data-position="0" data-probability="0.9" data-verdict="Yes"',
            'We will cancel.',
            'data-position="1" data-probability="0.1" data-verdict="No"',
            'Thanks!',
        ], escape: false)
        ->assertSee('data-results="2"', escape: false)
        ->assertSee('data-input-tokens="99"', escape: false);

    Http::assertSent(fn (Request $request): bool => $request->url() === 'http://localhost:8000/v1/systemone/batch'
        && $request['states'] === ['We will cancel.', 'Thanks!']);
});

it('uses three default tickets when no usable text is given', function (string $query): void {
    fakeBatch(0.5, 0.5, 0.5);

    $this->get('/decide-many'.$query)->assertOk()->assertSee('data-results="3"', escape: false);

    Http::assertSent(fn (Request $request): bool => is_array($request['states']) && count($request['states']) === 3);
})->with(['none' => '', 'blank only' => '?text[]=%20&text[]=', 'a string' => '?text=x', 'nested' => '?text[a][b]=x']);

it('skips blank texts and keeps the rest in order', function (): void {
    fakeBatch(0.2, 0.3);

    $this->get('/decide-many?text[]=First&text[]=%20&text[]=Second')->assertOk();

    Http::assertSent(fn (Request $request): bool => $request['states'] === ['First', 'Second']);
});

it('renders the package exception as an error page', function (): void {
    Http::fake(['*' => Http::response(['detail' => 'too many states in batch (65 > 64)'], 413)]);

    $this->get('/decide-many?text[]=x')
        ->assertStatus(502)
        ->assertSee('data-error="RejectedByModel"', escape: false)
        ->assertSee('too many states in batch (65 &gt; 64)', escape: false);
});

it('escapes the texts', function (): void {
    fakeBatch(0.5);

    $this->get('/decide-many?text[]='.urlencode('<script>x</script>'))
        ->assertOk()
        ->assertDontSee('<script>x</script>', escape: false)
        ->assertSee('&lt;script&gt;x&lt;/script&gt;', escape: false);
});
