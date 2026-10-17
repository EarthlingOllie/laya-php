<?php

declare(strict_types=1);

use EarthlingOllie\SystemOneClassification\Transport\RetryPolicy;

it('retries nothing by default', function (): void {
    $policy = new RetryPolicy;

    expect($policy->retries)->toBe(0)
        ->and($policy->delayMilliseconds)->toBe(0)
        ->and($policy->delays())->toBe([]);
});

it('doubles the delay before each further retry', function (): void {
    expect((new RetryPolicy(retries: 4, delayMilliseconds: 250))->delays())->toBe([250, 500, 1000, 2000]);
});

it('waits not at all between retries with no delay', function (): void {
    expect((new RetryPolicy(retries: 2))->delays())->toBe([0, 0]);
});

it('caps every delay at the maximum', function (): void {
    expect((new RetryPolicy(retries: 5, delayMilliseconds: 10_000))->delays())
        ->toBe([10_000, 20_000, RetryPolicy::MAX_DELAY_MILLISECONDS, RetryPolicy::MAX_DELAY_MILLISECONDS, RetryPolicy::MAX_DELAY_MILLISECONDS])
        ->and(RetryPolicy::MAX_DELAY_MILLISECONDS)->toBe(30_000);
});

it('caps a first delay above the maximum, and never overflows however many retries', function (): void {
    $delays = (new RetryPolicy(retries: 100, delayMilliseconds: 40_000))->delays();

    expect($delays)->toHaveCount(100)
        ->and(array_unique($delays))->toBe([RetryPolicy::MAX_DELAY_MILLISECONDS]);
});
