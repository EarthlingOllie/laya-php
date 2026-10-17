<?php

declare(strict_types=1);

use EarthlingOllie\SystemOneClassification\Domain\Context;
use EarthlingOllie\SystemOneClassification\Domain\Decision;
use EarthlingOllie\SystemOneClassification\Domain\Question;
use EarthlingOllie\SystemOneClassification\Domain\Question\ScoreQuestion;
use EarthlingOllie\SystemOneClassification\Exceptions\MalformedResponse;
use EarthlingOllie\SystemOneClassification\Model\JevProfile;
use EarthlingOllie\SystemOneClassification\Protocol\SystemOneCodec;
use EarthlingOllie\SystemOneClassification\Result\Answer\ScoreAnswer;
use EarthlingOllie\SystemOneClassification\Result\Confidence;
use EarthlingOllie\SystemOneClassification\Tests\Fixtures\Enums\Department;
use EarthlingOllie\SystemOneClassification\Tests\Fixtures\Enums\Severity;
use EarthlingOllie\SystemOneClassification\Tests\Fixtures\Enums\UndescribedLevel;
use EarthlingOllie\SystemOneClassification\Tests\Fixtures\Enums\Urgency;
use EarthlingOllie\SystemOneClassification\Transport\Response;

/** @return ScoreQuestion<Urgency> */
function urgencyQuestion(): ScoreQuestion
{
    return Question::score('urgency', Urgency::class, 'How urgent is this?');
}

/** @return ScoreQuestion<Severity> */
function severityQuestion(): ScoreQuestion
{
    return Question::score('severity', Severity::class, 'How severe is this?');
}

/** The request body exactly as it goes over the wire. */
function scoreWireJson(ScoreQuestion $question): string
{
    return json_encode((new SystemOneCodec)->encode(Decision::about(Context::text('Charged twice'))->ask($question)), JSON_THROW_ON_ERROR);
}

/**
 * @template TEnum of BackedEnum
 *
 * @param  ScoreQuestion<TEnum>  $question
 * @param  array<string, mixed>  $answer
 * @return ScoreAnswer<TEnum>
 */
function decodeScore(ScoreQuestion $question, array $answer, SystemOneCodec $codec = new SystemOneCodec): ScoreAnswer
{
    $body = json_encode(['answers' => [$question->key => $answer]], JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);

    return $codec->decode(Decision::about(Context::text('Charged twice'))->ask($question), new Response(200, $body))->get($question);
}

/**
 * The laya-serve answer to urgencyQuestion(), as on laya-ai.com/laya-api.
 *
 * @return array<string, mixed>
 */
function urgencyAnswer(array $overrides = []): array
{
    return [
        'type' => 'score',
        'score' => 2.1875,
        'legend' => ['0' => 'can wait', '1' => 'soon', '2' => 'today', '3' => 'blocking'],
        'probabilities' => ['0' => 0.0625, '1' => 0.0625, '2' => 0.5, '3' => 0.375],
        'confidence' => 0.3043,
        'answer_confidence' => 0.5,
        ...$overrides,
    ];
}

/**
 * An answer to severityQuestion() with the given expected level index (`score`).
 *
 * @return array<string, mixed>
 */
function severityAnswer(float $score, array $overrides = []): array
{
    return [
        'type' => 'score',
        'score' => $score,
        'legend' => ['0' => 'a cosmetic issue', '1' => 'a feature is broken', '2' => 'the service is down'],
        'probabilities' => ['0' => 0.2, '1' => 0.5, '2' => 0.3],
        'confidence' => 0.1,
        ...$overrides,
    ];
}

it('sends a score question with its level descriptions as a list, lowest level first', function (): void {
    expect(scoreWireJson(urgencyQuestion()))->toBe(json_encode([
        'state' => 'Charged twice',
        'questions' => ['urgency' => [
            'type' => 'score',
            'instructions' => 'How urgent is this?',
            'criteria' => ['can wait', 'soon', 'today', 'blocking'],
        ]],
    ], JSON_THROW_ON_ERROR));
});

it('sends the levels in backing-value order, not declaration order', function (): void {
    expect(scoreWireJson(severityQuestion()))
        ->toContain('"criteria":["a cosmetic issue","a feature is broken","the service is down"]');
});

it('decodes the most likely level, the expected level, both confidences and the distribution', function (): void {
    $answer = decodeScore(urgencyQuestion(), urgencyAnswer());

    expect($answer->value)->toBe(Urgency::High)
        ->and($answer->expected)->toBe(2.1875)
        ->and($answer->answerConfidence)->toBe(0.5)
        ->and($answer->confidence)->toEqual(new Confidence(0.3043, 'laya'))
        ->and($answer->distribution->entries())->toBe([
            ['case' => Urgency::Low, 'probability' => 0.0625],
            ['case' => Urgency::Medium, 'probability' => 0.0625],
            ['case' => Urgency::High, 'probability' => 0.5],
            ['case' => Urgency::Critical, 'probability' => 0.375],
        ]);
});

