<?php

declare(strict_types=1);

use EarthlingOllie\SystemOneClassification\Domain\Context;
use EarthlingOllie\SystemOneClassification\Domain\Decision;
use EarthlingOllie\SystemOneClassification\Domain\Question;
use EarthlingOllie\SystemOneClassification\Exceptions\HostUnavailable;
use EarthlingOllie\SystemOneClassification\Exceptions\InvalidConfiguration;
use EarthlingOllie\SystemOneClassification\Exceptions\InvalidDecision;
use EarthlingOllie\SystemOneClassification\Exceptions\MalformedResponse;
use EarthlingOllie\SystemOneClassification\Exceptions\RejectedByModel;
use EarthlingOllie\SystemOneClassification\Exceptions\SystemOneException;
use EarthlingOllie\SystemOneClassification\Laravel\Events\DecisionFailed;
use EarthlingOllie\SystemOneClassification\Laravel\Events\DecisionMade;
use EarthlingOllie\SystemOneClassification\Laravel\Facades\SystemOne;
use EarthlingOllie\SystemOneClassification\Laravel\SystemOneManager;
use EarthlingOllie\SystemOneClassification\Laravel\SystemOneServiceProvider;
use EarthlingOllie\SystemOneClassification\Model\LayaProfile;
use EarthlingOllie\SystemOneClassification\Result\Answer\Answer;
use EarthlingOllie\SystemOneClassification\Result\Meta;
use EarthlingOllie\SystemOneClassification\Transport\Redactor;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Workbench\App\Enums\Department;
use Workbench\App\Enums\Topic;
use Workbench\App\Enums\Urgency;

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
            <p><a href="/choose">Ask one choice question</a></p>
            <p><a href="/score">Ask one score question</a></p>
            <p><a href="/limits">Ask a choice over 101 topics</a> (more than Laya allows)</p>
            <p><a href="/connections">The configured connections</a> (every question page takes <code>?connection=</code>)</p>
            <p><a href="/decide-many">Ask it about several texts</a></p>
        </body>
        </html>
        HTML;
});

/*
 * The ticket text the question pages ask about. A non-blank ?text= replaces the default
 * ticket. A query string can also make it an array (?text[]=x), and Context::text() refuses
 * blank text, so anything but a non-blank string gets the default.
 */
$ticket = static function (Request $request): string {
    $text = $request->query('text');

    return is_string($text) && trim($text) !== ''
        ? $text
        : 'Hi, we were billed twice for March. Please refund the duplicate charge today or we will cancel our plan.';
};

/*
 * The checkpoint every question page pins with Decision::withModel(): a non-blank ?model=,
 * or null to let the server choose. The connection's profile decides which values are valid.
 */
$model = static function (Request $request): ?string {
    $model = $request->query('model');

    return is_string($model) && trim($model) !== '' ? $model : null;
};

/*
 * The connection every question page asks through SystemOne::connection(): a non-blank
 * ?connection=, or null for the default one.
 */
$connection = static function (Request $request): ?string {
    $name = $request->query('connection');

    return is_string($name) && trim($name) !== '' ? $name : null;
};

/*
 * The decision about $text, pinned to $model when one is given, with #7's request controls from
 * the query string: a numeric ?min_confidence= turns on the abstention gate, a non-blank ?lang=
 * and ?lang_guess= name the language, and a whole-number ?max_len= and ?head_max_len= set the
 * token budgets. Anything else sends none; a value the decision refuses (?max_len=0) is the
 * package's InvalidDecision.
 */
$decision = static function (string $text, ?string $model, Request $request): Decision {
    $decision = Decision::about(Context::text($text));
    $decision = $model === null ? $decision : $decision->withModel($model);
    $query = static fn (string $name): ?string => is_string($value = $request->query($name)) && trim($value) !== '' ? $value : null;
    $minConfidence = $query('min_confidence');
    $decision = $minConfidence !== null && is_numeric($minConfidence) ? $decision->withMinConfidence((float) $minConfidence) : $decision;
    $decision = ($language = $query('lang')) !== null ? $decision->withLanguage($language) : $decision;
    $decision = ($hint = $query('lang_guess')) !== null ? $decision->withLanguageHint($hint) : $decision;

    $tokens = static fn (string $name): ?int => filter_var($query($name), FILTER_VALIDATE_INT, FILTER_NULL_ON_FAILURE);
    $decision = ($maxLength = $tokens('max_len')) !== null ? $decision->withMaxLength($maxLength) : $decision;

    return ($headMaxLength = $tokens('head_max_len')) !== null ? $decision->withHeadMaxLength($headMaxLength) : $decision;
};

