<?php

declare(strict_types=1);

use EarthlingOllie\SystemOneClassification\Result\Meta;
use EarthlingOllie\SystemOneClassification\Result\Result;

it('holds what the server reported about the call and the client-side latency', function (): void {
    $meta = new Meta(
        model: 'laya-rl-agent',
        checkpoint: 'english',
        routingReason: 'English Latin text',
        inputTokens: 57,
        outputTokens: 0,
        latencyMs: 412.5,
    );

    expect($meta->model)->toBe('laya-rl-agent')
        ->and($meta->checkpoint)->toBe('english')
        ->and($meta->routingReason)->toBe('English Latin text')
        ->and($meta->inputTokens)->toBe(57)
        ->and($meta->outputTokens)->toBe(0)
        ->and($meta->latencyMs)->toBe(412.5);
});

it('knows nothing by default, for hosts that report less', function (): void {
    $meta = new Meta;

    expect([$meta->model, $meta->checkpoint, $meta->routingReason, $meta->inputTokens, $meta->outputTokens, $meta->latencyMs])
        ->toBe([null, null, null, null, null, null]);
});

it('cannot be changed after construction', function (): void {
    $meta = new Meta(model: 'laya-rl-agent');

    expect(fn () => $meta->model = 'other')->toThrow(Error::class);
});

it('is empty on a result built without it', function (): void {
    expect((new Result([]))->meta)->toEqual(new Meta);
});

it('is the meta a result was built with', function (): void {
    $meta = new Meta(model: 'laya-rl-agent');

    expect((new Result([], $meta))->meta)->toBe($meta);
});
