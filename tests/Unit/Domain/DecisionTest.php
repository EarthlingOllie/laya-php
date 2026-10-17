<?php

declare(strict_types=1);

use EarthlingOllie\SystemOneClassification\Domain\Context;
use EarthlingOllie\SystemOneClassification\Domain\Decision;
use EarthlingOllie\SystemOneClassification\Domain\Question;
use EarthlingOllie\SystemOneClassification\Exceptions\InvalidDecision;

it('holds the context it is about and asks nothing yet', function (): void {
    $context = Context::text('We were billed twice.');

    $decision = Decision::about($context);

    expect($decision->context)->toBe($context)
        ->and($decision->questions())->toBe([]);
});

it('returns a new decision from ask() and leaves the original unchanged', function (): void {
    $churn = Question::yesNo('churn', 'Does the customer threaten to cancel?');
    $original = Decision::about(Context::text('We were billed twice.'));

    $asked = $original->ask($churn);

    expect($asked)->not->toBe($original)
        ->and($asked->context)->toBe($original->context)
        ->and($asked->questions())->toBe([$churn])
        ->and($original->questions())->toBe([]);
});

it('keeps the questions of every step in the order they were asked', function (): void {
    $churn = Question::yesNo('churn', 'Does the customer threaten to cancel?');
    $refund = Question::yesNo('refund', 'Does the customer ask for a refund?');
    $angry = Question::yesNo('angry', 'Is the customer angry?');
    $first = Decision::about(Context::text('We were billed twice.'))->ask($churn);

    $second = $first->ask($refund, $angry);

    expect($second->questions())->toBe([$churn, $refund, $angry])
        ->and($first->questions())->toBe([$churn]);
});

it('rejects a second question with a key it already asks', function (): void {
    $decision = Decision::about(Context::text('We were billed twice.'))
        ->ask(Question::yesNo('churn', 'Does the customer threaten to cancel?'));

    $decision->ask(Question::yesNo('churn', 'Will they leave?'));
})->throws(InvalidDecision::class, "The decision already asks a question with the key 'churn'.");

it('rejects two questions with the same key in one ask() call', function (): void {
    Decision::about(Context::text('We were billed twice.'))->ask(
        Question::yesNo('churn', 'Does the customer threaten to cancel?'),
        Question::yesNo('churn', 'Will they leave?'),
    );
})->throws(InvalidDecision::class, "The decision already asks a question with the key 'churn'.");

it('leaves the decision unchanged when a duplicate key is rejected', function (): void {
    $churn = Question::yesNo('churn', 'Does the customer threaten to cancel?');
    $decision = Decision::about(Context::text('We were billed twice.'))->ask($churn);

    try {
        $decision->ask(Question::yesNo('refund', 'A refund?'), Question::yesNo('churn', 'Again?'));
    } catch (InvalidDecision) {
    }

    expect($decision->questions())->toBe([$churn]);
});

it('cannot be changed after construction', function (): void {
    $decision = Decision::about(Context::text('We were billed twice.'));

    expect(fn () => $decision->context = Context::text('other'))->toThrow(Error::class);
});

it('sends no request controls until a builder sets one', function (): void {
    $decision = Decision::about(Context::text('We were billed twice.'));

    expect($decision->minConfidence)->toBeNull()
        ->and($decision->language)->toBeNull()
        ->and($decision->languageHint)->toBeNull()
        ->and($decision->maxLength)->toBeNull()
        ->and($decision->headMaxLength)->toBeNull();
});

it('returns a new decision from every request-control builder and leaves the original unchanged', function (string $builder, float|int|string $value, string $property): void {
    $churn = Question::yesNo('churn', 'Does the customer threaten to cancel?');
    $original = Decision::about(Context::text('We were billed twice.'))->ask($churn);

    $changed = $original->{$builder}($value);

    expect($changed)->not->toBe($original)
        ->and($changed->{$property})->toBe($value)
        ->and($original->{$property})->toBeNull()
        ->and($changed->context)->toBe($original->context)
        ->and($changed->questions())->toBe([$churn]);
})->with([
    'min confidence' => ['withMinConfidence', 0.6, 'minConfidence'],
    'language' => ['withLanguage', 'de', 'language'],
    'language hint' => ['withLanguageHint', 'en-US', 'languageHint'],
    'max length' => ['withMaxLength', 256, 'maxLength'],
    'head max length' => ['withHeadMaxLength', 64, 'headMaxLength'],
]);

