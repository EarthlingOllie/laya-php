#!/usr/bin/env php
<?php

declare(strict_types=1);

/*
 * Runs Pest on behalf of Infection's PHPUnit adapter (see infection.json5).
 *
 * Infection no longer ships a Pest adapter, and Pest's CLI is PHPUnit-compatible, so the
 * PHPUnit adapter can drive Pest, except for three details that this script bridges:
 *
 * 1. Infection decides whether a mutant was killed from PHPUnit's summary line
 *    ("OK (...)" vs "FAILURES!"), which Pest does not print. After Pest exits, the
 *    matching PHPUnit summary is printed, derived from Pest's exit code and item 3.
 * 2. Infection matches the tests in the coverage report (named `P\Tests\...` by Pest)
 *    against the JUnit report, where Pest writes `class="Tests\..."` and
 *    `file="tests/...Test.php::it does something"`. Those two attributes are rewritten
 *    to what PHPUnit itself would write (the generated class name and the absolute
 *    test file path).
 * 3. Pest exits 0 when a test is risky (performs no assertions, say) even with
 *    failOnRisky="true" (phpunit.xml.dist) or --fail-on-risky: its Result::exitCode()
 *    returns early for a run without failures. A mutant that removes a test's only
 *    assertion would then read as "OK" and escape. So Pest also writes PHPUnit's event
 *    log, and a run with a "Test Considered Risky" event fails (exit 1, "FAILURES!").
 *
 * Otherwise Pest's exit code is passed through unchanged.
 */

/**
 * @param  list<string>  $arguments
 */
function runPestForInfection(array $arguments, string $root): int
{
    // Infection reads the PHPUnit version to decide how to write its configuration;
    // Pest would answer with its own version number.
    if ($arguments === ['--version']) {
        $process = proc_open([PHP_BINARY, $root.'/vendor/bin/phpunit', '--version'], [STDIN, STDOUT, STDERR], $pipes);

        return $process === false ? 2 : proc_close($process);
    }

    $events = (string) tempnam(sys_get_temp_dir(), 'pest-events');
    $process = proc_open([PHP_BINARY, $root.'/vendor/bin/pest', ...$arguments, '--log-events-text='.$events], [STDIN, STDOUT, STDERR], $pipes);
    $exitCode = $process === false ? 2 : proc_close($process);
    $risky = riskyTests($events);
    if (is_file($events)) {
        unlink($events);
    }

    if ($exitCode === 0 && $risky !== []) {
        $exitCode = 1;
        $count = count($risky) === 1 ? '1 test was' : count($risky).' tests were';
        echo "\nFAILURES! (Pest exited with 0, but {$count} risky: ".implode(', ', $risky).")\n";
    } else {
        echo $exitCode === 0 ? "\nOK (Pest exited with 0)\n" : "\nFAILURES! (Pest exited with {$exitCode})\n";
    }

    foreach ($arguments as $argument) {
        if (str_starts_with($argument, '--log-junit=')) {
            normaliseJunit(substr($argument, strlen('--log-junit=')), $root);
        }
    }

    return $exitCode;
}

/**
 * The tests PHPUnit's event log (--log-events-text) records as risky, by name.
 *
 * @return list<string>
 */
function riskyTests(string $events): array
{
    $log = is_file($events) ? (string) file_get_contents($events) : '';
    preg_match_all('/Test Considered Risky \((.+?)\)$/m', $log, $matches);

    return array_values(array_unique($matches[1]));
}

function normaliseJunit(string $path, string $root): void
{
    if (! is_file($path)) {
        return;
    }

    $document = new DOMDocument;

    // A report Pest could not finish is left as it is, for Infection to report.
    $previous = libxml_use_internal_errors(true);
    $loaded = $document->load($path);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);

    if (! $loaded) {
        return;
    }

    foreach ($document->getElementsByTagName('testcase') as $testCase) {
        prefixClassName($testCase, 'class');
        absoluteFile($testCase, $root);
    }

    // The suite of one test file carries a `file` attribute and is named after its class;
    // PHPUnit names it by the class too, and Infection looks tests up by that name first.
    foreach ($document->getElementsByTagName('testsuite') as $testSuite) {
        if ($testSuite->hasAttribute('file')) {
            prefixClassName($testSuite, 'name');
            absoluteFile($testSuite, $root);
        }
    }

    $document->save($path);
}

/** Pest's generated test classes live under `P\`; its JUnit report leaves that out. */
function prefixClassName(DOMElement $element, string $attribute): void
{
    $class = $element->getAttribute($attribute);

    if ($class !== '' && ! str_starts_with($class, 'P\\')) {
        $element->setAttribute($attribute, 'P\\'.$class);
    }
}

/** The absolute test file path, without Pest's `::test name` suffix. */
function absoluteFile(DOMElement $element, string $root): void
{
    $file = explode('::', $element->getAttribute('file'), 2)[0];

    if ($file !== '' && ! str_starts_with($file, '/')) {
        $element->setAttribute('file', $root.'/'.$file);
    }
}

// Run only when executed, not when the functions are loaded by the tests.
$script = $_SERVER['SCRIPT_FILENAME'] ?? null;

if (is_string($script) && realpath($script) === __FILE__) {
    $argv = $_SERVER['argv'] ?? [];
    $arguments = is_array($argv) ? array_values(array_filter(array_slice($argv, 1), is_string(...))) : [];

    exit(runPestForInfection($arguments, dirname(__DIR__)));
}
