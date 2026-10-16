<?php

declare(strict_types=1);

/*
 * Mutation gate: runs Infection with the thresholds from infection.json5.
 *
 * Usage: php tools/mutate.php [infection options...]
 *
 * Infection aborts ("No source code was executed") when src/ has no executable line at
 * all, which is the case while the package is only a skeleton. With zero executable
 * lines there is nothing to mutate, so this gate passes and says so. As soon as src/
 * contains a single executable line, Infection runs and its MSI thresholds apply.
 */

use SebastianBergmann\CodeCoverage\StaticAnalysis\ParsingFileAnalyser;

require dirname(__DIR__).'/vendor/autoload.php';

/**
 * @param  list<string>  $arguments
 */
function mutate(array $arguments): int
{
    $root = dirname(__DIR__);
    $analyser = new ParsingFileAnalyser(true, false);
    $executableLines = 0;

    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/src', FilesystemIterator::SKIP_DOTS));

    foreach ($files as $file) {
        if ($file instanceof SplFileInfo && $file->getExtension() === 'php') {
            $executableLines += count($analyser->executableLinesIn($file->getPathname()));
        }
    }

    if ($executableLines === 0) {
        echo "src/ has no executable code yet, so there is nothing to mutate. Infection runs as soon as it does.\n";

        return 0;
    }

    $command = array_merge([PHP_BINARY, $root.'/vendor/bin/infection'], array_slice($arguments, 1));
    $process = proc_open($command, [STDIN, STDOUT, STDERR], $pipes, $root);

    if ($process === false) {
        fwrite(STDERR, "Could not start Infection.\n");

        return 2;
    }

    return proc_close($process);
}

$argv = $_SERVER['argv'] ?? [];
$arguments = is_array($argv) ? array_values(array_filter($argv, is_string(...))) : [];

exit(mutate($arguments));
