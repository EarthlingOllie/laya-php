<?php

declare(strict_types=1);

use EarthlingOllie\SystemOneClassification\Domain\Describe;
use EarthlingOllie\SystemOneClassification\Domain\Question;
use EarthlingOllie\SystemOneClassification\Domain\Question\ScoreQuestion;
use EarthlingOllie\SystemOneClassification\Exceptions\InvalidDecision;
use EarthlingOllie\SystemOneClassification\Result\Answer\ScoreAnswer;
use EarthlingOllie\SystemOneClassification\Tests\Fixtures\Enums\BackedEnumInterface;
use EarthlingOllie\SystemOneClassification\Tests\Fixtures\Enums\BlankLevel;
use EarthlingOllie\SystemOneClassification\Tests\Fixtures\Enums\BlankTranslation;
use EarthlingOllie\SystemOneClassification\Tests\Fixtures\Enums\Department;
use EarthlingOllie\SystemOneClassification\Tests\Fixtures\Enums\NoLevels;
use EarthlingOllie\SystemOneClassification\Tests\Fixtures\Enums\Rating;
use EarthlingOllie\SystemOneClassification\Tests\Fixtures\Enums\RepeatedLevelDescribe;
use EarthlingOllie\SystemOneClassification\Tests\Fixtures\Enums\Severity;
use EarthlingOllie\SystemOneClassification\Tests\Fixtures\Enums\TranslatedSeverity;
use EarthlingOllie\SystemOneClassification\Tests\Fixtures\Enums\Unbacked;
use EarthlingOllie\SystemOneClassification\Tests\Fixtures\Enums\UndescribedLevel;
use EarthlingOllie\SystemOneClassification\Tests\Fixtures\Enums\Urgency;

it('builds a score question over an int-backed enum with its key and instructions', function (): void {
    $question = Question::score('urgency', Urgency::class, 'How urgent is this?');

    expect($question)->toBeInstanceOf(ScoreQuestion::class)
        ->and($question->key)->toBe('urgency')
        ->and($question->instructions)->toBe('How urgent is this?')
        ->and($question->enum)->toBe(Urgency::class)
        ->and($question->answerType())->toBe(ScoreAnswer::class);
});

it('has one level per case, each with its backing value and #[Describe] text', function (): void {
    expect(Question::score('urgency', Urgency::class, 'How urgent is this?')->levels())->toBe([
        ['case' => Urgency::Low, 'value' => 0, 'description' => 'can wait'],
        ['case' => Urgency::Medium, 'value' => 1, 'description' => 'soon'],
        ['case' => Urgency::High, 'value' => 2, 'description' => 'today'],
        ['case' => Urgency::Critical, 'value' => 3, 'description' => 'blocking'],
    ]);
});

it('orders the levels by backing value, not by the order the cases are declared in', function (): void {
    expect(Question::score('severity', Severity::class, 'How severe is this?')->levels())->toBe([
        ['case' => Severity::Minor, 'value' => 1, 'description' => 'a cosmetic issue'],
        ['case' => Severity::Major, 'value' => 5, 'description' => 'a feature is broken'],
        ['case' => Severity::Critical, 'value' => 10, 'description' => 'the service is down'],
    ]);
});

// Review finding 8: the one list both the request and the legend check use.
it('gives the level descriptions as the criteria the server reads, lowest level first', function (): void {
    expect(Question::score('severity', Severity::class, 'How severe is this?')->criteria())
        ->toBe(['a cosmetic issue', 'a feature is broken', 'the service is down']);
});

it('prefers DescribesCases over the #[Describe] attribute', function (): void {
    expect(Question::score('severity', TranslatedSeverity::class, 'Wie dringend?')->levels())->toBe([
        ['case' => TranslatedSeverity::Low, 'value' => 1, 'description' => 'kann warten'],
        ['case' => TranslatedSeverity::High, 'value' => 2, 'description' => 'dringend'],
    ]);
});

it('rejects a class that is not an int-backed enum', function (string $class): void {
    new ScoreQuestion('urgency', $class, 'How urgent?');
})->with([
    'a string-backed enum' => [Department::class],
    'a string-backed enum with whole-number values' => [Rating::class],
    'a pure enum' => [Unbacked::class],
    'a class' => [stdClass::class],
    'the BackedEnum interface' => [BackedEnum::class],
    'an interface extending BackedEnum' => [BackedEnumInterface::class],
    'no class at all' => ['NoSuchEnum'],
])->throws(InvalidDecision::class, 'is not an int-backed enum');

it('names the class that is not an int-backed enum', function (): void {
    new ScoreQuestion('urgency', Department::class, 'How urgent?');
})->throws(InvalidDecision::class, "Score question 'urgency' needs an int-backed enum (enum X: int), whose backing values order its levels; ".Department::class.' is not an int-backed enum.');

it('rejects an enum with no cases', function (): void {
    Question::score('nothing', NoLevels::class, 'How much?');
})->throws(InvalidDecision::class, "Score question 'nothing' has no levels: ".NoLevels::class.' has no cases.');

// Laya answers a score question with an undescribed level with a 422; catch it before sending.
it('rejects a level without a description, naming the case', function (): void {
    Question::score('health', UndescribedLevel::class, 'How healthy?');
})->throws(InvalidDecision::class, "Level 1 of score question 'health' has no description: give ".UndescribedLevel::class.'::Bad a #[Describe], because Laya rejects a score level without one.');

it('rejects a blank description, from the attribute or the interface, naming the case', function (string $enum, string $message): void {
    expect(fn () => Question::score('health', $enum, 'How healthy?'))->toThrow(InvalidDecision::class, $message);
})->with([
    'attribute' => [BlankLevel::class, "Level 1 of score question 'health' has a blank description: describe ".BlankLevel::class.'::Blank, because Laya rejects a score level without one.'],
    'interface' => [BlankTranslation::class, "Level 7 of score question 'health' has a blank description: describe ".BlankTranslation::class.'::Only, because Laya rejects a score level without one.'],
]);

it('rejects a #[Describe] that PHP cannot build, naming the case and keeping the cause', function (): void {
    try {
        Question::score('broken', RepeatedLevelDescribe::class, 'How broken?');
    } catch (InvalidDecision $e) {
        expect($e->getMessage())->toBe("Level 0 of score question 'broken' has a #[Describe] on ".RepeatedLevelDescribe::class.'::Broken that PHP cannot build: Attribute "'.Describe::class.'" must not be repeated')
            ->and($e->getPrevious())->toBeInstanceOf(Error::class);

        return;
    }

    throw new RuntimeException('No InvalidDecision was thrown.');
});

it('validates the key like every other question', function (string $key): void {
    Question::score($key, Urgency::class, 'How urgent?');
})->with(['', ' ', '0', '42'])->throws(InvalidDecision::class);

it('rejects empty instructions', function (string $instructions): void {
    Question::score('urgency', Urgency::class, $instructions);
})->with(['', '   '])->throws(InvalidDecision::class, "Question 'urgency' needs instructions.");

it('compares by value: equal questions built apart are equal', function (): void {
    $a = Question::score('urgency', Urgency::class, 'How urgent?');
    $b = Question::score('urgency', Urgency::class, 'How urgent?');

    expect((array) $a)->toBe((array) $b);
});

it('cannot be changed after construction', function (): void {
    $question = Question::score('urgency', Urgency::class, 'How urgent?');

    expect(fn () => $question->instructions = 'Other?')->toThrow(Error::class, 'Cannot modify readonly property');
});
