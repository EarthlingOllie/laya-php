<?php

declare(strict_types=1);

use EarthlingOllie\SystemOneClassification\Tests\Support\LayaServer;
use EarthlingOllie\SystemOneClassification\Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Suites
|--------------------------------------------------------------------------
|
| Unit, Contract and Arch tests are plain Pest tests with no framework.
| Feature tests boot a Laravel application through Orchestra Testbench.
| Integration tests talk to a real Laya server (SYSTEM_ONE_URL). They are
| grouped as `integration`, are not part of the default suite (see
| phpunit.xml.dist), and skip themselves when no server is reachable,
| unless CI or SYSTEM_ONE_REQUIRE_SERVER=1 is set: then they fail.
| Smoke tests reach the real hosted Laya and Jev APIs; they are not in the
| default suite either, and each skips unless its host's key is set
| (Tests\Support\RemoteHost).
|
*/

pest()->extend(TestCase::class)->in('Feature');

// The opt-in Smoke suite (tests/Smoke) boots Laravel too, and is grouped so it can be told apart.
pest()->extend(TestCase::class)->group('smoke')->in('Smoke');

pest()->group('integration')
    ->beforeEach(function (): void {
        [$server, $reachable] = integrationServer();

        $server->guard($reachable);
    })
    ->in('Integration');

/**
 * The Integration suite's server and whether it is reachable, probed once per run.
 *
 * @return array{LayaServer, bool}
 */
function integrationServer(): array
{
    static $state = null;

    if ($state === null) {
        $server = LayaServer::fromEnvironment();
        $state = [$server, $server->isReachable()];
    }

    return $state;
}
