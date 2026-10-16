<?php

declare(strict_types=1);

namespace EarthlingOllie\SystemOneClassification\Exceptions;

use RuntimeException;

/** The model server refused the decision with a 4xx (auth, limits, validation). */
final class RejectedByModel extends RuntimeException implements SystemOneException
{
    private function __construct(
        string $message,
        public readonly int $status,
        public readonly ?string $detail,
    ) {
        parent::__construct($message);
    }

    public static function withStatus(int $status, ?string $detail): self
    {
        return new self("The model server rejected the decision (HTTP {$status})".($detail === null ? '.' : ": {$detail}"), $status, $detail);
    }
}
