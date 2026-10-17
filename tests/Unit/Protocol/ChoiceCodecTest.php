<?php

declare(strict_types=1);

use EarthlingOllie\SystemOneClassification\Domain\Context;
use EarthlingOllie\SystemOneClassification\Domain\Decision;
use EarthlingOllie\SystemOneClassification\Domain\Question;
use EarthlingOllie\SystemOneClassification\Domain\Question\ChoiceQuestion;
use EarthlingOllie\SystemOneClassification\Exceptions\MalformedResponse;
use EarthlingOllie\SystemOneClassification\Protocol\SystemOneCodec;
use EarthlingOllie\SystemOneClassification\Result\Answer\ChoiceAnswer;
use EarthlingOllie\SystemOneClassification\Result\Confidence;
use EarthlingOllie\SystemOneClassification\Tests\Fixtures\Enums\Channel;
use EarthlingOllie\SystemOneClassification\Tests\Fixtures\Enums\Department;
use EarthlingOllie\SystemOneClassification\Tests\Fixtures\Enums\Priority;
use EarthlingOllie\SystemOneClassification\Tests\Fixtures\Enums\Rating;
use EarthlingOllie\SystemOneClassification\Transport\Response;

/** @return ChoiceQuestion<Department> */
function departmentQuestion(): ChoiceQuestion
{
    return Question::choice('department', Department::class, 'Which department should handle this?');
}

/** The request body exactly as it goes over the wire. */
function wireJson(ChoiceQuestion $question): string
{
    return json_encode((new SystemOneCodec)->encode(Decision::about(Context::text('Charged twice'))->ask($question)), JSON_THROW_ON_ERROR);
}

/**
 * @template TEnum of BackedEnum
 *
 * @param  ChoiceQuestion<TEnum>  $question
 * @param  array<string, mixed>  $answer
 * @return ChoiceAnswer<TEnum>
 */
function decodeChoice(ChoiceQuestion $question, array $answer, SystemOneCodec $codec = new SystemOneCodec): ChoiceAnswer
{
    $body = json_encode(['answers' => [$question->key => $answer]], JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);

    return $codec->decode(Decision::about(Context::text('Charged twice'))->ask($question), new Response(200, $body))->get($question);
}

/** @return array<string, mixed> */
function departmentAnswer(array $overrides = []): array
{
    return [
        'type' => 'choice',
        'choice' => 'billing',
        'probabilities' => ['billing' => 0.9281, 'technical' => 0.0412, 'other' => 0.0307],
        'confidence' => 0.4534,
        'answer_confidence' => 0.9281,
        ...$overrides,
    ];
}

it('sends a choice question with its options as label => description', function (): void {
    expect(wireJson(departmentQuestion()))->toBe(json_encode([
        'state' => 'Charged twice',
        'questions' => ['department' => [
            'type' => 'choice',
            'instructions' => 'Which department should handle this?',
            'criteria' => [
                'billing' => 'invoices, payments, refunds',
                'technical' => 'bugs, outages, system errors',
                'other' => 'everything else',
            ],
        ]],
    ], JSON_THROW_ON_ERROR));
});

it('sends whole-number labels as a JSON object, never a list', function (string $enum, string $criteria): void {
    expect(wireJson(Question::choice('q', $enum, 'Which one?')))->toContain('"criteria":'.$criteria.'}');
})->with([
    'int-backed 0..n-1' => [Priority::class, '{"0":"can wait","1":"this week","2":"today"}'],
    'whole-number strings' => [Rating::class, '{"0":null,"1":null,"10":null}'],
]);

it('sends an undescribed option with a null description, which Laya shows as the bare label', function (): void {
    expect(wireJson(Question::choice('channel', Channel::class, 'How did they reach us?')))
        ->toContain('"criteria":{"email":"a message sent by email","phone":null}');
});

it('decodes the chosen case, both confidences and the distribution', function (): void {
    $answer = decodeChoice(departmentQuestion(), departmentAnswer());

    expect($answer->value)->toBe(Department::Billing)
        ->and($answer->answerConfidence)->toBe(0.9281)
        ->and($answer->confidence)->toEqual(new Confidence(0.4534, 'laya'))
        ->and($answer->probabilityOf(Department::Technical))->toBe(0.0412)
        ->and($answer->probabilityOf(Department::Other))->toBe(0.0307);
});

it('tags the raw confidence with the profile the codec was built for', function (): void {
    expect(decodeChoice(departmentQuestion(), departmentAnswer(), new SystemOneCodec('jev'))->confidence)
        ->toEqual(new Confidence(0.4534, 'jev'));
});

it('orders the distribution as the enum declares its cases, not as the response lists them', function (): void {
    $answer = decodeChoice(departmentQuestion(), departmentAnswer([
        'probabilities' => ['other' => 0.0307, 'billing' => 0.9281, 'technical' => 0.0412],
    ]));

    expect($answer->distribution->cases())->toBe([Department::Billing, Department::Technical, Department::Other]);
});

it('decodes whole-number labels, which PHP reads back as integer keys', function (): void {
    $answer = decodeChoice(Question::choice('priority', Priority::class, 'How soon?'), [
        'type' => 'choice',
        'choice' => '2',
        'probabilities' => ['0' => 0.1, '1' => 0.2, '2' => 0.7],
        'confidence' => 0.3,
        'answer_confidence' => 0.7,
    ]);

    expect($answer->value)->toBe(Priority::High)
        ->and($answer->probabilityOf(Priority::Low))->toBe(0.1)
        ->and($answer->distribution->cases())->toBe([Priority::Low, Priority::Normal, Priority::High]);
});

