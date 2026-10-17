<?php

declare(strict_types=1);

namespace EarthlingOllie\SystemOneClassification\Tests\Fixtures\Enums;

use EarthlingOllie\SystemOneClassification\Domain\Describe;

/** Score levels declared out of order, with gaps between the backing values (1, 5, 10). */
enum Severity: int
{
    #[Describe('the service is down')]
    case Critical = 10;

    #[Describe('a cosmetic issue')]
    case Minor = 1;

    #[Describe('a feature is broken')]
    case Major = 5;
}
