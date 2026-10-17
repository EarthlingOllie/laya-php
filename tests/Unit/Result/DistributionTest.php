<?php

declare(strict_types=1);

use EarthlingOllie\SystemOneClassification\Exceptions\InvalidDistribution;
use EarthlingOllie\SystemOneClassification\Exceptions\InvalidProbability;
use EarthlingOllie\SystemOneClassification\Result\Distribution;
use EarthlingOllie\SystemOneClassification\Tests\Fixtures\Enums\Channel;
use EarthlingOllie\SystemOneClassification\Tests\Fixtures\Enums\Department;
use EarthlingOllie\SystemOneClassification\Tests\Fixtures\Enums\Priority;

/** @return Distribution<Department> */
function departments(float $billing = 0.7, float $technical = 0.2, float $other = 0.1): Distribution
{
    return new Distribution([
        ['case' => Department::Billing, 'probability' => $billing],
        ['case' => Department::Technical, 'probability' => $technical],
        ['case' => Department::Other, 'probability' => $other],
    ]);
}

it('gives the probability of each case', function (): void {
    $distribution = departments();

    expect($distribution->probabilityOf(Department::Billing))->toBe(0.7)
        ->and($distribution->probabilityOf(Department::Technical))->toBe(0.2)
        ->and($distribution->probabilityOf(Department::Other))->toBe(0.1);
});

it('keeps the order it was built in, whatever the probabilities', function (): void {
    $distribution = new Distribution([
        ['case' => Priority::High, 'probability' => 0.1],
        ['case' => Priority::Low, 'probability' => 0.6],
        ['case' => Priority::Normal, 'probability' => 0.3],
    ]);

    expect($distribution->cases())->toBe([Priority::High, Priority::Low, Priority::Normal])
        ->and($distribution->entries())->toBe([
            ['case' => Priority::High, 'probability' => 0.1],
            ['case' => Priority::Low, 'probability' => 0.6],
            ['case' => Priority::Normal, 'probability' => 0.3],
        ])
        ->and($distribution)->toHaveCount(3);
});

it('accepts the bounds of [0, 1]', function (): void {
    $distribution = new Distribution([
        ['case' => Channel::Email, 'probability' => 1.0],
        ['case' => Channel::Phone, 'probability' => 0.0],
    ]);

    expect($distribution->probabilityOf(Channel::Email))->toBe(1.0)
        ->and($distribution->probabilityOf(Channel::Phone))->toBe(0.0);
});

it('rejects a probability outside [0, 1]', function (float $probability): void {
    departments(technical: $probability);
})->with([-0.0001, 1.0001, NAN])->throws(InvalidProbability::class, "The probability of 'technical' must be between 0 and 1");

it('must cover every case of its enum exactly once', function (array $entries): void {
    new Distribution($entries);
})->with([
    'a case missing' => [[['case' => Department::Billing, 'probability' => 1.0], ['case' => Department::Other, 'probability' => 0.0]]],
    'a case twice' => [[
        ['case' => Department::Billing, 'probability' => 0.5],
        ['case' => Department::Billing, 'probability' => 0.3],
        ['case' => Department::Other, 'probability' => 0.2],
    ]],
    'a case of another enum' => [[
        ['case' => Department::Billing, 'probability' => 0.5],
        ['case' => Channel::Email, 'probability' => 0.3],
        ['case' => Department::Other, 'probability' => 0.2],
    ]],
])->throws(InvalidDistribution::class, 'A distribution must give every case of '.Department::class.' exactly one probability.');

it('rejects a distribution over nothing', function (): void {
    new Distribution([]);
})->throws(InvalidDistribution::class, 'A distribution needs at least one case.');

it('refuses the probability of a case it is not over', function (): void {
    departments()->probabilityOf(Channel::Email);
})->throws(InvalidDistribution::class, Channel::class.'::Email is not a case of this distribution over '.Department::class.'.');
