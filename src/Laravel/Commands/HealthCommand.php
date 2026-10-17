<?php

declare(strict_types=1);

namespace EarthlingOllie\SystemOneClassification\Laravel\Commands;

use EarthlingOllie\SystemOneClassification\Exceptions\HostUnavailable;
use EarthlingOllie\SystemOneClassification\Exceptions\InvalidConfiguration;
use EarthlingOllie\SystemOneClassification\Exceptions\MalformedResponse;
use EarthlingOllie\SystemOneClassification\Exceptions\RejectedByModel;
use EarthlingOllie\SystemOneClassification\Laravel\SystemOneManager;
use EarthlingOllie\SystemOneClassification\Protocol\SystemOneCodec;
use EarthlingOllie\SystemOneClassification\Result\Health;
use Illuminate\Console\Command;

use function array_map;
use function implode;
use function is_string;

/**
 * `php artisan system-one:health [--connection=name]`: asks the server how it is.
 *
 * Exit codes, for deploy checks: 0 when the server answers `status: ok`, 1 when it cannot be
 * reached or is not healthy (a 5xx, a refusal, an unexpected answer, or another status), and
 * 2 when the connection itself is not configured. Everything printed about the connection
 * goes through Transport\Redactor, so no URL credential or API key reaches the terminal.
 *
 * It always probes the real server (SystemOneManager), also under SystemOne::fake(): a health
 * check of a fake would say nothing about the host.
 */
final class HealthCommand extends Command
{
    /** @var string */
    protected $signature = 'system-one:health
        {--connection= : The connection to check (default: system-one.default)}';

    /** @var string */
    protected $description = "Check the System One server's health and its loaded checkpoints";

    public function handle(SystemOneManager $manager): int
    {
        $option = $this->option('connection');

        try {
            $name = is_string($option) ? $option : $manager->defaultConnection();
            $this->line("Connection: {$name} ({$manager->displayUrl($name)})");
            $health = $manager->connection($name)->health();
        } catch (InvalidConfiguration $invalid) {
            $this->error($invalid->getMessage());

            return self::INVALID;
        } catch (HostUnavailable $unavailable) {
            $this->error(($unavailable->status === null ? 'Unreachable: ' : 'Unhealthy: ').$unavailable->getMessage());

            return self::FAILURE;
        } catch (RejectedByModel $rejected) {
            $detail = $rejected->detail === null ? '' : ": {$rejected->detail}";
            $this->error('Unhealthy: GET '.SystemOneCodec::HEALTH_PATH." was refused with HTTP {$rejected->status}{$detail} (is this a laya-serve server?)");

            return self::FAILURE;
        } catch (MalformedResponse $malformed) {
            $this->error("Unhealthy: {$malformed->getMessage()}");

            return self::FAILURE;
        }

        $this->report($health);

        if (! $health->isHealthy()) {
            $this->error("Unhealthy: the server reports status \"{$health->status}\", not \"ok\".");

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function report(Health $health): void
    {
        $this->line("Status: {$health->status}");
        $this->line('Checkpoints: '.match ($health->loaded) {
            null => 'not reported (laya-serve lists them only to requests with its API key: check SYSTEM_ONE_API_KEY)',
            [] => 'none loaded yet (laya-serve loads them at start, or on the first decision when LAYA_PRELOAD=0)',
            default => implode(', ', array_map(fn (string $name): string => self::checkpoint($name, $health->revisions[$name] ?? null), $health->loaded)),
        });

        if ($health->device !== null) {
            $this->line("Device: {$health->device}");
        }
    }

    private static function checkpoint(string $name, ?string $revision): string
    {
        return $revision === null ? $name : "{$name} (revision {$revision})";
    }
}
