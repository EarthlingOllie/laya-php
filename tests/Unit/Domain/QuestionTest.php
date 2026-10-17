<?php

declare(strict_types=1);

use EarthlingOllie\SystemOneClassification\Domain\Context;
use EarthlingOllie\SystemOneClassification\Domain\Question;
use EarthlingOllie\SystemOneClassification\Domain\Question\YesNoQuestion;
use EarthlingOllie\SystemOneClassification\Exceptions\InvalidDecision;
use EarthlingOllie\SystemOneClassification\Result\Answer\YesNoAnswer;

it('builds a yes/no question with its explicit key and instructions', function (): void {
    $question = Question::yesNo('churn', 'Does the customer threaten to cancel?');

    expect($question)->toBeInstanceOf(YesNoQuestion::class)
        ->and($question->key)->toBe('churn')
        ->and($question->instructions)->toBe('Does the customer threaten to cancel?')
        ->and($question->answerType())->toBe(YesNoAnswer::class);
});

it('rejects an empty key', function (string $key): void {
    Question::yesNo($key, 'Does the customer threaten to cancel?');
})->with(['empty' => '', 'blank' => '  '])->throws(InvalidDecision::class, 'A question key must not be empty.');

it('rejects a key PHP would turn into an integer array key', function (string $key): void {
    Question::yesNo($key, 'Does the customer threaten to cancel?');
})->with(['zero' => '0', 'positive' => '42', 'negative' => '-7'])->throws(InvalidDecision::class, 'is a whole number');

it('names the integer-like key and suggests a word instead', function (): void {
    Question::yesNo('0', 'Does the customer threaten to cancel?');
})->throws(InvalidDecision::class, "Question key '0' is a whole number, which PHP stores as an integer array key; use a name such as 'q0'.");

it('accepts keys that only look numeric', function (string $key): void {
    expect(Question::yesNo($key, 'Does the customer threaten to cancel?')->key)->toBe($key);
})->with(['leading zero' => '01', 'decimal' => '1.5', 'padded' => ' 1', 'prefixed' => 'q0', 'minus zero' => '-0', 'past PHP_INT_MAX' => '99999999999999999999']);

it('rejects empty instructions', function (string $instructions): void {
    Question::yesNo('churn', $instructions);
})->with(['empty' => '', 'blank' => " \n"])->throws(InvalidDecision::class, "Question 'churn' needs instructions.");

it('holds the context text as given', function (): void {
    expect(Context::text('  We were billed twice.  ')->state)->toBe('  We were billed twice.  ');
});

it('rejects empty or blank context text, which the server would answer with a meaningless 0.5', function (string $text): void {
    Context::text($text);
})->with(['empty' => '', 'blank' => " \t\n"])->throws(InvalidDecision::class, 'The context text must not be empty.');
