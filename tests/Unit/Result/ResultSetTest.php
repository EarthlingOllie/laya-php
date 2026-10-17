<?php

declare(strict_types=1);

use EarthlingOllie\SystemOneClassification\Domain\Question;
use EarthlingOllie\SystemOneClassification\Exceptions\InvalidResultSetAccess;
use EarthlingOllie\SystemOneClassification\Exceptions\SystemOneException;
use EarthlingOllie\SystemOneClassification\Result\Answer\YesNoAnswer;
use EarthlingOllie\SystemOneClassification\Result\Result;
use EarthlingOllie\SystemOneClassification\Result\ResultSet;
use EarthlingOllie\SystemOneClassification\Result\Usage;

/** A Result answering `churn` with the given probability. */
function churnResult(float $probability): Result
{
    $churn = Question::yesNo('churn', 'Does the customer threaten to cancel?');

    return new Result(['churn' => ['question' => $churn, 'answer' => new YesNoAnswer($probability)]]);
}

/** @return array{ResultSet, list<Result>} */
function threeResults(): array
{
    $results = [churnResult(0.1), churnResult(0.5), churnResult(0.9)];

    return [new ResultSet($results, new Usage(12, 0)), $results];
}

it('iterates its results in input order, keyed by position', function (): void {
    [$set, $results] = threeResults();

    expect(iterator_to_array($set))->toBe($results);
});

it('can be iterated more than once', function (): void {
    [$set, $results] = threeResults();

    iterator_to_array($set);

    expect(iterator_to_array($set))->toBe($results);
});

it('cannot be changed through its iterator', function (): void {
    [$set, $results] = threeResults();

    $iterator = $set->getIterator();
    $iterator[0] = churnResult(0.3);
    unset($iterator[1]);

    expect($set->all())->toBe($results)
        ->and(iterator_to_array($set))->toBe($results);
});

it('counts its results', function (): void {
    [$set] = threeResults();

    expect($set)->toHaveCount(3)
        ->and(count($set))->toBe(3);
});

it('reads a result by its position', function (): void {
    [$set, $results] = threeResults();

    expect($set[0])->toBe($results[0])
        ->and($set[2])->toBe($results[2])
        ->and(isset($set[0]))->toBeTrue()
        ->and(isset($set[2]))->toBeTrue()
        ->and(isset($set[3]))->toBeFalse()
        ->and(isset($set[-1]))->toBeFalse()
        ->and(isset($set['0']))->toBeFalse();
});

it('returns every result as a list', function (): void {
    [$set, $results] = threeResults();

    expect($set->all())->toBe($results);
});

it('exposes the token usage of the whole batch', function (): void {
    [$set] = threeResults();

    expect($set->usage)->toEqual(new Usage(12, 0));
});

it('refuses a position it has no result for', function (mixed $offset, string $message): void {
    [$set] = threeResults();

    expect(fn () => $set[$offset])->toThrow(InvalidResultSetAccess::class, $message);
})->with([
    'past the end' => [3, 'The result set has no result at 3; it holds 3 results (0 to 2).'],
    'negative' => [-1, 'The result set has no result at -1; it holds 3 results (0 to 2).'],
    'a numeric string' => ['0', 'The result set has no result for a string offset; it holds 3 results (0 to 2).'],
    'an object' => [new stdClass, 'The result set has no result for a stdClass offset; it holds 3 results (0 to 2).'],
    'null' => [null, 'The result set has no result for a null offset; it holds 3 results (0 to 2).'],
]);

it('says it is empty when an empty set is read', function (): void {
    expect(fn () => (new ResultSet([], null))[0])
        ->toThrow(InvalidResultSetAccess::class, 'The result set has no result at 0; it holds no results.');
});

it('refuses to be written to', function (): void {
    [$set] = threeResults();

    $set[0] = churnResult(0.3);
})->throws(InvalidResultSetAccess::class, 'A result set is read-only.');

it('refuses to be appended to', function (): void {
    [$set] = threeResults();

    $set[] = churnResult(0.3);
})->throws(InvalidResultSetAccess::class, 'A result set is read-only.');

it('refuses to have a result removed', function (): void {
    [$set, $results] = threeResults();

    try {
        unset($set[0]);
        $this->fail('Expected InvalidResultSetAccess.');
    } catch (InvalidResultSetAccess $refused) {
        expect($refused)->toBeInstanceOf(SystemOneException::class)
            ->and($refused->getMessage())->toBe('A result set is read-only.')
            ->and($set[0])->toBe($results[0]);
    }
});

it('concatenates two sets into a new one, in order, adding their usage', function (): void {
    $first = new ResultSet([churnResult(0.1), churnResult(0.2)], new Usage(10, 1));
    $second = new ResultSet([churnResult(0.3)], new Usage(5, 2));

    $both = $first->concat($second);

    expect($both->all())->toBe([...$first->all(), ...$second->all()])
        ->and($both->usage)->toEqual(new Usage(15, 3))
        ->and($first)->toHaveCount(2)
        ->and($first->usage)->toEqual(new Usage(10, 1))
        ->and($second)->toHaveCount(1);
});

it('has no usage once either concatenated set has none', function (?Usage $first, ?Usage $second): void {
    $both = (new ResultSet([churnResult(0.1)], $first))->concat(new ResultSet([churnResult(0.2)], $second));

    expect($both->usage)->toBeNull();
})->with([
    'first' => [null, new Usage(5, 0)],
    'second' => [new Usage(5, 0), null],
    'both' => [null, null],
]);

it('cannot have its usage replaced', function (): void {
    [$set] = threeResults();

    expect(fn () => $set->usage = new Usage(1, 1))->toThrow(Error::class);
});
