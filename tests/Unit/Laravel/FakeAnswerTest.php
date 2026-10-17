<?php

declare(strict_types=1);

use EarthlingOllie\SystemOneClassification\Domain\Question;
use EarthlingOllie\SystemOneClassification\Domain\Question\ChoiceQuestion;
use EarthlingOllie\SystemOneClassification\Domain\Question\QuestionHandle;
use EarthlingOllie\SystemOneClassification\Domain\Question\ScoreQuestion;
use EarthlingOllie\SystemOneClassification\Exceptions\InvalidDecision;
use EarthlingOllie\SystemOneClassification\Exceptions\InvalidDistribution;
use EarthlingOllie\SystemOneClassification\Exceptions\InvalidFakeAnswer;
use EarthlingOllie\SystemOneClassification\Exceptions\InvalidProbability;
use EarthlingOllie\SystemOneClassification\Laravel\Testing\FakeAnswer;
use EarthlingOllie\SystemOneClassification\Result\Answer\ChoiceAnswer;
use EarthlingOllie\SystemOneClassification\Result\Answer\ScoreAnswer;
use EarthlingOllie\SystemOneClassification\Result\Answer\YesNoAnswer;
use EarthlingOllie\SystemOneClassification\Result\Confidence;
use EarthlingOllie\SystemOneClassification\Tests\Fixtures\Enums\Channel;
use EarthlingOllie\SystemOneClassification\Tests\Fixtures\Enums\Department;
use EarthlingOllie\SystemOneClassification\Tests\Fixtures\Enums\OnlyOption;
use EarthlingOllie\SystemOneClassification\Tests\Fixtures\Enums\Severity;
use EarthlingOllie\SystemOneClassification\Tests\Fixtures\Enums\Urgency;

/** @return ChoiceQuestion<Department> */
function fakeDepartment(): ChoiceQuestion
{
    return Question::choice('department', Department::class, 'Which department should handle this?');
}

/** @return ScoreQuestion<Urgency> */
function fakeUrgency(): ScoreQuestion
{
    return Question::score('urgency', Urgency::class, 'How urgent is this?');
}

/**
 * @param  ChoiceAnswer<BackedEnum>|ScoreAnswer<BackedEnum>  $answer
 * @return array<int|string, float>
 */
function probabilities(ChoiceAnswer|ScoreAnswer $answer): array
{
    $probabilities = [];

    foreach ($answer->distribution->entries() as ['case' => $case, 'probability' => $probability]) {
        $probabilities[$case->value] = $probability;
    }

    return $probabilities;
}

it('scripts a yes/no answer from a probability', function (): void {
    $churn = Question::yesNo('churn', 'Does the customer threaten to cancel?');
    $scripted = FakeAnswer::yesNo($churn, 0.91);

    expect($scripted->question)->toBe($churn)
        ->and($scripted->answerFor('laya'))->toEqual(new YesNoAnswer(0.91));
});

it('refuses a yes/no probability outside [0, 1]', function (): void {
    FakeAnswer::yesNo(Question::yesNo('churn', 'Cancel?'), 1.5);
})->throws(InvalidProbability::class, 'A probability must be between 0 and 1, got 1.5.');

it('scripts a certain choice answer from an enum case', function (): void {
    $answer = FakeAnswer::choice(fakeDepartment(), Department::Technical)->answerFor('laya');

    expect($answer)->toBeInstanceOf(ChoiceAnswer::class)
        ->and($answer->value)->toBe(Department::Technical)
        ->and($answer->answerConfidence)->toBe(1.0)
        ->and($answer->confidence)->toEqual(new Confidence(1.0, 'laya'))
        ->and(probabilities($answer))->toBe(['billing' => 0.0, 'technical' => 1.0, 'other' => 0.0]);
});

it('scripts a choice answer with a confidence, sharing the rest evenly among the other options', function (): void {
    $answer = FakeAnswer::choice(fakeDepartment(), Department::Technical, confidence: 0.6)->answerFor('jev');

    expect($answer->value)->toBe(Department::Technical)
        ->and($answer->answerConfidence)->toBe(0.6)
        ->and(probabilities($answer))->toEqualWithDelta(['billing' => 0.2, 'technical' => 0.6, 'other' => 0.2], 1e-12)
        // Laya's raw confidence, 1 - normalised entropy, tagged with the connection's profile.
        ->and($answer->confidence->value)->toEqualWithDelta(0.1350264792820728, 1e-12)
        ->and($answer->confidence->profile)->toBe('jev');
});

