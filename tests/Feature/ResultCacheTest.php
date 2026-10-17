<?php

declare(strict_types=1);

use EarthlingOllie\SystemOneClassification\Domain\Context;
use EarthlingOllie\SystemOneClassification\Domain\Decision;
use EarthlingOllie\SystemOneClassification\Domain\Question;
use EarthlingOllie\SystemOneClassification\Exceptions\InvalidConfiguration;
use EarthlingOllie\SystemOneClassification\Exceptions\MalformedResponse;
use EarthlingOllie\SystemOneClassification\Exceptions\RejectedByModel;
use EarthlingOllie\SystemOneClassification\Laravel\Events\DecisionMade;
use EarthlingOllie\SystemOneClassification\Laravel\Facades\SystemOne;
use EarthlingOllie\SystemOneClassification\Protocol\SystemOneCodec;
use EarthlingOllie\SystemOneClassification\Result\Result;
use Illuminate\Cache\ArrayStore;
use Illuminate\Contracts\Cache\Store;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;

function ticket(): Decision
{
    return Decision::about(Context::text('We were billed twice and will cancel.'))
        ->ask(Question::yesNo('churn', 'Does the customer threaten to cancel?'));
}

/**
 * Fakes the server: /health reports the revisions (read on every request, so a test can change
 * them), and each decision gets a new probability (0.1, 0.2, …), so a cached result is told
 * apart from a fresh one.
 *
 * @param  ArrayObject<string, ?string>|array<string, ?string>  $revisions
 */
function fakeServer(ArrayObject|array $revisions = ['english' => 'rev-a']): void
{
    $answered = 0;
    Http::fake(function (Request $request) use ($revisions, &$answered) {
        if (str_ends_with($request->url(), '/health')) {
            return Http::response(['status' => 'ok', 'revisions' => (object) (array) $revisions]);
        }

        $answered++;
        $answers = [];
        foreach (array_keys((array) $request->data()['questions']) as $key) {
            $answers[$key] = ['type' => 'noul', 'noul' => $answered / 10];
        }

        return Http::response(['model' => 'laya-rl-agent', 'answers' => $answers, 'routing' => ['model' => 'english']]);
    });
}

/** The decisions the fake server was sent, as opposed to health checks. */
function decisionsSent(): int
{
    return Http::recorded(fn (Request $request): bool => str_ends_with($request->url(), '/v1/systemone'))->count();
}

function churnOf(Result $result): float
{
    return $result->get(Question::yesNo('churn', 'Does the customer threaten to cancel?'))->probability;
}

beforeEach(function (): void {
    config([
        'system-one.connections.local' => ['url' => 'http://laya.test'],
        'system-one.cache' => ['enabled' => true, 'store' => 'array', 'ttl' => 60],
    ]);
});

it('is off by default and then sends every decision without reading the server health', function (): void {
    $published = require __DIR__.'/../../config/system-one.php';
    config(['system-one.cache' => $published['cache']]);
    fakeServer();

    SystemOne::decide(ticket());
    SystemOne::decide(ticket());

    expect(config('system-one.cache.enabled'))->toBeFalse()
        ->and(decisionsSent())->toBe(2);
    Http::assertNotSent(fn (Request $request): bool => str_ends_with($request->url(), '/health'));
});

it('answers an identical decision from the cache instead of sending it again', function (): void {
    fakeServer();
    Event::fake([DecisionMade::class]);

    $first = SystemOne::decide(ticket());
    $second = SystemOne::decide(ticket());

    expect(churnOf($second))->toBe(churnOf($first))
        ->and(decisionsSent())->toBe(1)
        ->and($second->meta->latencyMs)->toBeNull()
        ->and(Event::dispatched(DecisionMade::class)->map(fn (array $dispatched): bool => $dispatched[0]->cached)->all())->toBe([false, true]);
});

