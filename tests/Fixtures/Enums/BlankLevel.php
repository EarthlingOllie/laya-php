<?php

declare(strict_types=1);

namespace EarthlingOllie\SystemOneClassification\Tests\Fixtures\Enums;

use EarthlingOllie\SystemOneClassification\Domain\Describe;

/** A score level whose description says nothing. */
enum BlankLevel: int
{
    #[Describe('fine')]
    case Fine = 0;

    #[Describe(' ')]
    case Blank = 1;
}
