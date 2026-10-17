<?php

declare(strict_types=1);

use EarthlingOllie\SystemOneClassification\Result\Usage;

it('holds the input and output token counts', function (): void {
    $usage = new Usage(327, 4);

    expect($usage->inputTokens)->toBe(327)
        ->and($usage->outputTokens)->toBe(4);
});

it('adds two usages into a new one and leaves both unchanged', function (): void {
    $first = new Usage(327, 4);
    $second = new Usage(204, 1);

    $sum = $first->plus($second);

    expect($sum)->toEqual(new Usage(531, 5))
        ->and($first)->toEqual(new Usage(327, 4))
        ->and($second)->toEqual(new Usage(204, 1));
});

it('cannot be changed after construction', function (): void {
    $usage = new Usage(1, 0);

    expect(fn () => $usage->inputTokens = 2)->toThrow(Error::class);
});
