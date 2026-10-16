<?php

declare(strict_types=1);

namespace EarthlingOllie\SystemOneClassification\Exceptions;

use RuntimeException;

/** The model server answered, but not with the shape the protocol defines. */
final class MalformedResponse extends RuntimeException implements SystemOneException
{
    private function __construct(string $message, public readonly string $body)
    {
        parent::__construct($message);
    }

    public static function because(string $reason, string $body): self
    {
        return new self($reason, $body);
    }
}