/*
 * ?cache=1 turns the result cache on for this request (the `file` store unless
 * SYSTEM_ONE_CACHE_STORE names one), ?include_context=1 puts the text in the events. Returns
 * whether the cache is on. Both are for this request only: the settings they replace are kept
 * on the request, and put back once it is handled.
 */
$settings = static function (Request $request): bool {
    $cache = $request->query('cache') === '1';
    $request->attributes->set('workbench.replaced-config', [
        'system-one.cache' => config('system-one.cache'),
        'system-one.events.include_context' => config('system-one.events.include_context'),
    ]);

    if ($cache) {
        config(['system-one.cache' => ['enabled' => true, 'store' => config('system-one.cache.store') ?? 'file', 'ttl' => 300]]);
    }

    if ($request->query('include_context') === '1') {
        config(['system-one.events.include_context' => true]);
    }

    return $cache;
};

/* One listener, registered once when the routes load, puts the replaced settings back. */
Event::listen(RequestHandled::class, function (RequestHandled $handled): void {
    /** @var array<string, mixed>|null $replaced what $settings set aside */
    $replaced = $handled->request->attributes->get('workbench.replaced-config');

    if ($replaced !== null) {
        config($replaced);
    }
});

/* An answer's abstention, as data attributes and a definition list entry (#7). */
$abstention = static function (Answer $answer): array {
    $abstained = $answer->abstained ? 'yes' : 'no';
    $state = e($answer->abstention->name);
    $threshold = e((string) $answer->abstentionThreshold);

    return [
        "data-abstained=\"{$abstained}\" data-abstention=\"{$state}\" data-abstention-threshold=\"{$threshold}\"",
        "<dt>abstained (best guess kept above)</dt><dd>{$abstained}</dd><dt>abstention / threshold</dt><dd>{$state} / {$threshold}</dd>",
    ];
};

/* A result's call metadata (#7): the model, the routed checkpoint and why, tokens and latency. */
$metadata = static function (Meta $meta): string {
    $model = e((string) $meta->model);
    $checkpoint = e((string) $meta->checkpoint);
    $reason = e((string) $meta->routingReason);
    $inputTokens = e((string) $meta->inputTokens);
    $outputTokens = e((string) $meta->outputTokens);
    $latency = e($meta->latencyMs === null ? '' : number_format($meta->latencyMs, 1, '.', ''));

    return <<<HTML
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
};

/*
 * The question pages list the events their decision dispatched (#12). One listener, registered
 * once when the routes load, keeps them on the current request, so a long-lived app (Octane, a
 * test making several requests) neither piles up listeners nor mixes requests' events.
 */
Event::listen([DecisionMade::class, DecisionFailed::class], function (DecisionMade|DecisionFailed $event): void {
    $attributes = request()->attributes;
    $seen = $attributes->get('workbench.system-one-events');
    $attributes->set('workbench.system-one-events', [...(is_array($seen) ? $seen : []), $event]);
});

/* The events this request dispatched, with the fields a listener gets, and the cache state. */
$events = static function (Request $request, bool $cache): string {
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

    return <<<HTML
        <h2>Events dispatched</h2>
        <p id="cache" data-cache="{$cacheState}">Result cache: <strong>{$cacheState}</strong> (<code>?cache=1</code>)</p>
        <ol id="events">{$dispatched}</ol>
        HTML;
};

/*
 * A package exception as an error block and its status: 503 when the server is unreachable
 * or down (HostUnavailable), 502 when it refused the decision or answered nonsense
 * (RejectedByModel, MalformedResponse), 422 when the decision cannot be sent (InvalidDecision:
 * the query string's ?model= or ?min_confidence=, or a profile limit), 500 for this app's own
 * mistakes (InvalidConfiguration and the rest). Messages are shown as the package wrote them;
 * HostUnavailable's are already redacted.
 */
