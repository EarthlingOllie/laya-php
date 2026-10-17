<?php

declare(strict_types=1);

namespace EarthlingOllie\SystemOneClassification\Tests\Fixtures\Enums;

use EarthlingOllie\SystemOneClassification\Domain\Describe;

/** A score scale with one level left undescribed, which Laya rejects. */
enum UndescribedLevel: int
{
    #[Describe('fine')]
    case Fine = 0;

    case Bad = 1;
}
