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
