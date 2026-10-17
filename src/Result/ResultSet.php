<?php

declare(strict_types=1);

namespace EarthlingOllie\SystemOneClassification\Result;

use ArrayAccess;
use ArrayIterator;
use Countable;
use EarthlingOllie\SystemOneClassification\Exceptions\InvalidResultSetAccess;
use IteratorAggregate;

use function count;
use function is_int;

/**
 * The results of decideMany(): one Result per context, in the order the contexts were given.
 *
 * Read it like a list (`$results[0]`, `count($results)`, `foreach`); writing to it throws.
 * Each Result is read with the question handles, exactly as a decide() Result is.
 *
 * @implements ArrayAccess<int, Result>
 * @implements IteratorAggregate<int, Result>
 */
final readonly class ResultSet implements ArrayAccess, Countable, IteratorAggregate
{
    /**
     * @param  list<Result>  $results  one per context, in input order
     * @param  Usage|null  $usage  tokens the whole batch used, summed over every request it was
     *                             split into; null when the server did not report it for all of them
     */
    public function __construct(
        private array $results,
        public ?Usage $usage,
    ) {}

    /**
     * A new set holding these results followed by the other set's, with both usages added.
     * The usage is null when either set has none, rather than a sum that leaves part out.
     */
    public function concat(self $other): self
    {
        $usage = $this->usage !== null && $other->usage !== null ? $this->usage->plus($other->usage) : null;

        return new self([...$this->results, ...$other->results], $usage);
    }

    /**
     * @return list<Result>
     */
    public function all(): array
    {
        return $this->results;
    }

    public function count(): int
    {
        return count($this->results);
    }

    /**
     * An iterator over a copy of the results: writing through it cannot change the set.
     *
     * Not a generator: Xdebug's path coverage (`composer test:coverage`) segfaults on one.
     *
     * @return ArrayIterator<int, Result>
     */
    public function getIterator(): ArrayIterator
    {
        return new ArrayIterator($this->results);
    }

    /**
     * @param  mixed  $offset  anything PHP passes for `isset($set[...])`; only a position matches
     */
    public function offsetExists(mixed $offset): bool
    {
        return is_int($offset) && isset($this->results[$offset]);
    }

    /**
     * @param  mixed  $offset  anything PHP passes for `$set[...]`; only a held position reads
     *
     * @throws InvalidResultSetAccess when there is no result at that position
     */
    public function offsetGet(mixed $offset): Result
    {
        if (! is_int($offset) || ! isset($this->results[$offset])) {
            throw InvalidResultSetAccess::noResultAt($offset, count($this->results));
        }

        return $this->results[$offset];
    }

    /** @throws InvalidResultSetAccess always: a result set is read-only */
    public function offsetSet(mixed $offset, mixed $value): never
    {
        throw InvalidResultSetAccess::readOnly();
    }

    /** @throws InvalidResultSetAccess always: a result set is read-only */
    public function offsetUnset(mixed $offset): never
    {
        throw InvalidResultSetAccess::readOnly();
    }
}
