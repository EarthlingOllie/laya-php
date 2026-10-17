<?php

declare(strict_types=1);

namespace EarthlingOllie\SystemOneClassification\Exceptions;

use RuntimeException;
use Throwable;

/** The model server could not be reached, or answered with a 5xx. */
final class HostUnavailable extends RuntimeException implements SystemOneException
{
    private function __construct(
        string $message,
        public readonly ?int $status = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, previous: $previous);
    }

    /**
     * The transport could not connect. Both arguments must already be redacted
     * (Transport\Redactor): they are shown as given.
     *
     * The HTTP client's exception is deliberately not attached as the previous exception. Its
     * message can hold the request URL with its query string (Guzzle 7 masks only the userinfo),
     * `(string) $e` and Laravel's log formatter print the whole previous chain, and Guzzle's
     * exception carries the request, Authorization header included. Its useful part, the
     * connection error, is in $reason: that of the last of $attempts.
     */
    public static function unreachable(string $url, string $reason, int $attempts): self
    {
        $tries = $attempts > 1 ? " after {$attempts} attempts" : '';

        return new self("The model server at {$url} could not be reached{$tries}: {$reason}");
    }

    /** @param  int  $attempts  how many requests got a 5xx, retries included */
    public static function serverError(int $status, ?string $detail, int $attempts): self
    {
        $tries = $attempts > 1 ? " after {$attempts} attempts" : '';

        return new self("The model server is unavailable (HTTP {$status}{$tries})".($detail === null ? '.' : ": {$detail}"), $status);
    }
}
