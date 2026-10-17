<?php

declare(strict_types=1);

namespace EarthlingOllie\SystemOneClassification\Result;

/**
 * Tokens a request used, as the server counted them (`usage` / `total_usage` on the wire).
 *
 * A batch split into several requests adds the usage of each one with plus().
 */
final readonly class Usage
{
    public function __construct(
        public int $inputTokens,
        public int $outputTokens,
    ) {}

    public function plus(self $other): self
    {
        return new self($this->inputTokens + $other->inputTokens, $this->outputTokens + $other->outputTokens);
    }
}