it('keeps every request control through later builders and ask()', function (): void {
    $churn = Question::yesNo('churn', 'Does the customer threaten to cancel?');

    $decision = Decision::about(Context::text('We were billed twice.'))
        ->withMinConfidence(0.7)
        ->withLanguage('en')
        ->withLanguageHint('en_GB')
        ->withMaxLength(512)
        ->withHeadMaxLength(32)
        ->ask($churn)
        ->withMinConfidence(0.8);

    expect($decision->minConfidence)->toBe(0.8)
        ->and($decision->language)->toBe('en')
        ->and($decision->languageHint)->toBe('en_GB')
        ->and($decision->maxLength)->toBe(512)
        ->and($decision->headMaxLength)->toBe(32)
        ->and($decision->questions())->toBe([$churn]);
});

it('keeps the other controls when one builder changes its own', function (string $builder, float|int|string $value): void {
    $base = Decision::about(Context::text('x'))
        ->withMinConfidence(0.7)
        ->withLanguage('en')
        ->withLanguageHint('en_GB')
        ->withMaxLength(512)
        ->withHeadMaxLength(32);

    $changed = $base->{$builder}($value);
    $controls = static fn (Decision $d): array => [$d->minConfidence, $d->language, $d->languageHint, $d->maxLength, $d->headMaxLength];
    $differences = array_diff_assoc(array_map(strval(...), $controls($changed)), array_map(strval(...), $controls($base)));

    expect($differences)->toHaveCount(1)->and(array_values($differences))->toBe([(string) $value]);
})->with([
    ['withMinConfidence', 0.9],
    ['withLanguage', 'de'],
    ['withLanguageHint', 'fr'],
    ['withMaxLength', 128],
    ['withHeadMaxLength', 16],
]);

it('accepts a minimum confidence at either end of [0, 1]', function (float $confidence): void {
    expect(Decision::about(Context::text('x'))->withMinConfidence($confidence)->minConfidence)->toBe($confidence);
})->with([0.0, 1.0]);

it('rejects a minimum confidence outside [0, 1]', function (float $confidence, string $shown): void {
    expect(fn () => Decision::about(Context::text('x'))->withMinConfidence($confidence))
        ->toThrow(InvalidDecision::class, "The minimum confidence must be between 0 and 1, got {$shown}.");
})->with([
    'below' => [-0.01, '-0.01'],
    'above' => [1.5, '1.5'],
    'just above' => [1.0000001, '1.0000001'],
    'not a number' => [NAN, 'NAN'],
    'infinite' => [INF, 'INF'],
    'negative infinite' => [-INF, '-INF'],
]);

it('rejects a blank language or language hint', function (string $builder, string $code, string $message): void {
    expect(fn () => Decision::about(Context::text('x'))->{$builder}($code))->toThrow(InvalidDecision::class, $message);
})->with([
    'empty language' => ['withLanguage', '', 'The language code must not be empty.'],
    'blank language' => ['withLanguage', '  ', 'The language code must not be empty.'],
    'empty hint' => ['withLanguageHint', '', 'The language hint must not be empty.'],
    'blank hint' => ['withLanguageHint', "\t", 'The language hint must not be empty.'],
]);

it('trims whitespace around a language or language hint, as read from config or a query string', function (string $builder, string $property): void {
    expect(Decision::about(Context::text('x'))->{$builder}(" de\n")->{$property})->toBe('de');
})->with([
    'language' => ['withLanguage', 'language'],
    'hint' => ['withLanguageHint', 'languageHint'],
]);

it('rejects a length that is not a positive whole number', function (string $builder, int $length, string $message): void {
    expect(fn () => Decision::about(Context::text('x'))->{$builder}($length))->toThrow(InvalidDecision::class, $message);
})->with([
    'zero max length' => ['withMaxLength', 0, 'The max length must be a positive number of tokens, got 0.'],
    'negative max length' => ['withMaxLength', -5, 'The max length must be a positive number of tokens, got -5.'],
    'zero head max length' => ['withHeadMaxLength', 0, 'The head max length must be a positive number of tokens, got 0.'],
    'negative head max length' => ['withHeadMaxLength', -1, 'The head max length must be a positive number of tokens, got -1.'],
]);

it('accepts a length of one token', function (): void {
    $decision = Decision::about(Context::text('x'))->withMaxLength(1)->withHeadMaxLength(1);

    expect($decision->maxLength)->toBe(1)->and($decision->headMaxLength)->toBe(1);
});

it('leaves the decision unchanged when a builder rejects its value', function (): void {
    $decision = Decision::about(Context::text('x'))->withMinConfidence(0.5);

    try {
        $decision->withMinConfidence(2.0);
    } catch (InvalidDecision) {
    }

    expect($decision->minConfidence)->toBe(0.5);
});
