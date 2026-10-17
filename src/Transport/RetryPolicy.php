<?php

declare(strict_types=1);

namespace EarthlingOllie\SystemOneClassification\Transport;

use function min;

/**
 * How often HttpTransport tries again when the host cannot be reached or answers with a 5xx,
 * and how long it waits first: the delay doubles before each further retry (exponential
 * backoff), up to MAX_DELAY_MILLISECONDS. A 4xx is never retried: the same request would be
 * refused again.
 */
final readonly class RetryPolicy
{
    /** The longest wait before any one retry, however many come before it. */
    public const int MAX_DELAY_MILLISECONDS = 30_000;

    /**
     * @param  non-negative-int  $retries  attempts after the first one
     * @param  non-negative-int  $delayMilliseconds  the wait before the first retry
     */
    public function __construct(
        public int $retries = 0,
        public int $delayMilliseconds = 0,
    ) {}

    /**
     * The wait before each retry, in milliseconds, first retry first.
     *
     * @return list<int<0, max>>
     */
    public function delays(): array
    {
        $delays = [];
        $delay = min($this->delayMilliseconds, self::MAX_DELAY_MILLISECONDS);

        for ($retry = 0; $retry < $this->retries; $retry++) {
            $delays[] = $delay;
            $delay = min($delay * 2, self::MAX_DELAY_MILLISECONDS);
        }

        return $delays;
    }
}
