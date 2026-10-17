<?php

declare(strict_types=1);

use EarthlingOllie\SystemOneClassification\Domain\Context;
use EarthlingOllie\SystemOneClassification\Domain\Decision;
use EarthlingOllie\SystemOneClassification\Domain\Question;
use EarthlingOllie\SystemOneClassification\Model\JevProfile;
use EarthlingOllie\SystemOneClassification\Model\LayaProfile;
use EarthlingOllie\SystemOneClassification\Protocol\SystemOneCodec;
use EarthlingOllie\SystemOneClassification\Tests\Fixtures\Enums\Channel;
use EarthlingOllie\SystemOneClassification\Tests\Fixtures\Enums\Department;
use EarthlingOllie\SystemOneClassification\Tests\Fixtures\Enums\PartlyDescribedUrgency;
use EarthlingOllie\SystemOneClassification\Tests\Fixtures\Enums\Severity;
use EarthlingOllie\SystemOneClassification\Tests\Fixtures\Enums\Urgency;
use EarthlingOllie\SystemOneClassification\Tests\Support\JevSchema;
use EarthlingOllie\SystemOneClassification\Tests\Support\LayaFixtures;
use EarthlingOllie\SystemOneClassification\Tests\Support\OptionsEnum;

/*
 * Every request body the codec encodes, for every question type and request field it sends and
 * under both profiles, against Jev's request schema (issue #9). The schema comes from Jev's
 * saved OpenAPI document; until bin/fetch-jev-openapi has fetched it, from the PROVISIONAL
 * stand-in in tests/Fixtures/jev/provisional (see JevSchema).
 */

const JEV_TICKET = 'Hi, we were billed twice for March. Please refund the duplicate charge today or we will cancel our plan.';

/** @return array<string, array{SystemOneCodec, Decision, ?string}> codec, decision and default model */
function encodedDecisions(): array
{
    $about = Decision::about(Context::text(JEV_TICKET));
    $churn = Question::yesNo('churn', 'Does the customer threaten to cancel or leave?');
    $department = Question::choice('department', Department::class, 'Which department should handle this?');
    $urgency = Question::score('urgency', Urgency::class, 'How urgent is this?');
    $laya = new SystemOneCodec(new LayaProfile);
    $jev = new SystemOneCodec(new JevProfile(['jev-1']));

    return [
        'yes/no' => [$laya, $about->ask($churn), null],
        'choice' => [$laya, $about->ask($department), null],
        'choice with whole-number labels' => [$laya, $about->ask(Question::choice('channel', Channel::class, 'Which channel?')), null],
        'score' => [$laya, $about->ask($urgency), null],
        'score over gapped levels' => [$laya, $about->ask(Question::score('severity', Severity::class, 'How bad?')), null],
        'every type at once' => [$laya, $about->ask($churn, $department, $urgency), null],
        'pinned model' => [$laya, $about->ask($churn)->withModel('english'), null],
        "connection's default model" => [$laya, $about->ask($churn), 'multilingual'],
        'jev: undescribed score level' => [$jev, $about->ask(Question::score('urgency', PartlyDescribedUrgency::class, 'How urgent?')), null],
        'jev: 255 options' => [$jev, $about->ask(Question::choice('label', OptionsEnum::withCases(255), 'Which label fits?')), null],
        'jev: default model' => [$jev, $about->ask($churn, $department), 'jev-1'],
    ];
}

it('checks the bodies against the fetched Jev document when there is one, and says when it is the stand-in', function (): void {
    expect(JevSchema::path())->toBe(is_file(JevSchema::FETCHED) ? JevSchema::FETCHED : JevSchema::PROVISIONAL)
        ->and(JevSchema::isProvisional())->toBe(! is_file(JevSchema::FETCHED))
        ->and(JevSchema::document()->openapi)->toStartWith('3.');
});

it('encodes every request body as Jev\'s schema describes it', function (SystemOneCodec $codec, Decision $decision, ?string $model): void {
    $body = $codec->encode($decision, $model);

    expect(JevSchema::errors($body))->toBe([])
        ->and($body)->toHaveKeys(['state', 'questions']);
})->with(encodedDecisions());

it('agrees with every request laya-serve accepted the shape of', function (string $scenario): void {
    expect(JevSchema::errors(LayaFixtures::requestBody($scenario)))->toBe([]);
})->with(['noul', 'choice', 'score', 'multiple_questions', 'below_min_confidence', 'too_many_options', 'unknown_model', 'invalid_request', 'unauthorized_bad_key']);

// Any schema must refuse these. The messages are the stand-in's: Jev's own document may word
// or nest its errors differently, so they are checked only while the stand-in is in use.
it('is not vacuous: it refuses bodies the protocol does not allow', function (array $body, string $error): void {
    $errors = JevSchema::errors($body);

    expect($errors)->not->toBe([]);

    if (JevSchema::isProvisional()) {
        expect(implode("\n", $errors))->toContain($error);
    }
})->with([
    'no state (laya-serve answered 400)' => [LayaFixtures::requestBody('missing_state'), 'state: The property state is required'],
    'min_confidence above 1 (laya-serve answered 422)' => [LayaFixtures::requestBody('invalid_min_confidence'), 'min_confidence: Must have a maximum value less than or equal to 1'],
    'an unknown field' => [['state' => 'x', 'questions' => ['a' => ['type' => 'noul', 'instructions' => 'q']], 'modle' => 'english'], 'The property modle is not defined'],
    'an unknown question type' => [['state' => 'x', 'questions' => ['a' => ['type' => 'mystery', 'instructions' => 'q']]], 'questions.a: Failed to match exactly one schema'],
    'no questions' => [['state' => 'x', 'questions' => (object) []], 'questions: Must contain a minimum of 1 properties'],
    'a criterion that is not text' => [['state' => 'x', 'questions' => ['a' => ['type' => 'choice', 'instructions' => 'q', 'criteria' => ['b' => 5]]]], 'questions.a.criteria.b'],
    'score criteria as an object' => [['state' => 'x', 'questions' => ['a' => ['type' => 'score', 'instructions' => 'q', 'criteria' => ['0' => 'low', 'x' => 'high']]]], 'questions.a.criteria'],
]);
