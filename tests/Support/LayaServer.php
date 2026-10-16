<?php

declare(strict_types=1);

namespace EarthlingOllie\SystemOneClassification\Tests\Support;

use PHPUnit\Framework\Assert;

use function array_filter;
use function fclose;
use function fsockopen;
use function getenv;
use function in_array;
use function is_string;
use function parse_url;
use function restore_error_handler;
use function set_error_handler;
use function strtolower;
use function trim;

/**
 * The Laya server the Integration suite talks to (SYSTEM_ONE_URL, default
 * http://localhost:8000).
 *
 * guard() runs before every Integration test. Without a reachable server the test is
 * skipped locally, but fails when CI is set or SYSTEM_ONE_REQUIRE_SERVER=1, so a broken
 * container can never turn the integration gate green.
 */
final readonly class LayaServer
{
    public const string DEFAULT_URL = 'http://localhost:8000';

    public string $url;

    /**
     * @param  array<string, string>  $environment
     */
    public function __construct(?string $url, private array $environment)
    {
        $this->url = $url ?? self::DEFAULT_URL;
    }

    /**
     * @param  array<array-key, mixed>|null  $environment  defaults to getenv()
     */
    public static function fromEnvironment(?array $environment = null): self
    {
        $environment = array_filter($environment ?? getenv(), is_string(...));
        $environment = array_filter($environment, is_string(...), ARRAY_FILTER_USE_KEY);
        $url = $environment['SYSTEM_ONE_URL'] ?? '';

        return new self($url === '' ? null : $url, $environment);
    }

    public function isRequired(): bool
    {
        return self::isTruthy($this->environment['CI'] ?? '')
            || self::isTruthy($this->environment['SYSTEM_ONE_REQUIRE_SERVER'] ?? '');
    }

    /** Whether something accepts TCP connections at the URL's host and port (1s timeout). */
    public function isReachable(): bool
    {
        $parts = parse_url($this->url);

        if ($parts === false || ! isset($parts['host'])) {
            return false;
        }

        $host = $parts['host'];
        $port = $parts['port'] ?? (($parts['scheme'] ?? 'http') === 'https' ? 443 : 80);

        // A refused connection is the expected answer when no server runs, not a warning.
        set_error_handler(static fn (): bool => true);

        try {
            $socket = fsockopen($host, $port, $errorCode, $errorMessage, 1.0);
        } finally {
            restore_error_handler();
        }

        if ($socket === false) {
            return false;
        }

        fclose($socket);

        return true;
    }

    /**
     * Skips (locally) or fails (when required) the current test if the server is down.
     *
     * @param  bool|null  $reachable  a cached isReachable() result, to probe once per run
     */
    public function guard(?bool $reachable = null): void
    {
        if ($reachable ?? $this->isReachable()) {
            return;
        }

        $message = "No Laya server at {$this->url}. Start one (`composer laya:up`) or point SYSTEM_ONE_URL at it.";

        if ($this->isRequired()) {
            Assert::fail($message.' The server is required here (CI or SYSTEM_ONE_REQUIRE_SERVER).');
        }

        Assert::markTestSkipped($message);
    }

    private static function isTruthy(string $value): bool
    {
        return ! in_array(strtolower(trim($value)), ['', '0', 'false', 'no', 'off'], true);
    }
}
