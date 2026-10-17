<?php

declare(strict_types=1);

use EarthlingOllie\SystemOneClassification\Domain\Context;
use EarthlingOllie\SystemOneClassification\Domain\Decision;
use EarthlingOllie\SystemOneClassification\Domain\Question;
use EarthlingOllie\SystemOneClassification\Exceptions\InvalidConfiguration;
use EarthlingOllie\SystemOneClassification\Exceptions\InvalidDecision;
use EarthlingOllie\SystemOneClassification\Laravel\Facades\SystemOne;
use EarthlingOllie\SystemOneClassification\Tests\Fixtures\Enums\Department;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

/*
 * #8's batches through the rest of the stack: #9's connections (default model, bearer token,
 * retries, the remote key rule), #6's profile limits and #7's request controls, each applied
 * exactly as to decide().
 */

const BATCH_KEY = 'sk-batch-0123456789';

function batchOfTwo(): array
{
    return [Context::text('We were billed twice.'), Context::text('The app crashes.')];
}

function connectionBatchDecision(): Decision
{
    return Decision::about(Context::text('template'))->ask(Question::yesNo('churn', 'Cancel?'));
}

/** Answers every batch with one yes/no answer per state, after the given failures. */
function fakeBatchHost(int $failures = 0): void
{
    $sent = 0;
    Http::fake(function (Request $request) use (&$sent, $failures) {
        if (++$sent <= $failures) {
            return Http::response(['detail' => 'server busy'], 503);
        }

        $states = (array) $request->data()['states'];

        return Http::response([
            'results' => array_map(static fn (): array => ['answers' => ['churn' => ['type' => 'noul', 'noul' => 0.9]]], $states),
            'total_usage' => ['input_tokens' => 2, 'output_tokens' => 0],
        ]);
    });
}

it("sends a batch with its connection's default model, which a pinned model overrides", function (): void {
    config(['system-one.connections.local.model' => 'multilingual']);
    fakeBatchHost();

    SystemOne::decideMany(batchOfTwo(), connectionBatchDecision());
    SystemOne::decideMany(batchOfTwo(), connectionBatchDecision()->withModel('english'));

    expect(Http::recorded()->map(fn (array $sent): mixed => $sent[0]->data()['model'] ?? null)->all())->toBe(['multilingual', 'english']);
});

it('sends a remote batch with its own key as the bearer token, and refuses one without a key', function (): void {
    config(['system-one.connections.laya-hosted.api_key' => BATCH_KEY]);
    fakeBatchHost();

    SystemOne::connection('laya-hosted')->decideMany(batchOfTwo(), connectionBatchDecision());

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.laya-ai.com/v1/systemone/batch'
        && $request->header('Authorization') === ['Bearer '.BATCH_KEY]);
    expect(fn (): mixed => SystemOne::connection('jev')->decideMany(batchOfTwo(), connectionBatchDecision()))
        ->toThrow(InvalidConfiguration::class, "System One connection 'jev' is a remote host, so it needs an 'api_key'");
    Http::assertSentCount(1);
});

it("retries a batch as the connection's retry policy says", function (): void {
    config(['system-one.connections.local.retries' => 2, 'system-one.connections.local.retry_delay' => 100]);
    fakeBatchHost(failures: 2);

    $results = SystemOne::decideMany(batchOfTwo(), connectionBatchDecision());

    expect($results)->toHaveCount(2);
    Http::assertSentCount(3);
    Sleep::assertSlept(fn ($duration): bool => true, 2);
});

it("refuses a batch that breaks the connection's profile, before sending anything", function (): void {
    config(['system-one.connections.local.profile' => 'jev']);
    Http::fake();

    expect(fn (): mixed => SystemOne::decideMany(batchOfTwo(), connectionBatchDecision()->withModel('english')))
        ->toThrow(InvalidDecision::class, "The decision pins model 'english', but the jev profile accepts no model values");
    Http::assertNothingSent();
});

it('sends every request control with a batch', function (): void {
    fakeBatchHost();
    $department = Question::choice('department', Department::class, 'Which department?');

    try {
        SystemOne::decideMany(batchOfTwo(), connectionBatchDecision()->ask($department)->withMinConfidence(0.6)->withLanguage('en')->withMaxLength(64));
    } catch (Throwable) {
        // The fake host answers only churn; what was sent is what this test reads.
    }

    Http::assertSent(fn (Request $request): bool => $request['min_confidence'] === 0.6
        && $request['lang'] === 'en'
        && $request['max_len'] === 64
        && array_keys((array) $request['questions']) === ['churn', 'department']);
});
