<?php

declare(strict_types=1);

use EarthlingOllie\SystemOneClassification\Domain\Context;
use EarthlingOllie\SystemOneClassification\Domain\Decision;
use EarthlingOllie\SystemOneClassification\Domain\Question;
use EarthlingOllie\SystemOneClassification\Protocol\SystemOneCodec;
use EarthlingOllie\SystemOneClassification\Result\Abstention;
use EarthlingOllie\SystemOneClassification\Tests\Fixtures\Enums\Department;
use EarthlingOllie\SystemOneClassification\Tests\Fixtures\Enums\Urgency;
use EarthlingOllie\SystemOneClassification\Tests\Support\LayaFixtures;

/*
 * The request controls and the abstention gate against the `below_min_confidence` scenario
 * recorded from a real laya-serve (min_confidence 0.99 on an ambiguous state), which asks a
 * choice, a score and a yes/no question.
 */

/** The decision bin/laya-record-fixtures sends for `below_min_confidence`, and its questions. */
function recordedBelowMinConfidenceDecision(): array
{
    $department = Question::choice('department', Department::class, 'Which department should handle this?');
    $urgency = Question::score('urgency', Urgency::class, 'How urgent is this?');
    $churn = Question::yesNo('churn', 'Does the customer threaten to cancel or leave?');
    $decision = Decision::about(Context::text('Hello, I have a question.'))
        ->ask($department, $urgency, $churn)
        ->withMinConfidence(0.99);

    return [$decision, $department, $urgency, $churn];
}

it('encodes the decision exactly as recorded, min_confidence in the recorded place', function (): void {
    [$decision] = recordedBelowMinConfidenceDecision();

    // Compared as JSON: the codec sends a choice's criteria as an object (stdClass).
    expect(json_encode((new SystemOneCodec)->encode($decision), JSON_THROW_ON_ERROR))
        ->toBe(json_encode(LayaFixtures::requestBody('below_min_confidence'), JSON_THROW_ON_ERROR));
});

it('decodes every recorded abstained answer, keeping each best guess', function (): void {
    [$decision, $department, $urgency, $churn] = recordedBelowMinConfidenceDecision();
    $wire = LayaFixtures::responseBody('below_min_confidence')['answers'];
    assert(is_array($wire));

    $result = (new SystemOneCodec)->decode($decision, LayaFixtures::response('below_min_confidence'));

    foreach (['department' => $department, 'urgency' => $urgency, 'churn' => $churn] as $key => $question) {
        $answer = $result->get($question);
        assert(is_array($wire[$key]));

        expect($wire[$key]['abstention'])->toBe('abstained')
            ->and($answer->abstained)->toBeTrue()
            ->and($answer->abstention)->toBe(Abstention::Abstained)
            ->and($answer->abstentionThreshold)->toBe(0.99);
    }

    expect($result->get($department)->value)->toBe(Department::Technical)
        ->and($result->get($urgency)->value)->toBe(Urgency::Low)
        ->and($result->get($churn)->probability)->toBe(0.5);
});

it('reads answers recorded without min_confidence as not gated', function (string $scenario, Closure $question): void {
    $handle = $question();
    $decision = Decision::about(Context::text('x'))->ask($handle);

    $answer = (new SystemOneCodec)->decode($decision, LayaFixtures::response($scenario))->get($handle);

    expect($answer->abstained)->toBeFalse()
        ->and($answer->abstention)->toBe(Abstention::NotRequested)
        ->and($answer->abstentionThreshold)->toBeNull();
})->with([
    'yes/no' => ['noul', fn () => Question::yesNo('churn', 'Does the customer threaten to cancel or leave?')],
    'choice' => ['choice', fn () => Question::choice('department', Department::class, 'Which department should handle this?')],
    'score' => ['score', fn () => Question::score('urgency', Urgency::class, 'How urgent is this?')],
]);