it('misses the cache when anything the key covers changes, and still hits for the original', function (Closure $change): void {
    config(['system-one.connections.other' => ['url' => 'http://laya.test']]);
    $revisions = new ArrayObject(['english' => 'rev-a']);
    fakeServer($revisions);
    SystemOne::decide(ticket());

    $change($revisions);
    $missed = decisionsSent();
    config(['system-one.connections.local.model' => null]);
    $revisions['english'] = 'rev-a';
    SystemOne::decide(ticket());

    expect($missed)->toBe(2)
        ->and(decisionsSent())->toBe(2);
})->with([
    'the context' => [fn () => SystemOne::decide(Decision::about(Context::text('We are happy.'))->ask(Question::yesNo('churn', 'Does the customer threaten to cancel?')))],
    'the context as fields' => [fn () => SystemOne::decide(Decision::about(Context::fields(['body' => 'We were billed twice and will cancel.']))->ask(Question::yesNo('churn', 'Does the customer threaten to cancel?')))],
    'a question\'s key' => [fn () => SystemOne::decide(Decision::about(Context::text('We were billed twice and will cancel.'))->ask(Question::yesNo('cancel', 'Does the customer threaten to cancel?')))],
    'a question\'s instructions' => [fn () => SystemOne::decide(Decision::about(Context::text('We were billed twice and will cancel.'))->ask(Question::yesNo('churn', 'Will they cancel?')))],
    'another question added' => [fn () => SystemOne::decide(ticket()->ask(Question::yesNo('refund', 'Do they want a refund?')))],
    'min_confidence' => [fn () => SystemOne::decide(ticket()->withMinConfidence(0.6))],
    'lang' => [fn () => SystemOne::decide(ticket()->withLanguage('en'))],
    'lang_guess' => [fn () => SystemOne::decide(ticket()->withLanguageHint('en'))],
    'max_len' => [fn () => SystemOne::decide(ticket()->withMaxLength(128))],
    'head_max_len' => [fn () => SystemOne::decide(ticket()->withHeadMaxLength(16))],
    'the connection' => [fn () => SystemOne::connection('other')->decide(ticket())],
    'the model' => [function () {
        config(['system-one.connections.local.model' => 'english']);

        return SystemOne::decide(ticket());
    }],
    'the checkpoint revision' => [function (ArrayObject $revisions) {
        $revisions['english'] = 'rev-b';

        return SystemOne::decide(ticket());
    }],
]);

it('stores the status and body under a key scoped to the connection', function (): void {
    fakeServer();

    SystemOne::decide(ticket());

    $request = (new SystemOneCodec)->encode(ticket());
    $key = hash('sha256', serialize(['revisions' => ['english' => 'rev-a'], 'request' => $request]));
    $entry = Cache::store('array')->get('system-one:'.hash('sha256', "local\0{$key}"));
    expect($entry)->toBeArray()
        ->and(array_keys((array) $entry))->toBe(['status', 'body'])
        ->and($entry['status'] ?? null)->toBe(200)
        ->and(json_decode(is_array($entry) && is_string($entry['body']) ? $entry['body'] : '', true)['answers'] ?? null)->toBe(['churn' => ['type' => 'noul', 'noul' => 0.1]]);
});

it('stays off when the enabled setting is missing or empty', function (?string $enabled): void {
    config(['system-one.cache.enabled' => $enabled]);
    fakeServer();

    SystemOne::decide(ticket());
    SystemOne::decide(ticket());

    expect(decisionsSent())->toBe(2);
    Http::assertNotSent(fn (Request $request): bool => str_ends_with($request->url(), '/health'));
})->with(['missing' => [null], 'empty, as SYSTEM_ONE_CACHE= writes it' => ['']]);

it('accepts a TTL of one second', function (): void {
    config(['system-one.cache.ttl' => 1]);
    fakeServer();
    SystemOne::decide(ticket());
    SystemOne::decide(ticket());

    $this->travel(2)->seconds();
    SystemOne::decide(ticket());

    expect(decisionsSent())->toBe(2);
});

