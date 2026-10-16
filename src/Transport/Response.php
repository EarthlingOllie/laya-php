<?php

declare(strict_types=1);

namespace EarthlingOllie\SystemOneClassification\Transport;

/**
 * What a transport got back: the HTTP status and the raw body. Reading it (status
 * semantics, JSON, answers) is the codec's job, so every transport, the fake included,
 * goes through the same checks.
 */
final readonly class Response
{
    public function __construct(
        public int $status,
        public string $body,
    ) {}
}
