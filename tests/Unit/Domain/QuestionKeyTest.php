<?php

declare(strict_types=1);

use EarthlingOllie\SystemOneClassification\Domain\Question\QuestionKey;
use EarthlingOllie\SystemOneClassification\Exceptions\InvalidDecision;

/*
 * The key rules every question type shares (#4 choice and #5 score reuse QuestionKey).
 */

it('returns a valid key unchanged', function (string $key): void {
    expect(QuestionKey::validate($key))->toBe($key);
})->with(['word' => 'churn', 'leading zero' => '01', 'decimal' => '1.5', 'padded' => ' 1', 'minus zero' => '-0']);

it('rejects an empty or blank key', function (string $key): void {
    QuestionKey::validate($key);
})->with(['empty' => '', 'blank' => " \t"])->throws(InvalidDecision::class, 'A question key must not be empty.');

it('rejects a key PHP would store as an integer array key', function (string $key): void {
    QuestionKey::validate($key);
})->with(['zero' => '0', 'positive' => '42', 'negative' => '-7'])->throws(InvalidDecision::class, 'is a whole number');
