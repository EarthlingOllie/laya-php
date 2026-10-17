<?php

declare(strict_types=1);

use EarthlingOllie\SystemOneClassification\Domain\Describe;
use EarthlingOllie\SystemOneClassification\Domain\Question;
use EarthlingOllie\SystemOneClassification\Domain\Question\ChoiceQuestion;
use EarthlingOllie\SystemOneClassification\Exceptions\InvalidDecision;
use EarthlingOllie\SystemOneClassification\Result\Answer\ChoiceAnswer;
use EarthlingOllie\SystemOneClassification\Tests\Fixtures\Enums\BackedEnumInterface;
use EarthlingOllie\SystemOneClassification\Tests\Fixtures\Enums\BlankDescription;
use EarthlingOllie\SystemOneClassification\Tests\Fixtures\Enums\BlankLabel;
use EarthlingOllie\SystemOneClassification\Tests\Fixtures\Enums\BlankTranslation;
use EarthlingOllie\SystemOneClassification\Tests\Fixtures\Enums\Channel;
use EarthlingOllie\SystemOneClassification\Tests\Fixtures\Enums\Department;
use EarthlingOllie\SystemOneClassification\Tests\Fixtures\Enums\DescribeNotString;
use EarthlingOllie\SystemOneClassification\Tests\Fixtures\Enums\DescribeUnknownArgument;
use EarthlingOllie\SystemOneClassification\Tests\Fixtures\Enums\DescribeWithoutText;
use EarthlingOllie\SystemOneClassification\Tests\Fixtures\Enums\EmptyLabel;
use EarthlingOllie\SystemOneClassification\Tests\Fixtures\Enums\NoCases;
use EarthlingOllie\SystemOneClassification\Tests\Fixtures\Enums\Priority;
use EarthlingOllie\SystemOneClassification\Tests\Fixtures\Enums\RepeatedDescribe;
use EarthlingOllie\SystemOneClassification\Tests\Fixtures\Enums\TranslatedDepartment;
use EarthlingOllie\SystemOneClassification\Tests\Fixtures\Enums\Unbacked;

it('builds a choice question over an enum with its key and instructions', function (): void {
    $question = Question::choice('department', Department::class, 'Which department should handle this?');

    expect($question)->toBeInstanceOf(ChoiceQuestion::class)
        ->and($question->key)->toBe('department')
        ->and($question->instructions)->toBe('Which department should handle this?')
        ->and($question->enum)->toBe(Department::class)
        ->and($question->answerType())->toBe(ChoiceAnswer::class);
});

it('offers every case in declaration order, described by its #[Describe] attribute', function (): void {
    $question = Question::choice('department', Department::class, 'Which department should handle this?');

    expect($question->options())->toBe([
        ['case' => Department::Billing, 'description' => 'invoices, payments, refunds'],
        ['case' => Department::Technical, 'description' => 'bugs, outages, system errors'],
        ['case' => Department::Other, 'description' => 'everything else'],
    ]);
});

it('offers int-backed cases too', function (): void {
    expect(Question::choice('priority', Priority::class, 'How soon?')->options())->toBe([
        ['case' => Priority::Low, 'description' => 'can wait'],
        ['case' => Priority::Normal, 'description' => 'this week'],
        ['case' => Priority::High, 'description' => 'today'],
    ]);
});

it('leaves a case without #[Describe] undescribed', function (): void {
    expect(Question::choice('channel', Channel::class, 'How did they reach us?')->options())->toBe([
        ['case' => Channel::Email, 'description' => 'a message sent by email'],
        ['case' => Channel::Phone, 'description' => null],
    ]);
});

it('prefers DescribesCases over the #[Describe] attribute', function (): void {
    expect(Question::choice('department', TranslatedDepartment::class, 'Welche Abteilung?')->options())->toBe([
        ['case' => TranslatedDepartment::Billing, 'description' => 'Rechnungen und Zahlungen'],
        ['case' => TranslatedDepartment::Technical, 'description' => 'Fehler und Ausfälle'],
    ]);
});

