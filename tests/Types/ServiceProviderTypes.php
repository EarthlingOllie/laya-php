<?php

declare(strict_types=1);

/*
 * Type-inference tests. Nothing here is executed: PHPStan analyses this directory and
 * fails if an assertType() expectation does not match the inferred type.
 * Later tickets add e.g. `assertType(Department::class, $result->get($department)->value)`.
 */

namespace EarthlingOllie\SystemOneClassification\Tests\Types;

use EarthlingOllie\SystemOneClassification\Laravel\SystemOneServiceProvider;
use Illuminate\Foundation\Application;
use Illuminate\Support\ServiceProvider;

use function PHPStan\Testing\assertType;

function serviceProviderTypes(Application $app): void
{
    assertType(SystemOneServiceProvider::class, new SystemOneServiceProvider($app));

    $registered = $app->getProvider(SystemOneServiceProvider::class);

    assertType(ServiceProvider::class.'|null', $registered);

    if ($registered instanceof SystemOneServiceProvider) {
        assertType(SystemOneServiceProvider::class, $registered);
    }
}