$failure = static function (SystemOneException $exception): array {
    $status = match (true) {
        $exception instanceof HostUnavailable => 503,
        $exception instanceof RejectedByModel, $exception instanceof MalformedResponse => 502,
        $exception instanceof InvalidDecision => 422,
        default => 500, // this app's own mistake: InvalidConfiguration and the rest
    };
    $type = e(class_basename($exception));
    $message = e($exception->getMessage());

    return [$status, <<<HTML
        <div id="error" data-error="{$type}" style="border: 2px solid #b00020; padding: 1rem;">
            <h2>{$type}</h2>
            <p>{$message}</p>
        </div>
        HTML];
};

/*
 * The page around a question's outcome. Every argument but $model and $connection is already
 * escaped; $model is the pinned checkpoint, or null when the server chooses, and $connection
 * the connection asked, or null for the default one.
 */
$page = static function (string $title, string $text, string $question, ?string $model, string $outcome, ?string $connection = null): string {
    $pinned = e($model ?? '');
    $shown = $model === null ? 'chosen by the server' : $pinned;
    $default = config('system-one.default');
    $asked = e($connection ?? (is_string($default) ? $default : ''));

    return <<<HTML
        <!doctype html>
        <html lang="en">
        <head><meta charset="utf-8"><title>System One: {$title}</title></head>
        <body style="font-family: system-ui, sans-serif; margin: 3rem; max-width: 44rem;">
            <h1>{$title}</h1>
            <p><strong>Text:</strong> {$text}</p>
            <p><strong>Question:</strong> {$question}</p>
            <p id="connection" data-connection="{$asked}"><strong>Connection:</strong> {$asked} (<a href="/connections">all</a>)</p>
            <p id="model" data-model="{$pinned}"><strong>Model:</strong> {$shown}</p>
            {$outcome}
        </body>
        </html>
        HTML;
};

/*
 * One yes/no question, end to end: SystemOne::decide() against the default connection
 * (SYSTEM_ONE_URL). Like every question page it takes ?text=, ?model=, ?connection=,
 * ?min_confidence= (the answer then shows its abstention), ?cache=1 and ?include_context=1,
 * and shows the call metadata and the events the decision dispatched.
 *
 * Under `composer serve` the shell environment does not reach this app (Testbench turns
 * off putenv and the PHP built-in server fills neither $_ENV nor $_SERVER from it): to
 * use another server or a key, add SYSTEM_ONE_URL / SYSTEM_ONE_API_KEY to the `env:`
 * list in testbench.yaml.
 */
Route::get('/decide', function (Request $request) use ($ticket, $model, $connection, $decision, $settings, $abstention, $metadata, $events, $failure, $page): Response {
    $text = $ticket($request);
    $cache = $settings($request);
    $pinned = $model($request);
    $asked = $connection($request);
    $churn = Question::yesNo('churn', 'Does the customer threaten to cancel or leave?');
    $status = 200;

    try {
        $result = SystemOne::connection($asked)->decide($decision($text, $pinned, $request)->ask($churn));
        $answer = $result->get($churn);
        $probability = e((string) $answer->probability);
        $verdict = e($answer->verdict(yes: 0.8, no: 0.2)->name);
        $isYes = $answer->isYes() ? 'yes' : 'no';
        [$gate, $gated] = $abstention($answer);
        $outcome = <<<HTML
            <dl id="answer" data-probability="{$probability}" data-verdict="{$verdict}" data-is-yes="{$isYes}" {$gate}>
                <dt>probability</dt><dd>{$probability}</dd>
                <dt>isYes() (threshold 0.5)</dt><dd>{$isYes}</dd>
                <dt>verdict(yes: 0.8, no: 0.2)</dt><dd>{$verdict}</dd>
                {$gated}
            </dl>
            {$metadata($result->meta)}
            HTML;
    } catch (SystemOneException $exception) {
        [$status, $outcome] = $failure($exception);
    }

    return new Response($page('One yes/no question', e($text), e($churn->instructions), $pinned, $outcome.$events($request, $cache), $asked), $status);
});

/*
 * One choice question over Workbench\App\Enums\Department: the chosen case, both
 * confidences, isConfident(0.6) and the distribution in the enum's order.
 */
