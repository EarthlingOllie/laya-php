<?php

declare(strict_types=1);

namespace EarthlingOllie\SystemOneClassification\Laravel;

use EarthlingOllie\SystemOneClassification\Client\Decider;
use EarthlingOllie\SystemOneClassification\Exceptions\InvalidConfiguration;

/**
 * What the SystemOne facade stands for: decide() on the default connection, and a Decider per
 * named connection. SystemOneManager is the real one and SystemOne::fake() swaps in the fake.
 *
 * The container resolves this interface to whichever is active, so inject it (not
 * SystemOneManager) to keep injected code fakeable.
 */
interface ConnectionResolver extends Decider
{
    /**
     * The decider for a named connection, or the default one.
     *
     * @throws InvalidConfiguration when the connection is missing or malformed
     */
    public function connection(?string $name = null): Decider;
}
