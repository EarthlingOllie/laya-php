<?php

declare(strict_types=1);

namespace EarthlingOllie\SystemOneClassification\Tests\Fixtures\Enums;

use EarthlingOllie\SystemOneClassification\Domain\Describe;

/** Two #[Describe] on one score level, though the attribute is not repeatable. */
enum RepeatedLevelDescribe: int
{
    #[Describe('first')]
    #[Describe('second')]
    case Broken = 0;
}
