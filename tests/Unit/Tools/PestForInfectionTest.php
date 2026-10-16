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

it('leaves an unreadable JUnit report untouched', function (): void {
    $truncated = '<?xml version="1.0"?><testsuites><testsuite name="Unit"><testcase class="Tests';
    $path = junitFixture($truncated);

    normaliseJunit($path, '/project');

    expect(file_get_contents($path))->toBe($truncated);

    unlink($path);
});
