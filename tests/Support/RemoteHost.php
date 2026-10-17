<?php

declare(strict_types=1);

namespace EarthlingOllie\SystemOneClassification\Tests\Support;

use PHPUnit\Framework\Assert;

use function array_filter;
use function array_map;
use function explode;
use function getenv;
use function in_array;
use function is_string;
use function str_replace;
use function strtoupper;
use function trim;

/**
 * A remote host the Smoke suite (tests/Smoke) checks: `laya-hosted` or `jev`, reached with its
 * real key from SYSTEM_ONE_LAYA_HOSTED_API_KEY or SYSTEM_ONE_JEV_API_KEY.
 *
 * guard() runs before each smoke test of that connection. Without its key the test is skipped,
 * so the suite costs nothing and sends nothing by default; but a connection named in
 * SYSTEM_ONE_REQUIRE_SMOKE (a comma-separated list, as the manually run smoke workflow sets it)
 * fails instead, so a run meant to reach a host can never pass by skipping.
 */
final readonly class RemoteHost
{
    /**
     * @param  array<string, string>  $environment
     */
    public function __construct(public string $connection, private array $environment) {}

    /**
     * @param  array<array-key, mixed>|null  $environment  defaults to getenv()
     */
    public static function fromEnvironment(string $connection, ?array $environment = null): self
    {
        $environment = array_filter($environment ?? getenv(), is_string(...));

        return new self($connection, array_filter($environment, is_string(...), ARRAY_FILTER_USE_KEY));
    }

    /** SYSTEM_ONE_LAYA_HOSTED_API_KEY for 'laya-hosted', SYSTEM_ONE_JEV_API_KEY for 'jev'. */
    public function keyVariable(): string
    {
        return 'SYSTEM_ONE_'.strtoupper(str_replace('-', '_', $this->connection)).'_API_KEY';
    }

    public function hasKey(): bool
    {
        return trim($this->environment[$this->keyVariable()] ?? '') !== '';
    }

    public function isRequired(): bool
    {
        $required = array_map(trim(...), explode(',', $this->environment['SYSTEM_ONE_REQUIRE_SMOKE'] ?? ''));

        return in_array($this->connection, $required, true);
    }

    /** Skips the current test without a key, or fails it when this host is required. */
    public function guard(): void
    {
        if ($this->hasKey()) {
            return;
        }

        $message = "No key for '{$this->connection}': set {$this->keyVariable()} to run its smoke tests.";

        if ($this->isRequired()) {
            Assert::fail($message.' They are required here (SYSTEM_ONE_REQUIRE_SMOKE).');
        }

        Assert::markTestSkipped($message);
    }
}
