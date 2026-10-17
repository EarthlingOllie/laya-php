<?php

declare(strict_types=1);

use EarthlingOllie\SystemOneClassification\Domain\Context;
use EarthlingOllie\SystemOneClassification\Domain\Decision;
use EarthlingOllie\SystemOneClassification\Domain\Question;
use EarthlingOllie\SystemOneClassification\Exceptions\HostUnavailable;
use EarthlingOllie\SystemOneClassification\Exceptions\InvalidDecision;
use EarthlingOllie\SystemOneClassification\Exceptions\MalformedResponse;
use EarthlingOllie\SystemOneClassification\Exceptions\RejectedByModel;
use EarthlingOllie\SystemOneClassification\Exceptions\SystemOneException;
use EarthlingOllie\SystemOneClassification\Laravel\Events\DecisionFailed;
use EarthlingOllie\SystemOneClassification\Laravel\Events\DecisionMade;
use EarthlingOllie\SystemOneClassification\Laravel\Facades\SystemOne;
use EarthlingOllie\SystemOneClassification\Laravel\SystemOneServiceProvider;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;

/*
 * Status page of the workbench app: shows whether the package's service provider was
 * registered by the booted application.
 */
Route::get('/', function (Application $app): string {
    $loaded = $app->providerIsLoaded(SystemOneServiceProvider::class);
    $provider = e(SystemOneServiceProvider::class);
    $state = $loaded ? 'yes' : 'no';
    $label = $loaded ? 'registered and booted' : 'NOT registered';
    $laravel = e($app->version());
    $php = e(PHP_VERSION);

    return <<<HTML
        <!doctype html>
        <html lang="en">
        <head><meta charset="utf-8"><title>System One Classification</title></head>
        <body style="font-family: system-ui, sans-serif; margin: 3rem;">
            <h1>System One Classification</h1>
            <p>Laravel {$laravel} on PHP {$php}</p>
            <p id="provider" data-provider-loaded="{$state}"><code>{$provider}</code>: <strong>{$label}</strong></p>
            <p><a href="/decide">Ask one yes/no question</a></p>
        </body>
        </html>
        HTML;
});

/*
 * One yes/no question, end to end: SystemOne::decide() against the default connection
 * (SYSTEM_ONE_URL). A non-blank ?text= replaces the default ticket, and a numeric
 * ?min_confidence= turns on the abstention gate; the page shows the abstention and the call
 * metadata (model, checkpoint, routing reason, tokens, latency). A package exception is
 * rendered as an error page: 503 when the server is unreachable or down (HostUnavailable),
 * 502 when it refused the decision or answered nonsense (RejectedByModel,
 * MalformedResponse), 422 when the query string makes the decision invalid (InvalidDecision),
 * 500 for this app's own mistakes (InvalidConfiguration and the rest).
 * Messages are shown as the package wrote them; HostUnavailable's are already redacted.
 *
 * Under `composer serve` the shell environment does not reach this app (Testbench turns
 * off putenv and the PHP built-in server fills neither $_ENV nor $_SERVER from it): to
 * use another server or a key, add SYSTEM_ONE_URL / SYSTEM_ONE_API_KEY to the `env:`
 * list in testbench.yaml.
 */
/*
 * The decide page lists the events its decision dispatched. One listener, registered once
 * when the routes load, keeps them on the current request, so a long-lived app (Octane, a
 * test making several requests) neither piles up listeners nor mixes requests' events.
 */

Event::listen([DecisionMade::class, DecisionFailed::class], function (DecisionMade|DecisionFailed $event): void {
    $attributes = request()->attributes;
    $seen = $attributes->get('workbench.system-one-events');
    $attributes->set('workbench.system-one-events', [...(is_array($seen) ? $seen : []), $event]);
});

