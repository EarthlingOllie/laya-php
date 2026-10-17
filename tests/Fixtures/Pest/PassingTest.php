<?php

declare(strict_types=1);

/* Not in any suite: PestForInfectionTest runs it on its own. Every test asserts something. */

it('asserts something', function (): void {
    expect(1 + 1)->toBe(2);
});

it('expects an exception, which counts as an assertion', function (): void {
    throw new RuntimeException('expected');
})->throws(RuntimeException::class, 'expected');