it('tags the raw confidence with the profile the codec was built for', function (): void {
    expect(decodeScore(urgencyQuestion(), urgencyAnswer(), new SystemOneCodec(new JevProfile))->confidence)
        ->toEqual(new Confidence(0.3043, 'jev'));
});

it('orders the distribution by level, whatever order the response lists them in', function (): void {
    $answer = decodeScore(urgencyQuestion(), urgencyAnswer([
        'probabilities' => ['3' => 0.375, '1' => 0.0625, '0' => 0.0625, '2' => 0.5],
        'legend' => ['3' => 'blocking', '2' => 'today', '0' => 'can wait', '1' => 'soon'],
    ]));

    expect($answer->distribution->cases())->toBe(Urgency::cases())
        ->and($answer->value)->toBe(Urgency::High);
});

it('maps the level indexes the server uses onto the backing values', function (): void {
    $answer = decodeScore(severityQuestion(), severityAnswer(1.0));

    expect($answer->value)->toBe(Severity::Major)
        ->and($answer->distribution->entries())->toBe([
            ['case' => Severity::Minor, 'probability' => 0.2],
            ['case' => Severity::Major, 'probability' => 0.5],
            ['case' => Severity::Critical, 'probability' => 0.3],
        ]);
});

// Review finding 1: with unevenly spaced levels, placing the server's expected *index* on the
// values is not the expected value. Half on Minor (1) and half on Critical (10) is 5.5.
it('takes the expected level over the backing values, also when they are unevenly spaced', function (): void {
    $answer = decodeScore(severityQuestion(), severityAnswer(1.0, [
        'probabilities' => ['0' => 0.5, '1' => 0.0, '2' => 0.5],
    ]));

    expect($answer->expected)->toBe(5.5);
});

// Review finding 2: expected follows the distribution the answer carries, so the two never
// contradict each other.
it('takes the expected level from the probabilities, consistent with the distribution', function (): void {
    $answer = decodeScore(urgencyQuestion(), urgencyAnswer([
        'score' => 0.0,
        'probabilities' => ['0' => 0.0, '1' => 0.0, '2' => 0.0, '3' => 1.0],
    ]));

    expect($answer->value)->toBe(Urgency::Critical)
        ->and($answer->expected)->toBe(3.0);
});

// For levels backed by 0..n-1 the expected level is the server's own `score`, the expected
// index, as laya computes it (sum of i * p_i).
it('agrees with the server\'s score when the levels are backed by 0..n-1', function (): void {
    $answer = decodeScore(urgencyQuestion(), urgencyAnswer());

    expect($answer->expected)->toBe(urgencyAnswer()['score']);
});

it('takes the lowest of equally likely levels as the value, as the server\'s argmax does', function (): void {
    $answer = decodeScore(urgencyQuestion(), urgencyAnswer([
        'score' => 1.5,
        'probabilities' => ['0' => 0.25, '1' => 0.25, '2' => 0.25, '3' => 0.25],
    ]));

    expect($answer->value)->toBe(Urgency::Low);
});

it('takes the probability of the most likely level as answerConfidence when the server sends none (Jev)', function (): void {
    expect(decodeScore(urgencyQuestion(), urgencyAnswer(['answer_confidence' => null]))->answerConfidence)->toBe(0.5);
});

it('takes answer_confidence as sent, even where it differs from the probability (calibrated)', function (): void {
    expect(decodeScore(urgencyQuestion(), urgencyAnswer(['answer_confidence' => 0.8]))->answerConfidence)->toBe(0.8);
});

it('accepts integer numbers', function (): void {
    $answer = decodeScore(urgencyQuestion(), urgencyAnswer([
        'score' => 3,
        'probabilities' => ['0' => 0, '1' => 0, '2' => 0, '3' => 1],
        'confidence' => 1,
        'answer_confidence' => 1,
    ]));

    expect($answer->value)->toBe(Urgency::Critical)
        ->and($answer->expected)->toBe(3.0)
        ->and($answer->answerConfidence)->toBe(1.0)
        ->and($answer->confidence->value)->toBe(1.0)
        ->and($answer->probabilityOf(Urgency::Critical))->toBe(1.0);
});

/*
 * Review finding: a level sent without a description (a profile that allows it, such as Jev)
 * goes out as null, and nothing recorded shows what a server puts in the legend for it. Only
 * the descriptions that were sent must come back; an undescribed level's entry may be null,
 * any text, or missing.
 */