it('decodes string labels that look like whole numbers', function (): void {
    $answer = decodeChoice(Question::choice('rating', Rating::class, 'Which rating?'), [
        'type' => 'choice',
        'choice' => '10',
        'probabilities' => ['0' => 0.1, '1' => 0.2, '10' => 0.7],
        'confidence' => 0.3,
    ]);

    expect($answer->value)->toBe(Rating::Ten)
        ->and($answer->probabilityOf(Rating::One))->toBe(0.2);
});

it('takes the probability of the chosen case as answerConfidence when the server sends none (Jev)', function (): void {
    $answer = decodeChoice(departmentQuestion(), departmentAnswer([
        'choice' => 'technical',
        'answer_confidence' => null,
    ]));

    expect($answer->value)->toBe(Department::Technical)
        ->and($answer->answerConfidence)->toBe(0.0412);
});

it('takes answer_confidence as sent, even where it differs from the probability (calibrated)', function (): void {
    expect(decodeChoice(departmentQuestion(), departmentAnswer(['answer_confidence' => 0.8]))->answerConfidence)->toBe(0.8);
});

it('accepts integer numbers', function (): void {
    $answer = decodeChoice(departmentQuestion(), departmentAnswer([
        'probabilities' => ['billing' => 1, 'technical' => 0, 'other' => 0],
        'confidence' => 1,
        'answer_confidence' => 1,
    ]));

    expect($answer->answerConfidence)->toBe(1.0)
        ->and($answer->confidence->value)->toBe(1.0)
        ->and($answer->distribution->entries())->toBe([
            ['case' => Department::Billing, 'probability' => 1.0],
            ['case' => Department::Technical, 'probability' => 0.0],
            ['case' => Department::Other, 'probability' => 0.0],
        ]);
});

it('accepts an answer_confidence at either bound of [0, 1]', function (int|float $bound): void {
    expect(decodeChoice(departmentQuestion(), departmentAnswer(['answer_confidence' => $bound]))->answerConfidence)->toBe((float) $bound);
})->with([0, 0.0, 1, 1.0]);

it('raises MalformedResponse for a choice answer that does not match the protocol', function (array $overrides, string $message): void {
    try {
        decodeChoice(departmentQuestion(), departmentAnswer($overrides));
        $this->fail('Expected MalformedResponse.');
    } catch (MalformedResponse $malformed) {
        expect($malformed->getMessage())->toBe($message)
            ->and($malformed->body)->toContain('"department"');
    }
})->with([
    'a label the enum does not have' => [
        ['choice' => 'sales'],
        "The answer for 'department' names 'sales', which is not an option of ".Department::class.'.',
    ],
    'a label in another case' => [
        ['choice' => 'Billing'],
        "The answer for 'department' names 'Billing', which is not an option of ".Department::class.'.',
    ],
    'another type' => [['type' => 'noul'], "The answer for 'department' should have type 'choice', got \"noul\"."],
    'no type' => [['type' => null], "The answer for 'department' should have type 'choice', got null."],
    'no choice' => [['choice' => null], "The answer for 'department' has no string 'choice'."],
    'a number as the choice' => [['choice' => 1], "The answer for 'department' has no string 'choice'."],
    'no probabilities' => [['probabilities' => null], "The answer for 'department' has no 'probabilities' object."],
    'a probability missing' => [
        ['probabilities' => ['billing' => 0.9, 'other' => 0.1]],
        "The answer for 'department' has no numeric probability for 'technical'.",
    ],
    'a probability that is not a number' => [
        ['probabilities' => ['billing' => 0.9, 'technical' => '0.05', 'other' => 0.05]],
        "The answer for 'department' has no numeric probability for 'technical'.",
    ],
    'a probability outside [0, 1]' => [
        ['probabilities' => ['billing' => 1.5, 'technical' => 0.0, 'other' => 0.0]],
        "The answer for 'department' has a probability for 'billing' outside [0, 1]: 1.5.",
    ],
    'a probability for a label the enum does not have' => [
        ['probabilities' => ['billing' => 0.9, 'technical' => 0.05, 'other' => 0.0, 'sales' => 0.05, 'hr' => 0.0]],
        "The answer for 'department' has probabilities for labels that are not options of ".Department::class.": 'sales', 'hr'.",
    ],
    'no raw confidence' => [['confidence' => null], "The answer for 'department' has no numeric 'confidence'."],
    'a raw confidence that is not a number' => [['confidence' => 'high'], "The answer for 'department' has no numeric 'confidence'."],
    'an answer_confidence that is not a number' => [
        ['answer_confidence' => 'high'],
        "The answer for 'department' has no numeric 'answer_confidence'.",
    ],
    'an answer_confidence outside [0, 1]' => [
        ['answer_confidence' => -0.5],
        "The answer for 'department' has a 'answer_confidence' outside [0, 1]: -0.5.",
    ],
]);

it('reads a choice and a yes/no answer from one response', function (): void {
    $department = departmentQuestion();
    $churn = Question::yesNo('churn', 'Do they threaten to cancel?');
    $decision = Decision::about(Context::text('Charged twice'))->ask($department, $churn);
    $body = json_encode(['answers' => [
        'department' => departmentAnswer(),
        'churn' => ['type' => 'noul', 'noul' => 0.9],
    ]], JSON_THROW_ON_ERROR);

    $result = (new SystemOneCodec)->decode($decision, new Response(200, $body));

    expect($result->get($department)->value)->toBe(Department::Billing)
        ->and($result->get($churn)->probability)->toBe(0.9);
});