it('rejects a class that is not a backed enum', function (string $class): void {
    new ChoiceQuestion('department', $class, 'Which department?');
})->with([
    'a pure enum' => [Unbacked::class],
    'a class' => [stdClass::class],
    'the BackedEnum interface' => [BackedEnum::class],
    'an interface extending BackedEnum' => [BackedEnumInterface::class],
    'no class at all' => ['NoSuchEnum'],
])->throws(InvalidDecision::class, 'is not a backed enum');

it('names the class that is not a backed enum', function (): void {
    new ChoiceQuestion('mood', Unbacked::class, 'How do they feel?');
})->throws(InvalidDecision::class, "Choice question 'mood' needs a backed enum (enum X: string or enum X: int); ".Unbacked::class.' is not a backed enum.');

it('rejects an enum with no cases', function (): void {
    Question::choice('nothing', NoCases::class, 'Which one?');
})->throws(InvalidDecision::class, "Choice question 'nothing' has no options: ".NoCases::class.' has no cases.');

// The label is what the model answers with; a blank one would only fail at the server.
it('rejects a case whose backing value is a blank option label', function (string $enum): void {
    Question::choice('blank', $enum, 'Which one?');
})->with([
    'empty' => [EmptyLabel::class],
    'whitespace' => [BlankLabel::class],
])->throws(InvalidDecision::class, 'is a blank option label; back it with a word the model can answer with');

it('names the case whose label is blank', function (): void {
    Question::choice('blank', EmptyLabel::class, 'Which one?');
})->throws(InvalidDecision::class, "Choice question 'blank' has a blank option label: ".EmptyLabel::class."::Empty is backed by '', which is a blank option label; back it with a word the model can answer with.");

it('rejects a blank description, from the attribute or the interface', function (string $enum, string $label): void {
    Question::choice('blank', $enum, 'Which one?');
})->with([
    [BlankDescription::class, 'blank'],
    [BlankTranslation::class, '7'],
])->throws(InvalidDecision::class, 'is blank; describe it or leave the description out');

it('names the option whose description is blank', function (): void {
    Question::choice('blank', BlankTranslation::class, 'Which one?');
})->throws(InvalidDecision::class, "Option '7' of choice question 'blank' has a description that is blank; describe it or leave the description out.");

// PHP builds the attribute only when it is read, so a malformed one surfaces as an Error
// from newInstance(), which `catch (SystemOneException)` would miss.
it('rejects a #[Describe] that PHP cannot build, naming the enum case and keeping the cause', function (string $enum, string $error, string $reason): void {
    try {
        Question::choice('broken', $enum, 'Which one?');
    } catch (InvalidDecision $e) {
        expect($e->getMessage())->toStartWith("Option 'broken' of choice question 'broken' has a #[Describe] on {$enum}::Broken that PHP cannot build: {$reason}")
            ->and($e->getPrevious())->toBeInstanceOf($error)
            ->and($e->getMessage())->toEndWith($e->getPrevious()?->getMessage() ?? 'no previous');

        return;
    }

    throw new RuntimeException('No InvalidDecision was thrown.');
})->with([
    'repeated' => [RepeatedDescribe::class, Error::class, 'Attribute "'.Describe::class.'" must not be repeated'],
    'no description' => [DescribeWithoutText::class, ArgumentCountError::class, 'Too few arguments'],
    'not a string' => [DescribeNotString::class, TypeError::class, Describe::class.'::__construct(): Argument #1 ($description) must be of type string, int given'],
    'unknown argument' => [DescribeUnknownArgument::class, Error::class, 'Unknown named parameter $text'],
]);

it('validates the key like every other question', function (string $key): void {
    Question::choice($key, Department::class, 'Which department?');
})->with(['', ' ', '0', '42'])->throws(InvalidDecision::class);

it('rejects empty instructions', function (string $instructions): void {
    Question::choice('department', Department::class, $instructions);
})->with(['', '   '])->throws(InvalidDecision::class, "Question 'department' needs instructions.");

it('compares by value: equal questions built apart are equal', function (): void {
    $a = Question::choice('department', Department::class, 'Which department?');
    $b = Question::choice('department', Department::class, 'Which department?');

    expect((array) $a)->toBe((array) $b);
});