it('hits the cache again for a control set back to the same value', function (): void {
    fakeServer();

    SystemOne::decide(ticket()->withMinConfidence(0.6));
    SystemOne::decide(ticket()->withMinConfidence(0.6));

    expect(decisionsSent())->toBe(1);
});

it('keeps results in the configured store', function (): void {
    config(['cache.stores.decisions' => ['driver' => 'array'], 'system-one.cache.store' => 'decisions']);
    fakeServer();
    SystemOne::decide(ticket());

    Cache::store('array')->flush();
    SystemOne::decide(ticket());
    Cache::store('decisions')->flush();
    SystemOne::decide(ticket());

    expect(decisionsSent())->toBe(2);
});

it('uses the default cache store when none is configured', function (?string $store): void {
    config(['cache.default' => 'array', 'system-one.cache.store' => $store]);
    fakeServer();
    SystemOne::decide(ticket());

    Cache::store('array')->flush();
    SystemOne::decide(ticket());

    expect(decisionsSent())->toBe(2);
})->with(['null' => [null], 'empty' => ['']]);

it('keeps a result for the configured TTL', function (): void {
    fakeServer();
    SystemOne::decide(ticket());

    $this->travel(59)->seconds();
    SystemOne::decide(ticket());
    expect(decisionsSent())->toBe(1);

    $this->travel(2)->seconds();
    SystemOne::decide(ticket());
    expect(decisionsSent())->toBe(2);
});

it('defaults the TTL to an hour when it is empty or missing', function (mixed $ttl): void {
    config(['system-one.cache.ttl' => $ttl]);
    fakeServer();
    SystemOne::decide(ticket());

    $this->travel(3599)->seconds();
    SystemOne::decide(ticket());
    $this->travel(2)->seconds();
    SystemOne::decide(ticket());

    expect(decisionsSent())->toBe(2);
})->with(['empty' => [''], 'null' => [null]]);

it('takes a TTL written as a string, as env() gives it', function (): void {
    config(['system-one.cache.ttl' => '10']);
    fakeServer();
    SystemOne::decide(ticket());

    $this->travel(11)->seconds();
    SystemOne::decide(ticket());

    expect(decisionsSent())->toBe(2);
});

/** A cache store whose every read and write fails, as a Redis server that went away would. */
function brokenStore(): Store
{
    return new class implements Store
    {
        public function get($key): mixed
        {
            throw new RuntimeException('Connection to the cache refused');
        }

        public function many(array $keys): array
        {
            throw new RuntimeException('Connection to the cache refused');
        }

        public function put($key, $value, $seconds): bool
        {
            throw new RuntimeException('Connection to the cache refused on write');
        }

        public function putMany(array $values, $seconds): bool
        {
            throw new RuntimeException('Connection to the cache refused');
        }

        public function increment($key, $value = 1): int|bool
        {
            throw new RuntimeException('Connection to the cache refused');
        }

        public function decrement($key, $value = 1): int|bool
        {
            throw new RuntimeException('Connection to the cache refused');
        }

        public function forever($key, $value): bool
        {
            throw new RuntimeException('Connection to the cache refused');
        }

        public function touch($key, $seconds): bool
        {
            throw new RuntimeException('Connection to the cache refused');
        }

        public function forget($key): bool
        {
            throw new RuntimeException('Connection to the cache refused');
        }

        public function flush(): bool
        {
            throw new RuntimeException('Connection to the cache refused');
        }

        public function getPrefix(): string
        {
            return '';
        }
    };
}

it('reports a broken cache store and still decides every time', function (): void {
    Cache::extend('broken', fn () => Cache::repository(brokenStore()));
    config(['cache.stores.broken' => ['driver' => 'broken'], 'system-one.cache.store' => 'broken']);
    Exceptions::fake();
    fakeServer();

    $first = SystemOne::decide(ticket());
    $second = SystemOne::decide(ticket());

    expect(churnOf($first))->toBe(0.1)
        ->and(churnOf($second))->toBe(0.2)
        ->and(decisionsSent())->toBe(2);
    Exceptions::assertReported(fn (RuntimeException $e): bool => $e->getMessage() === 'Connection to the cache refused');
    Exceptions::assertReported(fn (RuntimeException $e): bool => $e->getMessage() === 'Connection to the cache refused on write');
    Exceptions::assertReportedCount(4);
});

