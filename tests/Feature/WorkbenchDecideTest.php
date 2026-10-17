<?php

declare(strict_types=1);

use EarthlingOllie\SystemOneClassification\Laravel\Events\DecisionMade;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
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

it('sends ?min_confidence= and renders the abstention and the call metadata', function (): void {
    Http::fake(['*' => Http::response([
        'model' => 'laya-rl-agent',
        'answers' => ['churn' => ['type' => 'noul', 'noul' => 0.5, 'low_confidence' => true, 'abstention' => 'abstained', 'abstention_threshold' => 0.9]],
        'usage' => ['input_tokens' => 44, 'output_tokens' => 0],
        'routing' => ['model' => 'english', 'reason' => 'English Latin text'],
    ])]);

    $this->get('/decide?min_confidence=0.9')
        ->assertOk()
        ->assertSee('data-abstained="yes"', escape: false)
        ->assertSee('data-abstention="Abstained"', escape: false)
        ->assertSee('data-abstention-threshold="0.9"', escape: false)
        ->assertSee('data-model="laya-rl-agent"', escape: false)
        ->assertSee('data-checkpoint="english"', escape: false)
        ->assertSee('data-routing-reason="English Latin text"', escape: false)
        ->assertSee('data-input-tokens="44"', escape: false)
        ->assertSee('data-output-tokens="0"', escape: false)
        ->assertSee('data-latency-ms="', escape: false);

    Http::assertSent(fn (Request $request): bool => $request['min_confidence'] === 0.9);
});

it('sends no minimum confidence without a usable ?min_confidence=', function (string $query): void {
    Http::fake(['*' => Http::response(['answers' => ['churn' => ['type' => 'noul', 'noul' => 0.5]]])]);

    $this->get('/decide?'.$query)
        ->assertOk()
        ->assertSee('data-abstained="no"', escape: false)
        ->assertSee('data-abstention="NotRequested"', escape: false)
        ->assertSee('data-abstention-threshold=""', escape: false)
        ->assertSee('data-model=""', escape: false);

    Http::assertSent(fn (Request $request): bool => ! array_key_exists('min_confidence', $request->data()));
})->with(['absent' => '', 'empty' => 'min_confidence=', 'not a number' => 'min_confidence=high', 'array' => 'min_confidence[]=1']);

it('renders an out-of-range ?min_confidence= as the package error with a 422 status', function (string $value): void {
    Http::fake();

    // The query string is the caller's input, so the page answers 422, not a 500 of its own.
    $this->get("/decide?min_confidence={$value}")
        ->assertStatus(422)
        ->assertSee('data-error="InvalidDecision"', escape: false)
        ->assertSee("The minimum confidence must be between 0 and 1, got {$value}.");

    Http::assertNothingSent();
})->with(['above' => '1.5', 'a percent' => '80', 'below' => '-0.1']);

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

it('shows the DecisionMade event the decision dispatched, without the context text', function (): void {
    Http::fake(['*' => Http::response([
        'model' => 'laya-rl-agent',
        'answers' => ['churn' => ['type' => 'noul', 'noul' => 0.7]],
        'routing' => ['model' => 'english', 'reason' => 'English Latin text'],
        'usage' => ['input_tokens' => 57, 'output_tokens' => 0],
    ])]);

    $response = $this->get('/decide?text='.urlencode('Jane Doe will cancel.'))->assertOk();

    $response->assertSee('data-event="DecisionMade"', escape: false)
        ->assertSee('data-connection="local"', escape: false)
        ->assertSee('data-question-keys="churn"', escape: false)
        ->assertSee('data-cached="no"', escape: false)
        ->assertSee('data-has-context="no"', escape: false)
        ->assertSee('&quot;checkpoint&quot;: &quot;english&quot;', escape: false);
    // The text is on the page above the events, but not inside the events panel.
    expect(Str::after((string) $response->getContent(), 'id="events"'))->not->toContain('Jane Doe');
});

it('shows the context in the event with ?include_context=1', function (): void {
    Http::fake(['*' => Http::response(['answers' => ['churn' => ['type' => 'noul', 'noul' => 0.7]]])]);

    $response = $this->get('/decide?include_context=1&text='.urlencode('Jane Doe will cancel.'))->assertOk();

    $response->assertSee('data-has-context="yes"', escape: false);
    expect(Str::after((string) $response->getContent(), 'id="events"'))->toContain('Jane Doe will cancel.');
});

it('shows the DecisionFailed event with the exception type', function (): void {
    Http::fake(['*' => Http::response(['detail' => 'no'], 422)]);

    $this->get('/decide')
        ->assertStatus(502)
        ->assertSee('data-event="DecisionFailed"', escape: false)
        ->assertSee('data-exception="RejectedByModel"', escape: false);
});

