<?php

declare(strict_types=1);

use EarthlingOllie\SystemOneClassification\Exceptions\InvalidProbability;
use EarthlingOllie\SystemOneClassification\Result\Abstention;
use EarthlingOllie\SystemOneClassification\Result\Answer\YesNoAnswer;
use EarthlingOllie\SystemOneClassification\Result\Verdict;

it('exposes the probability that the statement is true', function (): void {
    expect((new YesNoAnswer(0.91))->probability)->toBe(0.91);
});

it('is yes at or above the threshold', function (float $probability, float $threshold, bool $yes): void {
    expect((new YesNoAnswer($probability))->isYes($threshold))->toBe($yes);
})->with([
    'above' => [0.81, 0.8, true],
    'at' => [0.8, 0.8, true],
    'below' => [0.79, 0.8, false],
    'certain' => [1.0, 1.0, true],
    'zero threshold' => [0.0, 0.0, true],
]);

it('uses 0.5 as the default threshold', function (): void {
    expect((new YesNoAnswer(0.5))->isYes())->toBeTrue()
        ->and((new YesNoAnswer(0.49))->isYes())->toBeFalse();
});

it('gives a verdict between the no and yes thresholds', function (float $probability, Verdict $verdict): void {
    expect((new YesNoAnswer($probability))->verdict(yes: 0.8, no: 0.2))->toBe($verdict);
})->with([
    'clearly yes' => [0.95, Verdict::Yes],
    'at yes' => [0.8, Verdict::Yes],
    'just under yes' => [0.79, Verdict::Unsure],
    'middle' => [0.5, Verdict::Unsure],
    'just over no' => [0.21, Verdict::Unsure],
    'at no' => [0.2, Verdict::No],
    'clearly no' => [0.05, Verdict::No],
]);

it('says yes when both thresholds are equal and the probability reaches them', function (): void {
    expect((new YesNoAnswer(0.5))->verdict(yes: 0.5, no: 0.5))->toBe(Verdict::Yes)
        ->and((new YesNoAnswer(0.49))->verdict(yes: 0.5, no: 0.5))->toBe(Verdict::No);
});

it('accepts thresholds at the ends of [0, 1]', function (): void {
    expect((new YesNoAnswer(0.0))->verdict(yes: 1.0, no: 0.0))->toBe(Verdict::No)
        ->and((new YesNoAnswer(1.0))->verdict(yes: 1.0, no: 0.0))->toBe(Verdict::Yes)
        ->and((new YesNoAnswer(0.0))->isYes(0.0))->toBeTrue()
        ->and((new YesNoAnswer(1.0))->isYes(1.0))->toBeTrue();
});

it('rejects a no threshold above the yes threshold', function (): void {
    (new YesNoAnswer(0.5))->verdict(yes: 0.2, no: 0.8);
})->throws(InvalidProbability::class, 'The no threshold (0.8) must not be above the yes threshold (0.2).');

it('rejects thresholds outside [0, 1]', function (callable $call, string $message): void {
    expect(fn () => $call(new YesNoAnswer(0.5)))->toThrow(InvalidProbability::class, $message);
})->with([
    'yes above 1' => [fn (YesNoAnswer $a) => $a->verdict(yes: 1.01, no: 0.2), 'The yes threshold must be between 0 and 1, got 1.01.'],
    'no below 0' => [fn (YesNoAnswer $a) => $a->verdict(yes: 0.8, no: -0.01), 'The no threshold must be between 0 and 1, got -0.01.'],
    'isYes above 1' => [fn (YesNoAnswer $a) => $a->isYes(1.01), 'The threshold must be between 0 and 1, got 1.01.'],
    'isYes below 0' => [fn (YesNoAnswer $a) => $a->isYes(-0.01), 'The threshold must be between 0 and 1, got -0.01.'],
]);

it('rejects a probability outside [0, 1]', function (float $probability): void {
    new YesNoAnswer($probability);
})->with([-0.01, 1.01, NAN])->throws(InvalidProbability::class, 'A probability must be between 0 and 1');

it('has exactly the three verdicts', function (): void {
    expect(Verdict::cases())->toBe([Verdict::Yes, Verdict::No, Verdict::Unsure]);
});

it('has not abstained when no minimum confidence was asked for', function (): void {
    $answer = new YesNoAnswer(0.5);

    expect($answer->abstained)->toBeFalse()
        ->and($answer->abstention)->toBe(Abstention::NotRequested)
        ->and($answer->abstentionThreshold)->toBeNull();
});

it('has abstained only when the gate says abstained', function (Abstention $abstention, bool $abstained): void {
    $answer = new YesNoAnswer(0.5, $abstention, 0.9);

    expect($answer->abstained)->toBe($abstained)
        ->and($answer->abstention)->toBe($abstention)
        ->and($answer->abstentionThreshold)->toBe(0.9);
})->with([
    'abstained' => [Abstention::Abstained, true],
    'passed' => [Abstention::Passed, false],
    'unevaluated' => [Abstention::Unevaluated, false],
]);

it('keeps the best guess when it abstained', function (): void {
    $answer = new YesNoAnswer(0.97, Abstention::Abstained, 0.99);

    expect($answer->probability)->toBe(0.97)
        ->and($answer->isYes())->toBeTrue()
        ->and($answer->abstained)->toBeTrue();
});

it('accepts an abstention threshold at either end of [0, 1]', function (float $threshold): void {
    expect((new YesNoAnswer(0.5, Abstention::Passed, $threshold))->abstentionThreshold)->toBe($threshold);
})->with([0.0, 1.0]);

it('rejects an abstention threshold outside [0, 1]', function (float $threshold): void {
    new YesNoAnswer(0.5, Abstention::Passed, $threshold);
})->with([-0.01, 1.01, NAN])->throws(InvalidProbability::class, 'The abstention threshold must be between 0 and 1');

it('has the three gate states the server reports, plus not requested', function (): void {
    expect(Abstention::cases())->toBe([Abstention::NotRequested, Abstention::Passed, Abstention::Abstained, Abstention::Unevaluated]);
});
