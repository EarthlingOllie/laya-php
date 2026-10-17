<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

/*
 * tools/pest-for-infection.php lets Infection's PHPUnit adapter drive Pest. Infection
 * relies on three things from it: the PHPUnit version, PHPUnit's summary line, and a
 * JUnit report whose test names match the coverage report.
 */

require_once dirname(__DIR__, 3).'/tools/pest-for-infection.php';

function bridge(string ...$arguments): Process
{
    $process = new Process([PHP_BINARY, dirname(__DIR__, 3).'/tools/pest-for-infection.php', ...$arguments]);
    $process->run();

    return $process;
}

function junitFixture(string $xml): string
{
    $path = tempnam(sys_get_temp_dir(), 'junit');
    assert(is_string($path));
    file_put_contents($path, $xml);

    return $path;
}

it('reports the PHPUnit version, which Infection uses to build its configuration', function (): void {
    $phpunit = new Process([PHP_BINARY, dirname(__DIR__, 3).'/vendor/bin/phpunit', '--version']);
    $phpunit->mustRun();

    $process = bridge('--version');

    expect($process->getExitCode())->toBe(0)
        ->and(trim($process->getOutput()))->toBe(trim($phpunit->getOutput()));
});

it('rewrites Pest JUnit test cases to the names and paths PHPUnit would report', function (): void {
    $path = junitFixture(<<<'XML'
        <?xml version="1.0" encoding="UTF-8"?>
        <testsuites><testsuite name="Unit">
          <testcase name="it works" file="tests/Unit/FooTest.php::it works" class="Tests\Unit\FooTest" time="0.1"/>
          <testcase name="it is already normal" file="/abs/tests/Unit/BarTest.php" class="P\Tests\Unit\BarTest" time="0.2"/>
        </testsuite></testsuites>
        XML);

    normaliseJunit($path, '/project');

    $cases = simplexml_load_file($path)?->xpath('//testcase') ?? [];

    expect(array_map(static fn (SimpleXMLElement $case): array => [(string) $case['class'], (string) $case['file']], $cases))
        ->toBe([
            ['P\\Tests\\Unit\\FooTest', '/project/tests/Unit/FooTest.php'],
            ['P\\Tests\\Unit\\BarTest', '/abs/tests/Unit/BarTest.php'],
        ]);

    unlink($path);
});

it('names each test file suite after the class PHPUnit would report, so dataset tests resolve to one node', function (): void {
    // Infection looks a test up by `//testsuite[@name=<class>][1]` first, then by
    // `//testcase[@class=<class>][1]`; the second matches once per parent element, so a
    // class with both plain and dataset tests has two first matches and Infection aborts.
    $path = junitFixture(<<<'XML'
        <?xml version="1.0" encoding="UTF-8"?>
        <testsuites><testsuite name="Unit">
          <testsuite name="Tests\Unit\FooTest" file="tests/Unit/FooTest.php">
            <testcase name="it works" file="tests/Unit/FooTest.php::it works" class="Tests\Unit\FooTest" time="0.1"/>
            <testsuite name="it has data">
              <testcase name="it has data with data set #0" file="tests/Unit/FooTest.php::it has data" class="Tests\Unit\FooTest" time="0.1"/>
            </testsuite>
          </testsuite>
          <testsuite name="P\Tests\Unit\BarTest" file="/abs/tests/Unit/BarTest.php"/>
        </testsuite></testsuites>
        XML);

    normaliseJunit($path, '/project');

    $suites = simplexml_load_file($path)?->xpath('//testsuite') ?? [];

    expect(array_map(static fn (SimpleXMLElement $suite): array => [(string) $suite['name'], (string) $suite['file']], $suites))
        ->toBe([
            ['Unit', ''],
            ['P\\Tests\\Unit\\FooTest', '/project/tests/Unit/FooTest.php'],
            ['it has data', ''],
            ['P\\Tests\\Unit\\BarTest', '/abs/tests/Unit/BarTest.php'],
        ]);

    unlink($path);
});

it('leaves an unreadable JUnit report untouched', function (): void {
    $truncated = '<?xml version="1.0"?><testsuites><testsuite name="Unit"><testcase class="Tests';
    $path = junitFixture($truncated);

    normaliseJunit($path, '/project');

    expect(file_get_contents($path))->toBe($truncated);

    unlink($path);
});

// Pest exits 0 when a test performs no assertions, even with failOnRisky="true" (its
// Result::exitCode() returns early for a run without failures). Under Infection a mutant that
// removes a test's only assertion would then be reported as "OK" and escape.
it('fails a run in which a test performs no assertions, naming it', function (): void {
    $process = bridge(dirname(__DIR__, 2).'/Fixtures/Pest/RiskyTest.php');

    expect($process->getExitCode())->toBe(1)
        ->and($process->getOutput())->toContain('FAILURES! (Pest exited with 0, but 1 test was risky: P\\Tests\\Fixtures\\Pest\\RiskyTest::__pest_evaluable_it_asserts_nothing')
        ->not->toContain("\nOK (");
});

it('passes a run in which every test asserts something, an expected exception included', function (): void {
    $process = bridge(dirname(__DIR__, 2).'/Fixtures/Pest/PassingTest.php');

    expect($process->getExitCode())->toBe(0)
        ->and($process->getOutput())->toContain("\nOK (Pest exited with 0)");
});