Route::get('/choose', function (Request $request) use ($ticket, $model, $connection, $decision, $settings, $abstention, $metadata, $events, $failure, $page): Response {
    $text = $ticket($request);
    $cache = $settings($request);
    $pinned = $model($request);
    $asked = $connection($request);
    $department = Question::choice('department', Department::class, 'Which department should handle this?');
    $status = 200;

    try {
        $result = SystemOne::connection($asked)->decide($decision($text, $pinned, $request)->ask($department));
        $answer = $result->get($department);
        [$gate, $gated] = $abstention($answer);
        $value = e($answer->value->name);
        $answerConfidence = e((string) $answer->answerConfidence);
        $confidence = e((string) $answer->confidence->value);
        $profile = e($answer->confidence->profile);
        $confident = $answer->isConfident(0.6) ? 'yes' : 'no';
        $rows = '';

        foreach ($answer->distribution->entries() as ['case' => $case, 'probability' => $probability]) {
            $name = e($case->name);
            $shown = e((string) $probability);
            $rows .= "<tr data-case=\"{$name}\" data-probability=\"{$shown}\"><td>{$name}</td><td>{$shown}</td></tr>";
        }

        $outcome = <<<HTML
            <dl id="answer" data-value="{$value}" data-answer-confidence="{$answerConfidence}" data-confidence="{$confidence}" data-profile="{$profile}" data-confident="{$confident}" {$gate}>
                <dt>value</dt><dd>Department::{$value}</dd>
                <dt>answerConfidence</dt><dd>{$answerConfidence}</dd>
                <dt>confidence ({$profile})</dt><dd>{$confidence}</dd>
                <dt>isConfident(0.6)</dt><dd>{$confident}</dd>
                {$gated}
            </dl>
            <table id="distribution"><tr><th>case</th><th>probability</th></tr>{$rows}</table>
            {$metadata($result->meta)}
            HTML;
    } catch (SystemOneException $exception) {
        [$status, $outcome] = $failure($exception);
    }

    return new Response($page('One choice question', e($text), e($department->instructions), $pinned, $outcome.$events($request, $cache), $asked), $status);
});

/*
 * One score question over Workbench\App\Enums\Urgency: the most likely level, the expected
 * level (on the backing values), both confidences, isConfident(0.6) and the distribution
 * in level order.
 */
Route::get('/score', function (Request $request) use ($ticket, $model, $connection, $decision, $settings, $abstention, $metadata, $events, $failure, $page): Response {
    $text = $ticket($request);
    $cache = $settings($request);
    $pinned = $model($request);
    $asked = $connection($request);
    $urgency = Question::score('urgency', Urgency::class, 'How urgent is this?');
    $status = 200;

    try {
        $result = SystemOne::connection($asked)->decide($decision($text, $pinned, $request)->ask($urgency));
        $answer = $result->get($urgency);
        [$gate, $gated] = $abstention($answer);
        $value = e($answer->value->name);
        $expected = e((string) $answer->expected);
        $answerConfidence = e((string) $answer->answerConfidence);
        $confidence = e((string) $answer->confidence->value);
        $profile = e($answer->confidence->profile);
        $confident = $answer->isConfident(0.6) ? 'yes' : 'no';
        $rows = '';

        foreach ($answer->distribution->entries() as ['case' => $case, 'probability' => $probability]) {
            $name = e($case->name);
            $level = e((string) $case->value);
            $shown = e((string) $probability);
            $rows .= "<tr data-case=\"{$name}\" data-level=\"{$level}\" data-probability=\"{$shown}\"><td>{$name}</td><td>{$level}</td><td>{$shown}</td></tr>";
        }

        $outcome = <<<HTML
            <dl id="answer" data-value="{$value}" data-expected="{$expected}" data-answer-confidence="{$answerConfidence}" data-confidence="{$confidence}" data-profile="{$profile}" data-confident="{$confident}" {$gate}>
                <dt>value</dt><dd>Urgency::{$value}</dd>
                <dt>expected</dt><dd>{$expected}</dd>
                <dt>answerConfidence</dt><dd>{$answerConfidence}</dd>
                <dt>confidence ({$profile})</dt><dd>{$confidence}</dd>
                <dt>isConfident(0.6)</dt><dd>{$confident}</dd>
                {$gated}
            </dl>
            <table id="distribution"><tr><th>level</th><th>value</th><th>probability</th></tr>{$rows}</table>
            {$metadata($result->meta)}
            HTML;
    } catch (SystemOneException $exception) {
        [$status, $outcome] = $failure($exception);
    }

    return new Response($page('One score question', e($text), e($urgency->instructions), $pinned, $outcome.$events($request, $cache), $asked), $status);
});

