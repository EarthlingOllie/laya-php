<?php

declare(strict_types=1);

use EarthlingOllie\SystemOneClassification\Domain\Context;
use EarthlingOllie\SystemOneClassification\Domain\Decision;
use EarthlingOllie\SystemOneClassification\Domain\Question;
use EarthlingOllie\SystemOneClassification\Exceptions\HostUnavailable;
use EarthlingOllie\SystemOneClassification\Exceptions\MalformedResponse;
use EarthlingOllie\SystemOneClassification\Exceptions\RejectedByModel;
use EarthlingOllie\SystemOneClassification\Exceptions\SystemOneException;
use EarthlingOllie\SystemOneClassification\Laravel\Facades\SystemOne;
use EarthlingOllie\SystemOneClassification\Laravel\SystemOneServiceProvider;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
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

/* The decision about $text, pinned to $model when one is given. */
$decision = static function (string $text, ?string $model): Decision {
    $decision = Decision::about(Context::text($text));

    return $model === null ? $decision : $decision->withModel($model);
};

/*
 * A package exception as an error block and its status: 503 when the server is unreachable
 * or down (HostUnavailable), 502 when it refused the decision or answered nonsense
 * (RejectedByModel, MalformedResponse), 500 for this app's own mistakes (InvalidConfiguration
 * and the rest). Messages are shown as the package wrote them; HostUnavailable's are already
 * redacted.
 */