Route::get('/decide', function (Request $request): Response {
    // A query string can make ?text= an array (?text[]=x), and Context::text() refuses blank
    // text: anything but a non-blank string gets the default ticket.
    $text = $request->query('text');
    if (! is_string($text) || trim($text) === '') {
        $text = 'Hi, we were billed twice for March. Please refund the duplicate charge today or we will cancel our plan.';
    }
    // A numeric ?min_confidence= is sent as withMinConfidence(); anything else sends none.
    $minConfidence = $request->query('min_confidence');
    $minConfidence = is_string($minConfidence) && is_numeric($minConfidence) ? (float) $minConfidence : null;
    $churn = Question::yesNo('churn', 'Does the customer threaten to cancel or leave?');
    $status = 200;

    // ?cache=1 turns the result cache on for this request (the `file` store unless
    // SYSTEM_ONE_CACHE_STORE names one), ?include_context=1 puts the text in the events.
    $cache = $request->query('cache') === '1';
    if ($cache) {
        config(['system-one.cache' => ['enabled' => true, 'store' => config('system-one.cache.store') ?? 'file', 'ttl' => 300]]);
    }
    if ($request->query('include_context') === '1') {
        config(['system-one.events.include_context' => true]);
    }

    try {
        $decision = Decision::about(Context::text($text))->ask($churn);
        if ($minConfidence !== null) {
            $decision = $decision->withMinConfidence($minConfidence);
        }
        $result = SystemOne::decide($decision);
        $answer = $result->get($churn);
        $meta = $result->meta;
        $probability = e((string) $answer->probability);
        $verdict = e($answer->verdict(yes: 0.8, no: 0.2)->name);
        $isYes = $answer->isYes() ? 'yes' : 'no';
        $abstained = $answer->abstained ? 'yes' : 'no';
        $abstention = e($answer->abstention->name);
        $threshold = e((string) $answer->abstentionThreshold);
        $model = e((string) $meta->model);
        $checkpoint = e((string) $meta->checkpoint);
        $reason = e((string) $meta->routingReason);
        $inputTokens = e((string) $meta->inputTokens);
        $outputTokens = e((string) $meta->outputTokens);
        $latency = e($meta->latencyMs === null ? '' : number_format($meta->latencyMs, 1, '.', ''));
        $outcome = <<<HTML
            <dl id="answer" data-probability="{$probability}" data-verdict="{$verdict}" data-is-yes="{$isYes}"
                data-abstained="{$abstained}" data-abstention="{$abstention}" data-abstention-threshold="{$threshold}">
                <dt>probability</dt><dd>{$probability}</dd>
                <dt>isYes() (threshold 0.5)</dt><dd>{$isYes}</dd>
                <dt>verdict(yes: 0.8, no: 0.2)</dt><dd>{$verdict}</dd>
                <dt>abstained (best guess kept above)</dt><dd>{$abstained}</dd>
                <dt>abstention / threshold</dt><dd>{$abstention} / {$threshold}</dd>
            </dl>
            <h2>Call metadata</h2>
            <dl id="meta" data-model="{$model}" data-checkpoint="{$checkpoint}" data-routing-reason="{$reason}"
                data-input-tokens="{$inputTokens}" data-output-tokens="{$outputTokens}" data-latency-ms="{$latency}">
                <dt>model</dt><dd>{$model}</dd>
                <dt>checkpoint</dt><dd>{$checkpoint}</dd>
                <dt>routing reason</dt><dd>{$reason}</dd>
                <dt>tokens in / out</dt><dd>{$inputTokens} / {$outputTokens}</dd>
                <dt>latency (ms, client-side)</dt><dd>{$latency}</dd>
            </dl>
            HTML;
    } catch (SystemOneException $exception) {
        $status = match (true) {
            $exception instanceof HostUnavailable => 503,
            $exception instanceof RejectedByModel, $exception instanceof MalformedResponse => 502,
            // The page builds a valid decision unless the query string breaks it (?min_confidence=80).
            $exception instanceof InvalidDecision => 422,
            default => 500, // this app's own mistake: InvalidConfiguration and the rest
        };
        $type = e(class_basename($exception));
        $message = e($exception->getMessage());
        $outcome = <<<HTML
            <div id="error" data-error="{$type}" style="border: 2px solid #b00020; padding: 1rem;">
                <h2>{$type}</h2>
                <p>{$message}</p>
            </div>
            HTML;
    }

    $quoted = e($text);
    $question = e($churn->instructions);
    $cacheState = $cache ? 'on' : 'off';
    $dispatched = '';
    $events = $request->attributes->get('workbench.system-one-events');
    foreach (is_array($events) ? $events : [] as $event) {
        if (! $event instanceof DecisionMade && ! $event instanceof DecisionFailed) {
            continue;
        }
        $name = e(class_basename($event));
        $connection = e($event->connection);
        $keys = e(implode(',', $event->questionKeys));
        $hasContext = $event->context === null ? 'no' : 'yes';
        $extra = $event instanceof DecisionMade
            ? 'data-cached="'.($event->cached ? 'yes' : 'no').'"'
            : 'data-exception="'.e(class_basename($event->exception)).'"';
        // What a listener receives, minus the result's answers (shown above): the event's own fields.
        $fields = $event instanceof DecisionMade
            ? ['connection' => $event->connection, 'model' => $event->model, 'questionKeys' => $event->questionKeys, 'meta' => $event->meta, 'latencyMs' => $event->latencyMs, 'cached' => $event->cached, 'context' => $event->context]
            : ['connection' => $event->connection, 'model' => $event->model, 'questionKeys' => $event->questionKeys, 'exception' => [class_basename($event->exception) => $event->exception->getMessage()], 'context' => $event->context];
        $json = e((string) json_encode($fields, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION));
        $dispatched .= <<<HTML
            <li data-event="{$name}" data-connection="{$connection}" data-question-keys="{$keys}" data-has-context="{$hasContext}" {$extra}>
                <strong>{$name}</strong><pre>{$json}</pre>
            </li>
            HTML;
    }

    return new Response(<<<HTML
        <!doctype html>
        <html lang="en">
        <head><meta charset="utf-8"><title>System One: decide</title></head>
        <body style="font-family: system-ui, sans-serif; margin: 3rem; max-width: 44rem;">
            <h1>One yes/no question</h1>
            <p><strong>Text:</strong> {$quoted}</p>
            <p><strong>Question:</strong> {$question}</p>
            {$outcome}
            <h2>Events dispatched</h2>
            <p id="cache" data-cache="{$cacheState}">Result cache: <strong>{$cacheState}</strong> (<code>?cache=1</code>)</p>
            <ol id="events">{$dispatched}</ol>
        </body>
        </html>
        HTML, $status);
});
