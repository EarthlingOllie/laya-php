<?php

declare(strict_types=1);

use EarthlingOllie\SystemOneClassification\Domain\Context;
use EarthlingOllie\SystemOneClassification\Domain\Decision;
use EarthlingOllie\SystemOneClassification\Domain\Question;
use EarthlingOllie\SystemOneClassification\Laravel\Events\DecisionMade;
use EarthlingOllie\SystemOneClassification\Laravel\Facades\SystemOne;
use EarthlingOllie\SystemOneClassification\Tests\TestCase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Psr\Http\Message\RequestInterface;

/*
 * Events and the result cache against the Laya container at SYSTEM_ONE_URL, which runs with
 * LAYA_REVISION=reviewed, so /health reports a revision for its checkpoint.
 */

uses(TestCase::class);

it('reads a revision from the real /health, then answers a repeated decision from the cache', function (): void {
    config(['system-one.cache' => ['enabled' => true, 'store' => 'array', 'ttl' => 60]]);
    Event::fake([DecisionMade::class]);
    $posts = 0;
    Http::globalRequestMiddleware(function (RequestInterface $request) use (&$posts) {
        $posts += $request->getMethod() === 'POST' ? 1 : 0;

        return $request;
    });
    $churn = Question::yesNo('churn', 'Does the customer threaten to cancel or leave?');
    $decision = Decision::about(Context::text('We will cancel our plan unless you refund us.'))->ask($churn);

    $first = SystemOne::decide($decision);
    $second = SystemOne::decide($decision);
    $third = SystemOne::decide($decision->withMinConfidence(0.0));

    expect($second->get($churn)->probability)->toBe($first->get($churn)->probability)
        ->and($second->meta->checkpoint)->toBe($first->meta->checkpoint)
        ->and($second->meta->latencyMs)->toBeNull()
        ->and($third->meta->latencyMs)->not->toBeNull()
        ->and($posts)->toBe(2)
        ->and(Event::dispatched(DecisionMade::class)->map(fn (array $made): bool => $made[0]->cached)->all())->toBe([false, true, false]);
});