$failure = static function (SystemOneException $exception): array {
    $status = match (true) {
        $exception instanceof HostUnavailable => 503,
        $exception instanceof RejectedByModel, $exception instanceof MalformedResponse => 502,
        default => 500, // this app's own mistake: InvalidConfiguration, InvalidDecision, ...
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
 * The page around a question's outcome. Every argument but $model is already escaped; $model
 * is the pinned checkpoint, or null when the server chooses.
 */
$page = static function (string $title, string $text, string $question, ?string $model, string $outcome): string {
    $pinned = e($model ?? '');
    $shown = $model === null ? 'chosen by the server' : $pinned;

    return <<<HTML
        <!doctype html>
        <html lang="en">
        <head><meta charset="utf-8"><title>System One: {$title}</title></head>
        <body style="font-family: system-ui, sans-serif; margin: 3rem; max-width: 44rem;">
            <h1>{$title}</h1>
            <p><strong>Text:</strong> {$text}</p>
            <p><strong>Question:</strong> {$question}</p>
            <p id="model" data-model="{$pinned}"><strong>Model:</strong> {$shown}</p>
            {$outcome}
        </body>
        </html>
        HTML;
};

/*
 * One yes/no question, end to end: SystemOne::decide() against the default connection
 * (SYSTEM_ONE_URL).
 *
 * Under `composer serve` the shell environment does not reach this app (Testbench turns
 * off putenv and the PHP built-in server fills neither $_ENV nor $_SERVER from it): to
 * use another server or a key, add SYSTEM_ONE_URL / SYSTEM_ONE_API_KEY to the `env:`
 * list in testbench.yaml.
 */
Route::get('/decide', function (Request $request) use ($ticket, $model, $decision, $failure, $page): Response {
    $text = $ticket($request);
    $pinned = $model($request);
    $churn = Question::yesNo('churn', 'Does the customer threaten to cancel or leave?');
    $status = 200;

    try {
        $answer = SystemOne::decide($decision($text, $pinned)->ask($churn))->get($churn);
        $probability = e((string) $answer->probability);
        $verdict = e($answer->verdict(yes: 0.8, no: 0.2)->name);
        $isYes = $answer->isYes() ? 'yes' : 'no';
        $outcome = <<<HTML
            <dl id="answer" data-probability="{$probability}" data-verdict="{$verdict}" data-is-yes="{$isYes}">
                <dt>probability</dt><dd>{$probability}</dd>
                <dt>isYes() (threshold 0.5)</dt><dd>{$isYes}</dd>
                <dt>verdict(yes: 0.8, no: 0.2)</dt><dd>{$verdict}</dd>
            </dl>
            HTML;
    } catch (SystemOneException $exception) {
        [$status, $outcome] = $failure($exception);
    }

    return new Response($page('One yes/no question', e($text), e($churn->instructions), $pinned, $outcome), $status);
});

/*
 * One choice question over Workbench\App\Enums\Department: the chosen case, both
 * confidences, isConfident(0.6) and the distribution in the enum's order.
 */
Route::get('/choose', function (Request $request) use ($ticket, $model, $decision, $failure, $page): Response {
    $text = $ticket($request);
    $pinned = $model($request);
    $department = Question::choice('department', Department::class, 'Which department should handle this?');
    $status = 200;

    try {
        $answer = SystemOne::decide($decision($text, $pinned)->ask($department))->get($department);
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
            <dl id="answer" data-value="{$value}" data-answer-confidence="{$answerConfidence}" data-confidence="{$confidence}" data-profile="{$profile}" data-confident="{$confident}">
                <dt>value</dt><dd>Department::{$value}</dd>
                <dt>answerConfidence</dt><dd>{$answerConfidence}</dd>
                <dt>confidence ({$profile})</dt><dd>{$confidence}</dd>
                <dt>isConfident(0.6)</dt><dd>{$confident}</dd>
            </dl>
            <table id="distribution"><tr><th>case</th><th>probability</th></tr>{$rows}</table>
            HTML;
    } catch (SystemOneException $exception) {
        [$status, $outcome] = $failure($exception);
    }

    return new Response($page('One choice question', e($text), e($department->instructions), $pinned, $outcome), $status);
});

/*
 * One score question over Workbench\App\Enums\Urgency: the most likely level, the expected
 * level (on the backing values), both confidences, isConfident(0.6) and the distribution
 * in level order.
 */
Route::get('/score', function (Request $request) use ($ticket, $model, $decision, $failure, $page): Response {
    $text = $ticket($request);
    $pinned = $model($request);
    $urgency = Question::score('urgency', Urgency::class, 'How urgent is this?');
    $status = 200;

    try {
        $answer = SystemOne::decide($decision($text, $pinned)->ask($urgency))->get($urgency);
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
            <dl id="answer" data-value="{$value}" data-expected="{$expected}" data-answer-confidence="{$answerConfidence}" data-confidence="{$confidence}" data-profile="{$profile}" data-confident="{$confident}">
                <dt>value</dt><dd>Urgency::{$value}</dd>
                <dt>expected</dt><dd>{$expected}</dd>
                <dt>answerConfidence</dt><dd>{$answerConfidence}</dd>
                <dt>confidence ({$profile})</dt><dd>{$confidence}</dd>
                <dt>isConfident(0.6)</dt><dd>{$confident}</dd>
            </dl>
            <table id="distribution"><tr><th>level</th><th>value</th><th>probability</th></tr>{$rows}</table>
            HTML;
    } catch (SystemOneException $exception) {
        [$status, $outcome] = $failure($exception);
    }

    return new Response($page('One score question', e($text), e($urgency->instructions), $pinned, $outcome), $status);
});

/*
 * One choice question over Workbench\App\Enums\Topic's 101 cases, one more than the laya
 * profile allows: on a laya connection the decision fails with InvalidDecision before anything
 * is sent; with SYSTEM_ONE_PROFILE=jev, or SYSTEM_ONE_TOURNAMENT_GROUPING=true for a Laya
 * server started with LAYA_TOURNAMENT_GROUP, it is sent. The page names the profile and the
 * tournament grouping the default connection's config picks.
 */
Route::get('/limits', function (Request $request) use ($ticket, $model, $decision, $failure, $page): Response {
    $text = $ticket($request);
    $pinned = $model($request);
    $topic = Question::choice('topic', Topic::class, 'Which topic is this about?');
    $connection = config('system-one.default');
    $settings = config('system-one.connections.'.(is_string($connection) ? $connection : ''));
    $settings = is_array($settings) ? $settings : [];
    // Shown as the config states them (the manager refuses anything it cannot read).
    $profile = $settings['profile'] ?? null;
    $profile = e(is_string($profile) && $profile !== '' ? $profile : 'laya');
    $grouping = filter_var($settings['tournament_grouping'] ?? false, FILTER_VALIDATE_BOOL) ? 'yes' : 'no';
    $options = count($topic->options());
    $status = 200;

    try {
        $answer = SystemOne::decide($decision($text, $pinned)->ask($topic))->get($topic);
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

    return new Response($page("A choice over {$options} topics", e($text), e($topic->instructions), $pinned, $outcome), $status);
});