/*
 * One choice question over Workbench\App\Enums\Topic's 101 cases, one more than the laya
 * profile allows: on a laya connection the decision fails with InvalidDecision before anything
 * is sent; with SYSTEM_ONE_PROFILE=jev, or SYSTEM_ONE_TOURNAMENT_GROUPING=true for a Laya
 * server started with LAYA_TOURNAMENT_GROUP, it is sent. The page names the profile and the
 * tournament grouping the asked connection's config picks.
 */
Route::get('/limits', function (Request $request, SystemOneManager $manager) use ($ticket, $model, $connection, $decision, $settings, $events, $failure, $page): Response {
    $text = $ticket($request);
    $cache = $settings($request);
    $pinned = $model($request);
    $asked = $connection($request);
    $topic = Question::choice('topic', Topic::class, 'Which topic is this about?');
    // The profile as the manager reads it from config; a config it refuses shows its error below.
    try {
        $checked = $manager->configuration($asked)->profile;
        $profile = e($checked->name);
        $grouping = $checked instanceof LayaProfile && $checked->maxChoiceOptions === LayaProfile::TOURNAMENT_MAX_CHOICE_OPTIONS ? 'yes' : 'no';
    } catch (InvalidConfiguration) {
        [$profile, $grouping] = ['', 'no'];
    }

    $options = count($topic->options());
    $status = 200;

    try {
        $answer = SystemOne::connection($asked)->decide($decision($text, $pinned, $request)->ask($topic))->get($topic);
        $value = e($answer->value->name);
        $answerConfidence = e((string) $answer->answerConfidence);
        $outcome = <<<HTML
            <dl id="answer" data-value="{$value}" data-answer-confidence="{$answerConfidence}" data-options="{$options}">
                <dt>value</dt><dd>Topic::{$value}</dd>
                <dt>answerConfidence</dt><dd>{$answerConfidence}</dd>
                <dt>options sent</dt><dd>{$options}</dd>
            </dl>
            HTML;
    } catch (SystemOneException $exception) {
        [$status, $outcome] = $failure($exception);
    }

    $outcome = "<p id=\"profile\" data-profile=\"{$profile}\" data-tournament-grouping=\"{$grouping}\"><strong>Profile:</strong> {$profile}, tournament grouping: {$grouping}</p>{$outcome}";

    return new Response($page("A choice over {$options} topics", e($text), e($topic->instructions), $pinned, $outcome.$events($request, $cache), $asked), $status);
});

/*
 * Every connection in config/system-one.php as SystemOneManager::configuration() reads it: its
 * URL, whether it is remote and has a key (never the key itself), its profile, default model and
 * retry policy, and whether connection() would build a client for it. A connection the manager
 * refuses shows the InvalidConfiguration instead.
 */
