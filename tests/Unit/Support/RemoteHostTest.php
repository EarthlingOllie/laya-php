<?php

declare(strict_types=1);

use EarthlingOllie\SystemOneClassification\Tests\Support\RemoteHost;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\SkippedWithMessageException;

/*
 * RemoteHost guards the opt-in Smoke suite: without a host's key its tests skip (nothing is
 * sent, nothing is billed); the manual smoke workflow names the hosts it means to reach in
 * SYSTEM_ONE_REQUIRE_SMOKE, and a missing key then fails the run instead.
 */

it('names the key variable of each remote connection', function (string $connection, string $variable): void {
    expect((new RemoteHost($connection, []))->keyVariable())->toBe($variable);
})->with([
    ['laya-hosted', 'SYSTEM_ONE_LAYA_HOSTED_API_KEY'],
    ['jev', 'SYSTEM_ONE_JEV_API_KEY'],
]);

it('has a key only when its variable holds one', function (array $environment, bool $hasKey): void {
    expect(RemoteHost::fromEnvironment('jev', $environment)->hasKey())->toBe($hasKey);
})->with([
    'set' => [['SYSTEM_ONE_JEV_API_KEY' => 'k'], true],
    'unset' => [[], false],
    'blank' => [['SYSTEM_ONE_JEV_API_KEY' => '  '], false],
    "another host's" => [['SYSTEM_ONE_LAYA_HOSTED_API_KEY' => 'k'], false],
    'not a string' => [['SYSTEM_ONE_JEV_API_KEY' => ['k']], false],
]);

it('reads the real environment by default', function (): void {
    putenv('SYSTEM_ONE_JEV_API_KEY=from-env');

    try {
        expect(RemoteHost::fromEnvironment('jev')->hasKey())->toBeTrue();
    } finally {
        putenv('SYSTEM_ONE_JEV_API_KEY');
    }

    expect(RemoteHost::fromEnvironment('jev')->hasKey())->toBeFalse();
});

it('is required only when SYSTEM_ONE_REQUIRE_SMOKE names it', function (string $required, bool $isRequired): void {
    expect(RemoteHost::fromEnvironment('jev', ['SYSTEM_ONE_REQUIRE_SMOKE' => $required])->isRequired())->toBe($isRequired);
})->with([
    ['jev', true],
    ['laya-hosted, jev', true],
    ['laya-hosted', false],
    ['', false],
    ['jevs', false],
]);

it('passes the guard with a key', function (): void {
    (new RemoteHost('jev', ['SYSTEM_ONE_JEV_API_KEY' => 'k', 'SYSTEM_ONE_REQUIRE_SMOKE' => 'jev']))->guard();

    expect(true)->toBeTrue();
});

it('skips without a key', function (): void {
    expect(fn () => (new RemoteHost('laya-hosted', []))->guard())
        ->toThrow(SkippedWithMessageException::class, "No key for 'laya-hosted': set SYSTEM_ONE_LAYA_HOSTED_API_KEY to run its smoke tests.");
});

it('fails without a key when the host is required', function (): void {
    expect(fn () => (new RemoteHost('jev', ['SYSTEM_ONE_REQUIRE_SMOKE' => 'jev']))->guard())
        ->toThrow(AssertionFailedError::class, "No key for 'jev': set SYSTEM_ONE_JEV_API_KEY to run its smoke tests. They are required here (SYSTEM_ONE_REQUIRE_SMOKE).");
});
