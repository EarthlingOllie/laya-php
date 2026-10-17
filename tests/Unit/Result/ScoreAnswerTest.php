<?php

declare(strict_types=1);

use EarthlingOllie\SystemOneClassification\Exceptions\InvalidDistribution;
use EarthlingOllie\SystemOneClassification\Exceptions\InvalidProbability;
use EarthlingOllie\SystemOneClassification\Result\Answer\ScoreAnswer;
use EarthlingOllie\SystemOneClassification\Result\Confidence;
use EarthlingOllie\SystemOneClassification\Result\Distribution;
use EarthlingOllie\SystemOneClassification\Tests\Fixtures\Enums\Department;
use EarthlingOllie\SystemOneClassification\Tests\Fixtures\Enums\Severity;

/**
 * Severity is backed by 1 (Minor), 5 (Major) and 10 (Critical).
 *
 * @return Distribution<Severity>
 */
function severityDistribution(float $minor = 0.25, float $major = 0.5, float $critical = 0.25): Distribution
{
    return new Distribution([
        ['case' => Severity::Minor, 'probability' => $minor],
        ['case' => Severity::Major, 'probability' => $major],
        ['case' => Severity::Critical, 'probability' => $critical],
    ]);
}

/**
 * @param  Distribution<Severity>|null  $distribution
 * @return ScoreAnswer<Severity>
 */
function majorAnswer(float $answerConfidence = 0.5, ?Distribution $distribution = null): ScoreAnswer
{
    return new ScoreAnswer(Severity::Major, $answerConfidence, new Confidence(0.2, 'laya'), $distribution ?? severityDistribution());
}

it('holds the most likely level, both confidences and the distribution', function (): void {
    $answer = majorAnswer();

    expect($answer->value)->toBe(Severity::Major)
        ->and($answer->answerConfidence)->toBe(0.5)
        ->and($answer->confidence)->toEqual(new Confidence(0.2, 'laya'))
        ->and($answer->distribution->cases())->toBe([Severity::Minor, Severity::Major, Severity::Critical]);
});

it('takes the expected level over the backing values from the distribution', function (Severity $value, float $minor, float $major, float $critical, float $expected): void {
    $answer = new ScoreAnswer($value, 0.5, new Confidence(0.2, 'laya'), severityDistribution($minor, $major, $critical));

    expect($answer->expected)->toBe($expected);
})->with([
    'all on the lowest level' => [Severity::Minor, 1.0, 0.0, 0.0, 1.0],
    'all on the highest level' => [Severity::Critical, 0.0, 0.0, 1.0, 10.0],
    'all on the middle level' => [Severity::Major, 0.0, 1.0, 0.0, 5.0],
    'a quarter, a half, a quarter' => [Severity::Major, 0.25, 0.5, 0.25, 5.25],
    // Not the middle level (5), as placing an expected index on the values would give.
    'half on each end' => [Severity::Minor, 0.5, 0.0, 0.5, 5.5],
    // The server rounds each probability, so they need not add up to 1: they are normalised.
    'probabilities that add up to a half' => [Severity::Minor, 0.25, 0.0, 0.25, 5.5],
]);

it('takes the expected level by backing value, whatever order the distribution lists the levels in', function (): void {
    $answer = majorAnswer(distribution: new Distribution([
        ['case' => Severity::Critical, 'probability' => 0.25],
        ['case' => Severity::Minor, 'probability' => 0.25],
        ['case' => Severity::Major, 'probability' => 0.5],
    ]));

    expect($answer->expected)->toBe(5.25);
});

it('normalises probabilities that do not add up to 1 in floating point', function (): void {
    // Probabilities that do not add up to 1 in floating point, nearly all on the top level.
    $answer = new ScoreAnswer(Severity::Critical, 0.7, new Confidence(0.2, 'laya'), severityDistribution(0.1, 0.2, 0.7000000000000001));

    expect($answer->expected)->toBeGreaterThanOrEqual(1.0)->toBeLessThanOrEqual(10.0)
        ->and($answer->expected)->toEqualWithDelta(0.1 + 0.2 * 5 + 0.7 * 10, 1e-12);
});

it('rejects a distribution that gives no level any probability', function (): void {
    majorAnswer(distribution: severityDistribution(0.0, 0.0, 0.0));
})->throws(InvalidDistribution::class, 'A score distribution over '.Severity::class.' must give some level a probability above 0.');

it('rejects a distribution over a string-backed enum, whose values are no levels', function (): void {
    new ScoreAnswer(Department::Billing, 0.5, new Confidence(0.1, 'laya'), new Distribution([
        ['case' => Department::Billing, 'probability' => 0.5],
        ['case' => Department::Technical, 'probability' => 0.25],
        ['case' => Department::Other, 'probability' => 0.25],
    ]));
})->throws(InvalidDistribution::class, 'A score distribution must be over an int-backed enum, whose backing values are the levels; '.Department::class.' is not one.');

it('gives the probability of any level', function (): void {
    expect(majorAnswer()->probabilityOf(Severity::Critical))->toBe(0.25)
        ->and(majorAnswer()->probabilityOf(Severity::Major))->toBe(0.5);
});

it('is confident when answerConfidence reaches the threshold', function (float $threshold, bool $confident): void {
    expect(majorAnswer(answerConfidence: 0.6)->isConfident($threshold))->toBe($confident);
})->with([
    [0.0, true],
    [0.6, true],
    [0.6001, false],
    [1.0, false],
]);

it('compares against answerConfidence, not the distribution', function (): void {
    // P(Major) is 0.5; only answerConfidence counts.
    expect(majorAnswer(answerConfidence: 0.9)->isConfident(0.8))->toBeTrue()
        ->and(majorAnswer(answerConfidence: 0.4)->isConfident(0.45))->toBeFalse();
});

it('rejects a threshold outside [0, 1]', function (float $threshold): void {
    majorAnswer()->isConfident($threshold);
})->with([-0.1, 1.1, NAN])->throws(InvalidProbability::class, 'The threshold must be between 0 and 1');

it('rejects an answerConfidence outside [0, 1]', function (float $answerConfidence): void {
    majorAnswer(answerConfidence: $answerConfidence);
})->with([-0.1, 1.1, NAN])->throws(InvalidProbability::class, 'The answer confidence must be between 0 and 1');

it('rejects a value the distribution is not over', function (): void {
    new ScoreAnswer(Department::Billing, 0.5, new Confidence(0.1, 'laya'), severityDistribution());
})->throws(InvalidDistribution::class, Department::class.'::Billing is not a case of this distribution');

it('cannot be changed after construction', function (): void {
    $answer = majorAnswer();

    expect(fn () => $answer->expected = 1.0)->toThrow(Error::class, 'Cannot modify readonly property');
});

it('rejects a value that is not a most likely level', function (): void {
    // Critical is the most likely level (0.5), not Major (0.25).
    majorAnswer(distribution: severityDistribution(0.25, 0.25, 0.5));
})->throws(InvalidDistribution::class, 'A score answer\'s value must be a most likely level: '.Severity::class.'::Major has probability 0.25, but another level has 0.5.');

it('takes any of equally likely levels as the value', function (): void {
    $answer = new ScoreAnswer(Severity::Critical, 0.5, new Confidence(0.2, 'laya'), severityDistribution(0.5, 0.0, 0.5));

    expect($answer->value)->toBe(Severity::Critical)
        ->and($answer->expected)->toBe(5.5);
});
