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
    /**
     * @param  int  $attempts  how many requests it took, retries included
     * @param  ?int  $retryAfter  the seconds a Retry-After header asked to wait, if it gave a number
     */
    public function __construct(
        public int $status,
        public string $body,
        public int $attempts = 1,
        public ?int $retryAfter = null,
    ) {}
}
