<?php

declare(strict_types=1);

/*
 * Coverage gate: fails unless 100% of the executable lines AND branches of src/ are
 * covered.
 *
 * Usage: php tools/check-coverage.php build/coverage/coverage.cov
 *
 * The input is PHPUnit's serialised coverage (`--coverage-php`). Branch data only exists
 * when the tests ran under Xdebug with `--path-coverage` (PCOV records lines only), so
 * this script refuses coverage that was collected without branch data instead of
 * silently treating it as passing.
 */

use SebastianBergmann\CodeCoverage\CodeCoverage;
use SebastianBergmann\CodeCoverage\Node\File;

require dirname(__DIR__).'/vendor/autoload.php';

/**
 * @param  list<string>  $argv
 */
function checkCoverage(array $argv): int
{
    $path = $argv[1] ?? '';

    if ($path === '' || ! is_file($path)) {
        fwrite(STDERR, "Coverage file not found: '{$path}'. Run `composer test:coverage` under Xdebug: no coverage is written when no driver supports --path-coverage (PCOV does not).\n");

        return 2;
    }

    $coverage = require $path;

    if (! $coverage instanceof CodeCoverage) {
        fwrite(STDERR, "{$path} does not contain PHPUnit coverage data.\n");

        return 2;
    }

    if (! $coverage->collectsBranchAndPathCoverage()) {
        fwrite(STDERR, "{$path} has no branch data. Collect it with Xdebug (XDEBUG_MODE=coverage) and --path-coverage; PCOV cannot measure branches.\n");

        return 2;
    }

    $report = $coverage->getReport();
    $failures = [];

    foreach ($report as $node) {
        if (! $node instanceof File) {
            continue;
        }

        $lines = [$node->numberOfExecutedLines(), $node->numberOfExecutableLines()];
        $branches = [$node->numberOfExecutedBranches(), $node->numberOfExecutableBranches()];

        if ($lines[0] < $lines[1] || $branches[0] < $branches[1]) {
            $failures[] = sprintf(
                '  %s: lines %d/%d, branches %d/%d',
                $node->pathAsString(),
                $lines[0],
                $lines[1],
                $branches[0],
                $branches[1],
            );
        }
    }

    printf(
        "Coverage of src/: lines %d/%d, branches %d/%d, paths %d/%d (paths are informational).\n",
        $report->numberOfExecutedLines(),
        $report->numberOfExecutableLines(),
        $report->numberOfExecutedBranches(),
        $report->numberOfExecutableBranches(),
        $report->numberOfExecutedPaths(),
        $report->numberOfExecutablePaths(),
    );

    if ($failures !== []) {
        fwrite(STDERR, "Coverage below 100% in:\n".implode("\n", $failures)."\n");

        return 1;
    }

    echo $report->numberOfExecutableLines() === 0
        ? "OK: src/ has no executable lines or branches yet.\n"
        : "OK: 100% of lines and branches are covered.\n";

    return 0;
}

$argv = $_SERVER['argv'] ?? [];
$arguments = is_array($argv) ? array_values(array_filter($argv, is_string(...))) : [];

exit(checkCoverage($arguments));
