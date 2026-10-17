<?php

declare(strict_types=1);

/*
 * Not in any suite (tests/Fixtures is not a test directory): PestForInfectionTest runs it on its
 * own, as Infection would run a test whose only assertion a mutant removed.
 */

it('asserts nothing, as a test does once a mutant removes its only assertion', function (): void {
    $sum = 1 + 1;
    unset($sum);
});

it('asserts something', function (): void {
    expect(1 + 1)->toBe(2);
});
