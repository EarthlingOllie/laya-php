<?php

declare(strict_types=1);

use EarthlingOllie\SystemOneClassification\Domain\Context;
use EarthlingOllie\SystemOneClassification\Domain\Decision;
use EarthlingOllie\SystemOneClassification\Domain\Question;
use EarthlingOllie\SystemOneClassification\Exceptions\RejectedByModel;
use EarthlingOllie\SystemOneClassification\Protocol\SystemOneCodec;
use EarthlingOllie\SystemOneClassification\Tests\Fixtures\Enums\Urgency;
use EarthlingOllie\SystemOneClassification\Tests\Support\LayaFixtures;

/*
 * The codec against the `score` scenario recorded from a real laya-serve (see LayaFixtures
 * for which recording is in use). The recording's values are meaningless when it is
 * provisional, so the decoded answer is compared with the recorded payload, field by field.
 */

/** The decision bin/laya-record-fixtures sends for the `score` scenario (bin/lib/laya_payloads.py). */
function recordedScoreDecision(): array
{
    $urgency = Question::score('urgency', Urgency::class, 'How urgent is this?');
    $ticket = 'Hi, we were billed twice for March. Please refund the duplicate charge today or we will cancel our plan.';

    return [Decision::about(Context::text($ticket))->ask($urgency), $urgency];
}

/** @return array<string, mixed> */
function recordedScoreAnswer(string $scenario): array
{
    $answers = LayaFixtures::responseBody($scenario)['answers'] ?? null;
    assert(is_array($answers) && is_array($answers['urgency']));

    return $answers['urgency'];
}

it('encodes a request that matches the recorded score request on the wire', function (): void {
    [$decision] = recordedScoreDecision();

    $wire = json_decode(json_encode((new SystemOneCodec)->encode($decision), JSON_THROW_ON_ERROR), true);

    expect($wire)->toBe(LayaFixtures::requestBody('score'))
        ->and(SystemOneCodec::PATH)->toBe(LayaFixtures::requestPath('score'));
});

it('decodes the recorded score response into the enum, the expected level, both confidences and the distribution', function (): void {
    [$decision, $urgency] = recordedScoreDecision();
    $recorded = recordedScoreAnswer('score');
    assert(is_array($recorded['probabilities']) && is_array($recorded['legend']));

    $answer = (new SystemOneCodec)->decode($decision, LayaFixtures::response('score'))->get($urgency);

    // Urgency is backed by 0..3, the level indexes the server reports, so its numbers carry over as they are.
    $probabilities = array_map(floatval(...), $recorded['probabilities']);
    $mostLikely = array_search(max($probabilities), $probabilities, true);

    expect($answer->value)->toBe(Urgency::from((int) $mostLikely))
        ->and($answer->expected)->toBe((float) $recorded['score'])
        ->and($answer->answerConfidence)->toBe((float) $recorded['answer_confidence'])
        ->and($answer->confidence->value)->toBe((float) $recorded['confidence'])
        ->and($answer->confidence->profile)->toBe('laya')
        ->and($answer->distribution->cases())->toBe(Urgency::cases());

    foreach ($probabilities as $index => $probability) {
        expect($answer->probabilityOf(Urgency::from((int) $index)))->toBe($probability)
            ->and($recorded['legend'][$index])->toBe($urgency->levels()[$index]['description']);
    }
});

it('reads the score answer out of the recorded multi-question and abstention responses', function (string $scenario): void {
    $urgency = Question::score('urgency', Urgency::class, 'How urgent is this?');
    $decision = Decision::about(Context::text('Charged twice'))->ask($urgency);
    $recorded = recordedScoreAnswer($scenario);

    $answer = (new SystemOneCodec)->decode($decision, LayaFixtures::response($scenario))->get($urgency);

    expect($answer->expected)->toBe((float) $recorded['score'])
        ->and($answer->answerConfidence)->toBe((float) $recorded['answer_confidence']);
})->with(['multiple_questions', 'below_min_confidence']);

// What laya-serve does with a level that has no description, and why ScoreQuestion refuses one
// before anything is sent.
it('matches the recorded 422 for an undescribed level', function (): void {
    [$decision] = recordedScoreDecision();

    expect(fn () => (new SystemOneCodec)->decode($decision, LayaFixtures::response('invalid_request')))
        ->toThrow(RejectedByModel::class, "score question 'urgency' has a null level at index 1; give every level a description");
});
