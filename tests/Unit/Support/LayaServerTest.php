<?php

declare(strict_types=1);

use EarthlingOllie\SystemOneClassification\Tests\Support\LayaServer;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\SkippedWithMessageException;

/*
 * LayaServer guards the Integration suite: locally it skips when no server runs; in CI
 * (or with SYSTEM_ONE_REQUIRE_SERVER=1) a missing server fails the run instead, so the
 * integration gate can never pass vacuously.
 */

function closedPortUrl(): string
{
    $socket = stream_socket_server('tcp://127.0.0.1:0');
    assert($socket !== false);
    $address = stream_socket_get_name($socket, false);
    fclose($socket);

    return 'http://'.$address;
}

it('defaults to the local Laya port', function (): void {
    expect((new LayaServer(url: null, environment: []))->url)->toBe('http://localhost:8000');
});

it('reads the server address from SYSTEM_ONE_URL', function (): void {
    expect(LayaServer::fromEnvironment(['SYSTEM_ONE_URL' => 'http://laya:8000'])->url)->toBe('http://laya:8000');
});

it('sees a listening server as reachable and a closed port as not', function (): void {
    $socket = stream_socket_server('tcp://127.0.0.1:0');
    assert($socket !== false);

    expect((new LayaServer('http://'.stream_socket_get_name($socket, false), []))->isReachable())->toBeTrue()
        ->and((new LayaServer(closedPortUrl(), []))->isReachable())->toBeFalse();

    fclose($socket);
});

it('skips the test when no server is reachable locally', function (): void {
    (new LayaServer(closedPortUrl(), []))->guard();
})->throws(SkippedWithMessageException::class, 'No Laya server at');

it('fails the test when a server is required', function (array $environment): void {
    (new LayaServer(closedPortUrl(), $environment))->guard();
})->throws(AssertionFailedError::class, 'No Laya server at')->with([
    'in CI' => [['CI' => 'true']],
    'when required explicitly' => [['SYSTEM_ONE_REQUIRE_SERVER' => '1']],
]);

it('does nothing when the server is reachable', function (): void {
    $socket = stream_socket_server('tcp://127.0.0.1:0');
    assert($socket !== false);

    (new LayaServer('http://'.stream_socket_get_name($socket, false), ['CI' => 'true']))->guard();
    fclose($socket);

    expect(true)->toBeTrue();
});