it('gives the other option of two the rest of the probability', function (): void {
    $channel = Question::choice('channel', Channel::class, 'How did they get in touch?');
    $answer = FakeAnswer::choice($channel, Channel::Phone, confidence: 0.7)->answerFor('laya');

    expect(probabilities($answer))->toEqualWithDelta(['email' => 0.3, 'phone' => 0.7], 1e-12)
        // 1 - H / log(2): two options are normalised by log 2.
        ->and($answer->confidence->value)->toEqualWithDelta(1 + (0.3 * log(0.3) + 0.7 * log(0.7)) / log(2), 1e-12);
});

it('takes an even split as a confidence, the lowest the model can report for its answer', function (): void {
    $answer = FakeAnswer::choice(fakeDepartment(), Department::Other, confidence: 1 / 3)->answerFor('laya');

    expect($answer->answerConfidence)->toBe(1 / 3)
        ->and($answer->confidence->value)->toEqualWithDelta(0.0, 1e-12);
});

it('refuses a choice confidence below an even split, where another option would be more likely', function (): void {
    FakeAnswer::choice(fakeDepartment(), Department::Billing, confidence: 0.3);
})->throws(InvalidFakeAnswer::class, "The scripted answer to 'department' cannot have a confidence of 0.3: with 3 options, the chosen one is at least 1/3 likely.");

it('scripts the only option of a choice with certainty, and nothing less', function (): void {
    $only = Question::choice('queue', OnlyOption::class, 'Which queue?');
    $answer = FakeAnswer::choice($only, OnlyOption::Queue)->answerFor('laya');

    expect($answer->confidence->value)->toBe(1.0)
        ->and(probabilities($answer))->toBe(['queue' => 1.0])
        ->and(fn (): FakeAnswer => FakeAnswer::choice($only, OnlyOption::Queue, confidence: 0.99))
        ->toThrow(InvalidFakeAnswer::class, 'with 1 options, the chosen one is at least 1/1 likely.');
});

it('refuses a choice confidence outside [0, 1]', function (): void {
    FakeAnswer::choice(fakeDepartment(), Department::Billing, confidence: 1.2);
})->throws(InvalidProbability::class, 'The answer confidence must be between 0 and 1, got 1.2.');

it('refuses a choice case from another enum', function (): void {
    FakeAnswer::choice(fakeDepartment(), Severity::Major);
})->throws(InvalidDistribution::class);

it('scripts a certain score answer from an enum case', function (): void {
    $answer = FakeAnswer::score(fakeUrgency(), Urgency::High)->answerFor('laya');

    expect($answer)->toBeInstanceOf(ScoreAnswer::class)
        ->and($answer->value)->toBe(Urgency::High)
        ->and($answer->expected)->toBe(2.0)
        ->and($answer->answerConfidence)->toBe(1.0)
        ->and($answer->confidence)->toEqual(new Confidence(1.0, 'laya'))
        ->and(probabilities($answer))->toBe([0 => 0.0, 1 => 0.0, 2 => 1.0, 3 => 0.0]);
});

it('scripts a certain answer at the highest level, which no level can pull up', function (): void {
    $answer = FakeAnswer::score(fakeUrgency(), Urgency::Critical)->answerFor('laya');

    expect($answer->expected)->toBe(3.0)
        ->and(probabilities($answer))->toBe([0 => 0.0, 1 => 0.0, 2 => 0.0, 3 => 1.0]);
});

