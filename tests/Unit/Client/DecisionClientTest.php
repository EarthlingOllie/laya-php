<?php

declare(strict_types=1);

use EarthlingOllie\SystemOneClassification\Client\Clock;
use EarthlingOllie\SystemOneClassification\Client\DecisionClient;
use EarthlingOllie\SystemOneClassification\Client\SystemClock;
use EarthlingOllie\SystemOneClassification\Domain\Context;
use EarthlingOllie\SystemOneClassification\Domain\Decision;
use EarthlingOllie\SystemOneClassification\Domain\Question;
use EarthlingOllie\SystemOneClassification\Domain\Question\QuestionHandle;
use EarthlingOllie\SystemOneClassification\Exceptions\HostUnavailable;
use EarthlingOllie\SystemOneClassification\Exceptions\InvalidDecision;
use EarthlingOllie\SystemOneClassification\Protocol\SystemOneCodec;
use EarthlingOllie\SystemOneClassification\Result\Answer\YesNoAnswer;
use EarthlingOllie\SystemOneClassification\Tests\Support\ScriptedTransport;
use EarthlingOllie\SystemOneClassification\Transport\HttpTransport;
use EarthlingOllie\SystemOneClassification\Transport\Response;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;

it('sends the encoded decision through the transport and decodes the answer', function (): void {
    $churn = Question::yesNo('churn', 'Does the customer threaten to cancel?');
    $decision = Decision::about(Context::text('We will cancel.'))->ask($churn);
    $http = new Factory;
    $http->fake(['*' => Factory::response(['answers' => ['churn' => ['type' => 'noul', 'noul' => 0.91]]])]);

    $result = (new DecisionClient(new HttpTransport($http, 'http://laya.test'), new SystemOneCodec))->decide($decision);

    expect($result->get($churn)->probability)->toBe(0.91);
    $http->assertSent(fn (Request $request): bool => $request->url() === 'http://laya.test/v1/systemone'
        && $request->data() === (new SystemOneCodec)->encode($decision));
});

it('sends the model it was built with', function (): void {
    $decision = Decision::about(Context::text('We will cancel.'))->ask(Question::yesNo('churn', 'Cancel?'));
    $http = new Factory;
    $http->fake(['*' => Factory::response(['answers' => ['churn' => ['type' => 'noul', 'noul' => 0.5]]])]);

    (new DecisionClient(new HttpTransport($http, 'http://laya.test'), new SystemOneCodec, model: 'multilingual'))->decide($decision);

    $http->assertSent(fn (Request $request): bool => $request->data() === (new SystemOneCodec)->encode($decision, 'multilingual'));
});

/** A clock that returns the given readings, one per call, and records how often it was read. */
function steppedClock(float ...$readings): Clock
{
    return new readonly class($readings) implements Clock
    {
        private ArrayIterator $readings;

        /** @param list<float> $readings */
        public function __construct(array $readings)
        {
            $this->readings = new ArrayIterator($readings);
        }

        public function now(): float
        {
            $reading = $this->readings->current();
            $this->readings->next();

            return $reading ?? throw new LogicException('The clock was read more often than expected.');
        }
    };
}

it('measures the latency of the round trip with its clock, in milliseconds', function (): void {
    $transport = new ScriptedTransport([new Response(200, '{"model":"laya-rl-agent","answers":{"churn":{"type":"noul","noul":0.5}}}')]);
    $decision = Decision::about(Context::text('x'))->ask(Question::yesNo('churn', 'Cancel?'));

    $meta = (new DecisionClient($transport, new SystemOneCodec, steppedClock(100.25, 100.5)))->decide($decision)->meta;

    expect($meta->latencyMs)->toBe(250.0)
        ->and($meta->model)->toBe('laya-rl-agent');
});

it('starts the clock after encoding and stops it before decoding', function (): void {
    $order = new ArrayObject;
    $transport = new ScriptedTransport([new Response(200, '{"answers":{"churn":{"type":"noul","noul":0.5}}}')], log: $order);
    $clock = new readonly class($order) implements Clock
    {
        public function __construct(private ArrayObject $order) {}

        public function now(): float
        {
            $this->order->append('clock');

            return 1.0;
        }
    };
    $decision = Decision::about(Context::text('x'))->ask(Question::yesNo('churn', 'Cancel?'));

    (new DecisionClient($transport, new SystemOneCodec, $clock))->decide($decision);

    expect($order->getArrayCopy())->toBe(['clock', 'POST /v1/systemone', 'clock']);
});

it('reads no clock for a decision it refuses to send', function (): void {
    $http = new Factory;
    $http->fake();

    (new DecisionClient(new HttpTransport($http, 'http://laya.test'), new SystemOneCodec, steppedClock()))
        ->decide(Decision::about(Context::text('x')));
})->throws(InvalidDecision::class);

it('uses the system clock by default and reports a real, non-negative latency', function (): void {
    $http = new Factory;
    $http->fake(['*' => Factory::response(['answers' => ['churn' => ['type' => 'noul', 'noul' => 0.5]]])]);
    $decision = Decision::about(Context::text('x'))->ask(Question::yesNo('churn', 'Cancel?'));

    $latency = (new DecisionClient(new HttpTransport($http, 'http://laya.test'), new SystemOneCodec))->decide($decision)->meta->latencyMs;

    expect($latency)->toBeFloat()->toBeGreaterThanOrEqual(0.0)->toBeLessThan(10_000.0);
});

it('reads a monotonic system clock in seconds', function (): void {
    $clock = new SystemClock;

    $first = $clock->now();
    usleep(2_000);
    $second = $clock->now();

    expect($second - $first)->toBeGreaterThanOrEqual(0.002)->toBeLessThan(5.0)
        ->and($first)->toBeGreaterThan(0.0);
});

it('raises the codec error for an error response', function (): void {
    $transport = new ScriptedTransport([new Response(503, '{"detail":"server busy"}')]);
    $decision = Decision::about(Context::text('x'))->ask(Question::yesNo('churn', 'Cancel?'));

    (new DecisionClient($transport, new SystemOneCodec))->decide($decision);
})->throws(HostUnavailable::class, 'server busy');

it('sends nothing for a decision that asks no questions', function (): void {
    $http = new Factory;
    $http->fake();

    try {
        (new DecisionClient(new HttpTransport($http, 'http://laya.test'), new SystemOneCodec))
            ->decide(Decision::about(Context::text('x')));
    } finally {
        $http->assertNothingSent();
    }
})->throws(InvalidDecision::class);

it('sends nothing for a question type the wire format does not know', function (): void {
    $http = new Factory;
    $http->fake(['*' => Factory::response(['answers' => ['mystery' => ['type' => 'noul', 'noul' => 0.5]]])]);
    $mystery = new class implements QuestionHandle
    {
        public string $key = 'mystery';

        public string $instructions = 'What is it?';

        public function answerType(): string
        {
            return YesNoAnswer::class;
        }
    };

    try {
        (new DecisionClient(new HttpTransport($http, 'http://laya.test'), new SystemOneCodec))
            ->decide(Decision::about(Context::text('x'))->ask($mystery));
    } finally {
        $http->assertNothingSent();
    }
})->throws(InvalidDecision::class, 'The wire format has no question type for '.QuestionHandle::class.'@anonymous.');