function undescribedAnswer(array $legend): array
{
    return [
        'type' => 'score', 'score' => 0.5, 'legend' => $legend, 'confidence' => 0.2,
        'probabilities' => ['0' => 0.6, '1' => 0.4],
    ];
}

it('accepts any legend entry for a level that was sent without a description', function (array $legend): void {
    $question = Question::score('health', UndescribedLevel::class, 'How healthy?');

    expect(decodeScore($question, undescribedAnswer($legend), new SystemOneCodec(new JevProfile))->value)->toBe(UndescribedLevel::Fine);
})->with([
    'null, as sent' => [['0' => 'fine', '1' => null]],
    'text of its own' => [['0' => 'fine', '1' => 'level 1']],
    'left out' => [['0' => 'fine']],
]);

it('still checks the described levels and the indexes of a legend with an undescribed level', function (array $legend): void {
    $question = Question::score('health', UndescribedLevel::class, 'How healthy?');

    expect(fn () => decodeScore($question, undescribedAnswer($legend), new SystemOneCodec(new JevProfile)))
        ->toThrow(MalformedResponse::class, "The answer for 'health' has a legend that does not match the levels of ".UndescribedLevel::class.': expected {"0":"fine","1":null}');
})->with([
    'another description' => [['0' => 'good', '1' => null]],
    'a described level left out' => [['1' => null]],
    'an extra index' => [['0' => 'fine', '1' => null, '2' => 'worse']],
    'a non-text entry' => [['0' => 'fine', '1' => 5]],
]);

it('raises MalformedResponse for a score answer that does not match the protocol', function (array $overrides, string $message): void {
    try {
        decodeScore(urgencyQuestion(), urgencyAnswer($overrides));
        $this->fail('Expected MalformedResponse.');
    } catch (MalformedResponse $malformed) {
        expect($malformed->getMessage())->toBe($message)
            ->and($malformed->body)->toContain('"urgency"');
    }
})->with([
    'another type' => [['type' => 'choice'], "The answer for 'urgency' should have type 'score', got \"choice\"."],
    'no type' => [['type' => null], "The answer for 'urgency' should have type 'score', got null."],
    'no score' => [['score' => null], "The answer for 'urgency' has no numeric 'score'."],
    'a score that is not a number' => [['score' => '2.5'], "The answer for 'urgency' has no numeric 'score'."],
    'a score below the lowest level' => [['score' => -0.1], "The answer for 'urgency' has a 'score' outside the level indexes 0 to 3: -0.1."],
    'a score above the highest level' => [['score' => 3.0001], "The answer for 'urgency' has a 'score' outside the level indexes 0 to 3: 3.0001."],
    'no legend' => [['legend' => null], "The answer for 'urgency' has no 'legend' object."],
    'a legend with another description' => [
        ['legend' => ['0' => 'can wait', '1' => 'soon', '2' => 'tomorrow', '3' => 'blocking']],
        "The answer for 'urgency' has a legend that does not match the levels of ".Urgency::class.': expected {"0":"can wait","1":"soon","2":"today","3":"blocking"}, got {"0":"can wait","1":"soon","2":"tomorrow","3":"blocking"}.',
    ],
    'a legend missing a level' => [
        ['legend' => ['0' => 'can wait', '1' => 'soon', '2' => 'today']],
        "The answer for 'urgency' has a legend that does not match the levels of ".Urgency::class.': expected {"0":"can wait","1":"soon","2":"today","3":"blocking"}, got {"0":"can wait","1":"soon","2":"today"}.',
    ],
    'a legend with an extra level' => [
        ['legend' => ['0' => 'can wait', '1' => 'soon', '2' => 'today', '3' => 'blocking', '4' => 'on fire']],
        "The answer for 'urgency' has a legend that does not match the levels of ".Urgency::class.': expected {"0":"can wait","1":"soon","2":"today","3":"blocking"}, got {"0":"can wait","1":"soon","2":"today","3":"blocking","4":"on fire"}.',
    ],
    'a legend keyed by backing value names' => [
        ['legend' => ['Low' => 'can wait', 'Medium' => 'soon', 'High' => 'today', 'Critical' => 'blocking']],
        "The answer for 'urgency' has a legend that does not match the levels of ".Urgency::class.': expected {"0":"can wait","1":"soon","2":"today","3":"blocking"}, got {"Low":"can wait","Medium":"soon","High":"today","Critical":"blocking"}.',
    ],
    'a legend in another level order' => [
        ['legend' => ['0' => 'blocking', '1' => 'today', '2' => 'soon', '3' => 'can wait']],
        "The answer for 'urgency' has a legend that does not match the levels of ".Urgency::class.': expected {"0":"can wait","1":"soon","2":"today","3":"blocking"}, got {"0":"blocking","1":"today","2":"soon","3":"can wait"}.',
    ],
    'a legend shown with its slashes and accents as they are' => [
        ['legend' => ['0' => 'can wait', '1' => 'soon', '2' => 'today/tonight', '3' => 'très urgent']],
        "The answer for 'urgency' has a legend that does not match the levels of ".Urgency::class.': expected {"0":"can wait","1":"soon","2":"today","3":"blocking"}, got {"0":"can wait","1":"soon","2":"today/tonight","3":"très urgent"}.',
    ],
    'no probabilities' => [['probabilities' => null], "The answer for 'urgency' has no 'probabilities' object."],
    'a probability missing' => [
        ['probabilities' => ['0' => 0.1, '1' => 0.1, '3' => 0.8]],
        "The answer for 'urgency' has no numeric probability for '2' (".Urgency::class.'::High).',
    ],
    'a probability that is not a number' => [
        ['probabilities' => ['0' => 0.1, '1' => '0.1', '2' => 0.4, '3' => 0.4]],
        "The answer for 'urgency' has no numeric probability for '1' (".Urgency::class.'::Medium).',
    ],
    'a probability outside [0, 1]' => [
        ['probabilities' => ['0' => 0.0, '1' => 0.0, '2' => 0.0, '3' => 1.5]],
        "The answer for 'urgency' has a probability for '3' (".Urgency::class.'::Critical) outside [0, 1]: 1.5.',
    ],
    'probabilities that are all 0' => [
        ['probabilities' => ['0' => 0, '1' => 0.0, '2' => 0, '3' => 0.0]],
        "The answer for 'urgency' gives no level a probability above 0.",
    ],
    'a probability for a level the enum does not have' => [
        ['probabilities' => ['0' => 0.1, '1' => 0.1, '2' => 0.4, '3' => 0.4, '4' => 0.0]],
        "The answer for 'urgency' has probabilities for labels that are not levels of ".Urgency::class.": '4'.",
    ],
    'no raw confidence' => [['confidence' => null], "The answer for 'urgency' has no numeric 'confidence'."],
    'an answer_confidence that is not a number' => [
        ['answer_confidence' => 'high'],
        "The answer for 'urgency' has no numeric 'answer_confidence'.",
    ],
    'an answer_confidence outside [0, 1]' => [
        ['answer_confidence' => 1.5],
        "The answer for 'urgency' has a 'answer_confidence' outside [0, 1]: 1.5.",
    ],
]);

