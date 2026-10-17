<?php

declare(strict_types=1);

namespace EarthlingOllie\SystemOneClassification\Tests\Fixtures\Enums;

use EarthlingOllie\SystemOneClassification\Domain\Describe;

/** The scale bin/laya-record-fixtures sends for `invalid_request`: level 1 has no description. */
enum PartlyDescribedUrgency: int
{
    #[Describe('can wait')]
    case Low = 0;

    case Medium = 1;

    #[Describe('blocking')]
    case High = 2;
}