Route::get('/connections', function (SystemOneManager $manager): string {
    $connections = config('system-one.connections');
    $default = config('system-one.default');
    $rows = '';

    foreach (array_keys(is_array($connections) ? $connections : []) as $name) {
        $name = (string) $name;
        $shown = e($name);
        $link = e('/decide?connection='.rawurlencode($name));

        try {
            $config = $manager->configuration($name);
        } catch (InvalidConfiguration $invalid) {
            $message = e($invalid->getMessage());
            $rows .= "<tr data-connection=\"{$shown}\" data-usable=\"no\"><td>{$shown}</td><td colspan=\"7\">{$message}</td></tr>";

            continue;
        }

        $isDefault = $name === $default ? 'yes' : 'no';
        // Masked as an exception message would be: URL credentials and query values.
        $url = e(Redactor::forConnection($config->url, $config->apiKey())->redact($config->url));
        $remote = $config->remote ? 'yes' : 'no';
        $key = $config->hasApiKey() ? 'yes' : 'no';
        $usable = $config->remote && ! $config->hasApiKey() ? 'no' : 'yes';
        $profile = e($config->profile->name);
        $defaultModel = e($config->model ?? '');
        $retries = $config->retry->retries;
        $delay = $config->retry->delayMilliseconds;
        $rows .= "<tr data-connection=\"{$shown}\" data-default=\"{$isDefault}\" data-url=\"{$url}\" data-remote=\"{$remote}\" data-key=\"{$key}\" data-profile=\"{$profile}\" data-model=\"{$defaultModel}\" data-retries=\"{$retries}\" data-retry-delay=\"{$delay}\" data-usable=\"{$usable}\">"
            ."<td><a href=\"{$link}\">{$shown}</a></td><td>{$url}</td><td>{$remote}</td><td>{$key}</td><td>{$profile}</td><td>{$defaultModel}</td><td>{$retries} after {$delay} ms</td><td>{$usable}</td></tr>";
    }

    return <<<HTML
        <!doctype html>
        <html lang="en">
        <head><meta charset="utf-8"><title>System One: connections</title></head>
        <body style="font-family: system-ui, sans-serif; margin: 3rem;">
            <h1>Connections</h1>
            <p>Every question page takes <code>?connection=</code>. A remote connection is used only once it has a key.</p>
            <table id="connections">
                <tr><th>name</th><th>url</th><th>remote</th><th>key set</th><th>profile</th><th>default model</th><th>retries</th><th>usable</th></tr>
                {$rows}
            </table>
        </body>
        </html>
        HTML;
});

/*
 * The same question about several texts, end to end: SystemOne::decideMany() against the
 * batch endpoint. Each non-blank ?text[]= is one context, in order; with none, three default
 * tickets are asked about. Like the question pages it takes ?connection=, ?model=,
 * ?min_confidence=, ?cache=1 and ?include_context=1, and lists the events.
 */
Route::get('/decide-many', function (Request $request) use ($model, $connection, $decision, $settings, $abstention, $events, $failure, $page): Response {
    $given = $request->query('text');
    $texts = is_array($given)
        ? array_values(array_filter($given, static fn (mixed $text): bool => is_string($text) && trim($text) !== ''))
        : [];
    if ($texts === []) {
        $texts = [
            'Hi, we were billed twice for March. Please refund the duplicate charge today or we will cancel our plan.',
            'The app crashes every time I open the settings page.',
            'Just wanted to say the new dashboard looks great.',
        ];
    }
    $cache = $settings($request);
    $pinned = $model($request);
    $asked = $connection($request);
    $churn = Question::yesNo('churn', 'Does the customer threaten to cancel or leave?');
    $status = 200;

    try {
        $results = SystemOne::connection($asked)->decideMany(
            array_map(Context::text(...), $texts),
            $decision($texts[0], $pinned, $request)->ask($churn),
        );
        $rows = '';
        foreach ($texts as $position => $text) {
            $answer = $results[$position]->get($churn);
            $probability = e((string) $answer->probability);
            $verdict = e($answer->verdict(yes: 0.8, no: 0.2)->name);
            [$gate] = $abstention($answer);
            $checkpoint = e((string) $results[$position]->meta->checkpoint);
            $text = e($text);
            $rows .= "<tr data-position=\"{$position}\" data-probability=\"{$probability}\" data-verdict=\"{$verdict}\" data-checkpoint=\"{$checkpoint}\" {$gate}>"
                ."<td>{$position}</td><td>{$text}</td><td>{$probability}</td><td>{$verdict}</td><td>{$answer->abstention->name}</td><td>{$checkpoint}</td></tr>\n";
        }
        $count = count($results);
        $inputTokens = e((string) ($results->usage->inputTokens ?? 'n/a'));
        $outcome = <<<HTML
            <table id="results" data-results="{$count}" data-input-tokens="{$inputTokens}">
                <thead><tr><th>#</th><th>Text</th><th>probability</th><th>verdict(yes: 0.8, no: 0.2)</th><th>abstention</th><th>checkpoint</th></tr></thead>
                <tbody>{$rows}</tbody>
            </table>
            <p>{$count} results, {$inputTokens} input tokens.</p>
            HTML;
    } catch (SystemOneException $exception) {
        [$status, $outcome] = $failure($exception);
    }

    $shown = count($texts) === 1 ? '1 text' : count($texts).' texts';

    return new Response($page('One yes/no question, many texts', $shown, e($churn->instructions), $pinned, $outcome.$events($request, $cache), $asked), $status);
});
