<?php

declare(strict_types=1);

use EarthlingOllie\SystemOneClassification\Laravel\Install\EnvFile;

it('appends a key that is not set', function (string $contents, string $expected, string $key = 'SYSTEM_ONE_URL'): void {
    expect(EnvFile::withDefault($contents, $key, 'http://localhost:8000'))->toBe($expected);
})->with([
    'trailing newline' => ["APP_NAME=x\n", "APP_NAME=x\n\nSYSTEM_ONE_URL=http://localhost:8000\n"],
    'no trailing newline' => ['APP_NAME=x', "APP_NAME=x\n\nSYSTEM_ONE_URL=http://localhost:8000\n"],
    'empty file' => ['', "SYSTEM_ONE_URL=http://localhost:8000\n"],
    'only a commented-out key' => ["# SYSTEM_ONE_URL=http://old\n", "# SYSTEM_ONE_URL=http://old\n\nSYSTEM_ONE_URL=http://localhost:8000\n"],
    'a key that only matches as a pattern' => ["AXB=1\n", "AXB=1\n\nA.B=http://localhost:8000\n", 'A.B'],
    'a longer key with the same prefix' => ["SYSTEM_ONE_URL_OLD=a\n", "SYSTEM_ONE_URL_OLD=a\n\nSYSTEM_ONE_URL=http://localhost:8000\n"],
]);

it('leaves a key that is already set, whatever its value', function (string $contents): void {
    expect(EnvFile::withDefault($contents, 'SYSTEM_ONE_URL', 'http://localhost:8000'))->toBeNull();
})->with([
    "SYSTEM_ONE_URL=http://laya:8000\n",
    "APP_NAME=x\nSYSTEM_ONE_URL=\n",
    "  SYSTEM_ONE_URL = \"http://x\"\n",
    "export SYSTEM_ONE_URL=http://x\n",
]);