it('scripts a score answer with an expected level, keeping the case the most likely level', function (Urgency $case, float $expected, array $distribution, float $confidence): void {
    $answer = FakeAnswer::score(fakeUrgency(), $case, expected: $expected)->answerFor('laya');

    expect($answer->value)->toBe($case)
        ->and($answer->expected)->toEqualWithDelta($expected, 1e-12)
        ->and(probabilities($answer))->toEqualWithDelta($distribution, 1e-12)
        ->and($answer->answerConfidence)->toEqualWithDelta($distribution[$case->value], 1e-12)
        ->and($answer->confidence->value)->toEqualWithDelta($confidence, 1e-12);
})->with([
    // Down from High (2) towards Low (0): the lowest mean a set with High in it reaches is 1.
    'below the case' => [Urgency::High, 1.7, [0 => 0.15, 1 => 0.0, 2 => 0.85, 3 => 0.0], 0.6950798476417998],
    // Up from High towards Critical (3): {High, Critical} reaches 2.5.
    'above the case' => [Urgency::High, 2.4, [0 => 0.0, 1 => 0.0, 2 => 0.6, 3 => 0.4], 0.5145247027726656],
    // Up from Low: {Low, High, Critical} has the highest mean a set with Low in it reaches, 5/3.
    'above the lowest level' => [Urgency::Low, 0.5, [0 => 0.8, 1 => 0.0, 2 => 0.1, 3 => 0.1], 1 - (-(0.8 * log(0.8) + 0.2 * log(0.1)) / log(4))],
    'at the case' => [Urgency::Medium, 1.0, [0 => 0.0, 1 => 1.0, 2 => 0.0, 3 => 0.0], 1.0],
    // Nothing is above Critical (3), so only its own value is reachable from above.
    'at the highest level' => [Urgency::Critical, 3.0, [0 => 0.0, 1 => 0.0, 2 => 0.0, 3 => 1.0], 1.0],
    // {Medium, Critical} has mean 2; High (2) would not move it, so it is left out.
    'with a level at the mean left out' => [Urgency::Medium, 1.5, [0 => 0.0, 1 => 0.75, 2 => 0.0, 3 => 0.25], 1 - (-(0.75 * log(0.75) + 0.25 * log(0.25)) / log(4))],
]);

it('scripts a score answer at the edge of what its case allows', function (): void {
    $answer = FakeAnswer::score(fakeUrgency(), Urgency::High, expected: 2.5)->answerFor('laya');

    expect(probabilities($answer))->toEqualWithDelta([0 => 0.0, 1 => 0.0, 2 => 0.5, 3 => 0.5], 1e-12)
        ->and($answer->expected)->toEqualWithDelta(2.5, 1e-12);
});

it('refuses an expected level its case cannot be the most likely level for', function (Urgency $case, float $expected, string $message): void {
    expect(fn (): FakeAnswer => FakeAnswer::score(fakeUrgency(), $case, expected: $expected))
        ->toThrow(InvalidFakeAnswer::class, $message);
})->with([
    'too high' => [Urgency::High, 2.6, "The scripted answer to 'urgency' cannot have an expected level of 2.6 with High as its most likely level: that level allows more than 1, up to 2.5."],
    'too low' => [Urgency::Low, -0.1, "The scripted answer to 'urgency' cannot have an expected level of -0.1 with Low as its most likely level: that level allows from 0, up to 1.6666666666667."],
    'not a number' => [Urgency::Low, NAN, "The scripted answer to 'urgency' cannot have an expected level of NAN with Low as its most likely level: that level allows from 0, up to 1.6666666666667."],
    // {Low, High} at 0.5 each: High would only tie with Low, and a tie goes to the lower level.
    'at the lower edge' => [Urgency::High, 1.0, "The scripted answer to 'urgency' cannot have an expected level of 1 with High as its most likely level: that level allows more than 1, up to 2.5."],
]);

it('takes the upper edge, where the case ties only with higher levels and a tie goes to the lowest', function (): void {
    $answer = FakeAnswer::score(fakeUrgency(), Urgency::Medium, expected: 2.0)->answerFor('laya');

    expect(probabilities($answer))->toEqualWithDelta([0 => 0.0, 1 => 0.5, 2 => 0.0, 3 => 0.5], 1e-12);
});

it('refuses a score case from another enum, whatever the expected level', function (?float $expected): void {
    FakeAnswer::score(fakeUrgency(), Severity::Major, $expected);
})->with(['none' => [null], 'one no Urgency level reaches' => [9.0]])
    ->throws(InvalidDistribution::class, Severity::class.'::Major is not a case of this distribution over '.Urgency::class.'.');

it('gives each question type a certain default when it has no script', function (): void {
    $churn = Question::yesNo('churn', 'Cancel?');

    expect(FakeAnswer::unscripted($churn)->answerFor('laya'))->toEqual(new YesNoAnswer(0.0))
        ->and(FakeAnswer::unscripted(fakeDepartment())->answerFor('laya'))->toEqual(FakeAnswer::choice(fakeDepartment(), Department::Billing)->answerFor('laya'))
        ->and(FakeAnswer::unscripted(fakeUrgency())->answerFor('laya'))->toEqual(FakeAnswer::score(fakeUrgency(), Urgency::Low)->answerFor('laya'));
});

it('has no default for a question type the wire format does not have', function (): void {
    FakeAnswer::unscripted(new class implements QuestionHandle
    {
        public string $key = 'mystery';

        public string $instructions = 'What is it?';

        public function answerType(): string
        {
            return YesNoAnswer::class;
        }
    });
})->throws(InvalidDecision::class, 'The wire format has no question type for');