// Review finding 6: the server keys probabilities by level index, which for Severity (1, 5,
// 10) is not the backing value, so the message names the level too.
it('names the level behind a level index in a probability error', function (array $probabilities, string $message): void {
    expect(fn () => decodeScore(severityQuestion(), severityAnswer(1.0, ['probabilities' => $probabilities])))
        ->toThrow(MalformedResponse::class, $message);
})->with([
    'missing' => [['0' => 0.5, '2' => 0.5], "The answer for 'severity' has no numeric probability for '1' (".Severity::class.'::Major).'],
    'outside [0, 1]' => [['0' => 0.5, '1' => 0.0, '2' => 2.0], "The answer for 'severity' has a probability for '2' (".Severity::class.'::Critical) outside [0, 1]: 2.'],
]);

it('reports a missing raw confidence before a malformed answer_confidence, as choice does', function (): void {
    expect(fn () => decodeScore(urgencyQuestion(), urgencyAnswer(['confidence' => null, 'answer_confidence' => 'high'])))
        ->toThrow(MalformedResponse::class, "The answer for 'urgency' has no numeric 'confidence'.");
});

it('reads a score, a choice and a yes/no answer from one response', function (): void {
    $urgency = urgencyQuestion();
    $department = Question::choice('department', Department::class, 'Which department?');
    $churn = Question::yesNo('churn', 'Do they threaten to cancel?');
    $decision = Decision::about(Context::text('Charged twice'))->ask($department, $urgency, $churn);
    $body = json_encode(['answers' => [
        'department' => [
            'type' => 'choice',
            'choice' => 'billing',
            'probabilities' => ['billing' => 0.9, 'technical' => 0.05, 'other' => 0.05],
            'confidence' => 0.5,
        ],
        'urgency' => urgencyAnswer(),
        'churn' => ['type' => 'noul', 'noul' => 0.9],
    ]], JSON_THROW_ON_ERROR);

    $result = (new SystemOneCodec)->decode($decision, new Response(200, $body));

    expect($result->get($urgency)->value)->toBe(Urgency::High)
        ->and($result->get($department)->value)->toBe(Department::Billing)
        ->and($result->get($churn)->probability)->toBe(0.9);
});
