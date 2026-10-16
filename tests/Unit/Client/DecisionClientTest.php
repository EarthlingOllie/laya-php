<?php

declare(strict_types=1);

use EarthlingOllie\SystemOneClassification\Client\DecisionClient;
use EarthlingOllie\SystemOneClassification\Domain\Context;
use EarthlingOllie\SystemOneClassification\Domain\Decision;
use EarthlingOllie\SystemOneClassification\Domain\Question;
use EarthlingOllie\SystemOneClassification\Domain\Question\QuestionHandle;
use EarthlingOllie\SystemOneClassification\Exceptions\HostUnavailable;
use EarthlingOllie\SystemOneClassification\Exceptions\InvalidDecision;
use EarthlingOllie\SystemOneClassification\Protocol\SystemOneCodec;
use EarthlingOllie\SystemOneClassification\Result\Answer\YesNoAnswer;
use EarthlingOllie\SystemOneClassification\Transport\HttpTransport;
use EarthlingOllie\SystemOneClassification\Transport\Response;
use EarthlingOllie\SystemOneClassification\Transport\Transport;
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

it('raises the codec error for an error response', function (): void {
    $transport = new readonly class implements Transport
    {
        public function post(string $path, array $body): Response
        {
            return new Response(503, '{"detail":"server busy"}');
        }
    };
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