it('answers a repeated decision from the cache with ?cache=1, and misses when a control changes', function (): void {
    config(['system-one.cache.store' => 'array']);
    Http::fake([
        '*/health' => Http::response(['revisions' => ['english' => 'rev-a']]),
        '*/v1/systemone' => Http::response(['answers' => ['churn' => ['type' => 'noul', 'noul' => 0.7]], 'routing' => ['model' => 'english']]),
    ]);
    $posts = fn (): int => Http::recorded(fn (Request $request): bool => str_ends_with($request->url(), '/v1/systemone'))->count();

    $this->get('/decide?cache=1')->assertOk()->assertSee('data-cached="no"', escape: false)->assertSee('data-cache="on"', escape: false);
    $this->get('/decide?cache=1')->assertOk()->assertSee('data-cached="yes"', escape: false);
    expect($posts())->toBe(1);

    $this->get('/decide?cache=1&min_confidence=0.5')->assertOk()->assertSee('data-cached="no"', escape: false);
    expect($posts())->toBe(2);
});

it('lists only the events of its own request, with one listener however many requests the app serves', function (): void {
    Http::fake(['*' => Http::response(['answers' => ['churn' => ['type' => 'noul', 'noul' => 0.7]]])]);

    $this->get('/decide');
    $this->get('/decide');
    $page = (string) $this->get('/decide')->getContent();

    expect(substr_count($page, 'data-event="DecisionMade"'))->toBe(1)
        ->and(Event::getListeners(DecisionMade::class))->toHaveCount(1);
});

it('leaves caching off without ?cache=1', function (): void {
    Http::fake(['*' => Http::response(['answers' => ['churn' => ['type' => 'noul', 'noul' => 0.7]]])]);

    $this->get('/decide')->assertOk()->assertSee('data-cache="off"', escape: false);
    $this->get('/decide')->assertOk()->assertDontSee('data-cached="yes"', escape: false);

    Http::assertSentCount(2);
});

// Review finding: the query string's settings are for its own request only; an app serving
// several (Octane, a test) must not carry them into the next one.
it('turns ?cache=1 and ?include_context=1 off again for the next request', function (): void {
    config(['system-one.cache.store' => 'array']);
    Http::fake([
        '*/health' => Http::response(['revisions' => ['english' => 'rev-a']]),
        '*/v1/systemone' => Http::response(['answers' => ['churn' => ['type' => 'noul', 'noul' => 0.7]], 'routing' => ['model' => 'english']]),
    ]);

    $this->get('/decide?cache=1&include_context=1')->assertOk()->assertSee('data-has-context="yes"', escape: false);
    $this->get('/decide')
        ->assertOk()
        ->assertSee('data-cache="off"', escape: false)
        ->assertSee('data-cached="no"', escape: false)
        ->assertSee('data-has-context="no"', escape: false);

    expect(Http::recorded(fn (Request $request): bool => str_ends_with($request->url(), '/v1/systemone')))->toHaveCount(2)
        ->and(config('system-one.cache.enabled'))->toBeFalse()
        ->and(config('system-one.events.include_context'))->toBeFalse();
});

// #7's request controls from the query string, on every question page and /decide-many.
it('sends ?lang=, ?lang_guess=, ?max_len= and ?head_max_len= as the request controls', function (string $page): void {
    Http::fake(['*' => function (Request $request) {
        $answer = ['type' => 'noul', 'noul' => 0.5];

        return str_ends_with($request->url(), '/batch')
            ? Http::response(['results' => array_map(static fn (): array => ['answers' => ['churn' => $answer]], (array) $request['states'])])
            : Http::response(['answers' => [
                'churn' => $answer,
                'department' => ['type' => 'choice', 'choice' => 'billing', 'confidence' => 0.1, 'probabilities' => ['billing' => 0.8, 'technical' => 0.1, 'other' => 0.1]],
                'urgency' => ['type' => 'score', 'score' => 0.0, 'confidence' => 0.1, 'legend' => ['can wait', 'soon', 'today', 'blocking'], 'probabilities' => ['0' => 1.0, '1' => 0.0, '2' => 0.0, '3' => 0.0]],
            ]]);
    }]);

    $this->get("{$page}?lang=de&lang_guess=de_AT&max_len=64&head_max_len=16")->assertOk();

    Http::assertSent(fn (Request $request): bool => $request['lang'] === 'de'
        && $request['lang_guess'] === 'de_AT'
        && $request['max_len'] === 64
        && $request['head_max_len'] === 16);
})->with(['/decide', '/choose', '/score', '/decide-many']);

it('sends no control the query string leaves out or leaves blank', function (): void {
    Http::fake(['*' => Http::response(['answers' => ['churn' => ['type' => 'noul', 'noul' => 0.5]]])]);

    // Like ?min_confidence=high, a length that is not a whole number sends none.
    $this->get('/decide?lang=%20&max_len=&head_max_len=ten')->assertOk();

    Http::assertSent(fn (Request $request): bool => array_keys($request->data()) === ['state', 'questions']);
});

it('renders a control the query string makes invalid as the package error with a 422 status', function (string $query): void {
    Http::fake();

    $this->get("/decide?{$query}")->assertStatus(422)->assertSee('data-error="InvalidDecision"', escape: false);

    Http::assertNothingSent();
})->with(['max_len=0', 'head_max_len=-3']);