it('reports a cache store that is not configured and still decides', function (): void {
    config(['system-one.cache.store' => 'missing']);
    Exceptions::fake();
    fakeServer();

    expect(churnOf(SystemOne::decide(ticket())))->toBe(0.1);
    Exceptions::assertReported(fn (InvalidArgumentException $e): bool => str_contains($e->getMessage(), 'missing'));
});

it('treats a cached value it did not write as a miss', function (mixed $value): void {
    Cache::extend('odd', fn () => Cache::repository(new class($value) extends ArrayStore
    {
        public function __construct(private mixed $value)
        {
            parent::__construct();
        }

        public function get($key): mixed
        {
            return $this->value;
        }
    }));
    config(['cache.stores.odd' => ['driver' => 'odd'], 'system-one.cache.store' => 'odd']);
    Exceptions::fake();
    fakeServer();

    SystemOne::decide(ticket());
    SystemOne::decide(ticket());

    expect(decisionsSent())->toBe(2);
    Exceptions::assertNothingReported();
})->with([
    'a string' => ['garbage'],
    'an object' => [new stdClass],
    'no body' => [['status' => 200]],
    'a status that is no int' => [['status' => '200', 'body' => '{}']],
]);

it('reports a cached body it cannot decode, then sends the decision', function (): void {
    Cache::extend('stale', fn () => Cache::repository(new class extends ArrayStore
    {
        public function get($key): mixed
        {
            return ['status' => 200, 'body' => '{"answers":{}}'];
        }
    }));
    config(['cache.stores.stale' => ['driver' => 'stale'], 'system-one.cache.store' => 'stale']);
    Exceptions::fake();
    fakeServer();

    expect(churnOf(SystemOne::decide(ticket())))->toBe(0.1);
    Exceptions::assertReported(MalformedResponse::class);
});

it('reports a server without usable health and still decides, uncached', function (): void {
    Exceptions::fake();
    Http::fake([
        '*/health' => Http::response(['detail' => 'Not Found'], 404),
        '*/v1/systemone' => Http::response(['answers' => ['churn' => ['type' => 'noul', 'noul' => 0.4]]]),
    ]);

    SystemOne::decide(ticket());
    SystemOne::decide(ticket());

    expect(decisionsSent())->toBe(2);
    Exceptions::assertReported(RejectedByModel::class);
});

it('rejects cache settings it cannot use', function (array $cache, string $message): void {
    config(['system-one.cache' => $cache]);

    expect(fn () => SystemOne::connection())->toThrow(InvalidConfiguration::class, $message);
})->with([
    'enabled not a boolean' => [['enabled' => 'maybe'], "config/system-one.php has a 'cache.enabled' that is not a boolean."],
    'a zero TTL' => [['enabled' => true, 'ttl' => 0], "config/system-one.php has a 'cache.ttl' that is not a whole number of seconds above zero."],
    'a TTL in words' => [['enabled' => true, 'ttl' => 'an hour'], "config/system-one.php has a 'cache.ttl' that is not a whole number of seconds above zero."],
    'a fractional TTL' => [['enabled' => true, 'ttl' => 1.5], "config/system-one.php has a 'cache.ttl' that is not a whole number of seconds above zero."],
    'a store that is no name' => [['enabled' => true, 'store' => 5], "config/system-one.php has a 'cache.store' that is not a store name."],
]);

it('ignores the other cache settings while caching is off', function (): void {
    config(['system-one.cache' => ['enabled' => false, 'ttl' => 'never', 'store' => 5]]);
    fakeServer();

    SystemOne::decide(ticket());

    expect(decisionsSent())->toBe(1);
});
