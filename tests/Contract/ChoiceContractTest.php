<?php

declare(strict_types=1);

use EarthlingOllie\SystemOneClassification\Domain\Context;
use EarthlingOllie\SystemOneClassification\Domain\Decision;
use EarthlingOllie\SystemOneClassification\Domain\Question;
use EarthlingOllie\SystemOneClassification\Protocol\SystemOneCodec;
use EarthlingOllie\SystemOneClassification\Tests\Fixtures\Enums\Department;
use EarthlingOllie\SystemOneClassification\Tests\Support\LayaFixtures;

/*
 * The codec against the `choice` scenario recorded from a real laya-serve (see LayaFixtures
 * for which recording is in use). The recording's values are meaningless when it is
 * provisional, so the decoded answer is compared with the recorded payload, field by field.
 */

/** The decision bin/laya-record-fixtures sends for the `choice` scenario (bin/lib/laya_payloads.py). */
function recordedChoiceDecision(): array
{
    $department = Question::choice('department', Department::class, 'Which department should handle this?');
    $ticket = 'Hi, we were billed twice for March. Please refund the duplicate charge today or we will cancel our plan.';

    return [Decision::about(Context::text($ticket))->ask($department), $department];
}

/** @return array<string, mixed> */
function recordedAnswer(string $scenario, string $key): array
{
    $answers = LayaFixtures::responseBody($scenario)['answers'] ?? null;
    assert(is_array($answers) && is_array($answers[$key]));

    return $answers[$key];
}

it('encodes a request that matches the recorded choice request on the wire', function (): void {
    [$decision] = recordedChoiceDecision();

    $wire = json_decode(json_encode((new SystemOneCodec)->encode($decision), JSON_THROW_ON_ERROR), true);

    expect($wire)->toBe(LayaFixtures::requestBody('choice'))
        ->and(SystemOneCodec::PATH)->toBe(LayaFixtures::requestPath('choice'));
});

it('decodes the recorded choice response into the enum, both confidences and the distribution', function (): void {
    [$decision, $department] = recordedChoiceDecision();
    $recorded = recordedAnswer('choice', 'department');
    assert(is_string($recorded['choice']) && is_array($recorded['probabilities']));

    $answer = (new SystemOneCodec)->decode($decision, LayaFixtures::response('choice'))->get($department);

    expect($answer->value)->toBe(Department::from($recorded['choice']))
        ->and($answer->answerConfidence)->toBe((float) $recorded['answer_confidence'])
        ->and($answer->confidence->value)->toBe((float) $recorded['confidence'])
        ->and($answer->confidence->profile)->toBe('laya')
        ->and($answer->distribution->cases())->toBe(Department::cases());

    foreach ($recorded['probabilities'] as $label => $probability) {
        expect($answer->probabilityOf(Department::from((string) $label)))->toBe((float) $probability);
    }
});

it('reads the choice answer out of the recorded multi-question and abstention responses', function (string $scenario): void {
    $department = Question::choice('department', Department::class, 'Which department should handle this?');
    $decision = Decision::about(Context::text('Charged twice'))->ask($department);
    $recorded = recordedAnswer($scenario, 'department');
    assert(is_string($recorded['choice']));

    $answer = (new SystemOneCodec)->decode($decision, LayaFixtures::response($scenario))->get($department);

    expect($answer->value)->toBe(Department::from($recorded['choice']))
        ->and($answer->answerConfidence)->toBe((float) $recorded['answer_confidence']);
})->with(['multiple_questions', 'below_min_confidence']);
