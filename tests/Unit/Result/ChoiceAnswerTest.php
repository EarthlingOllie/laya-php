<?php

declare(strict_types=1);

use EarthlingOllie\SystemOneClassification\Exceptions\InvalidDistribution;
use EarthlingOllie\SystemOneClassification\Exceptions\InvalidProbability;
use EarthlingOllie\SystemOneClassification\Result\Answer\ChoiceAnswer;
use EarthlingOllie\SystemOneClassification\Result\Confidence;
use EarthlingOllie\SystemOneClassification\Result\Distribution;
use EarthlingOllie\SystemOneClassification\Tests\Fixtures\Enums\Channel;
use EarthlingOllie\SystemOneClassification\Tests\Fixtures\Enums\Department;

/** @return ChoiceAnswer<Department> */
function billingAnswer(float $answerConfidence = 0.7): ChoiceAnswer
{
    return new ChoiceAnswer(
        Department::Billing,
        $answerConfidence,
        new Confidence(0.4534, 'laya'),
        new Distribution([
            ['case' => Department::Billing, 'probability' => 0.7],
            ['case' => Department::Technical, 'probability' => 0.2],
            ['case' => Department::Other, 'probability' => 0.1],
        ]),
    );
}

it('holds the chosen case, both confidences and the distribution', function (): void {
    $answer = billingAnswer();

    expect($answer->value)->toBe(Department::Billing)
        ->and($answer->answerConfidence)->toBe(0.7)
        ->and($answer->confidence)->toEqual(new Confidence(0.4534, 'laya'))
        ->and($answer->confidence->value)->toBe(0.4534)
        ->and($answer->confidence->profile)->toBe('laya')
        ->and($answer->distribution->cases())->toBe([Department::Billing, Department::Technical, Department::Other]);
});

it('gives the probability of any case', function (): void {
    expect(billingAnswer()->probabilityOf(Department::Technical))->toBe(0.2)
        ->and(billingAnswer()->probabilityOf(Department::Billing))->toBe(0.7);
});

it('is confident when answerConfidence reaches the threshold', function (float $threshold, bool $confident): void {
    expect(billingAnswer(answerConfidence: 0.7)->isConfident($threshold))->toBe($confident);
})->with([
    [0.0, true],
    [0.69, true],
    [0.7, true],
    [0.7001, false],
    [1.0, false],
]);

it('compares against answerConfidence, not the raw confidence or the distribution', function (): void {
    // The raw confidence (0.4534) is below 0.6 and P(billing) is 0.7; only answerConfidence counts.
    expect(billingAnswer(answerConfidence: 0.65)->isConfident(0.6))->toBeTrue()
        ->and(billingAnswer(answerConfidence: 0.55)->isConfident(0.6))->toBeFalse();
});

it('rejects a threshold outside [0, 1]', function (float $threshold): void {
    billingAnswer()->isConfident($threshold);
})->with([-0.1, 1.1, NAN])->throws(InvalidProbability::class, 'The threshold must be between 0 and 1');

it('rejects an answerConfidence outside [0, 1]', function (float $answerConfidence): void {
    billingAnswer($answerConfidence);
})->with([-0.1, 1.1, NAN])->throws(InvalidProbability::class, 'The answer confidence must be between 0 and 1');

it('rejects a value the distribution is not over', function (): void {
    new ChoiceAnswer(Channel::Email, 0.5, new Confidence(0.1, 'laya'), billingAnswer()->distribution);
})->throws(InvalidDistribution::class, Channel::class.'::Email is not a case of this distribution');

it('cannot be changed after construction', function (): void {
    $answer = billingAnswer();

    expect(fn () => $answer->answerConfidence = 0.1)->toThrow(Error::class, 'Cannot modify readonly property');
});
