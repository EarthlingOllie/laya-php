<?php

declare(strict_types=1);

namespace EarthlingOllie\SystemOneClassification\Tests\Fixtures\Enums;

use EarthlingOllie\SystemOneClassification\Domain\Describe;

/** A #[Describe] that gives no description. */
enum DescribeWithoutText: string
{
    case Fine = 'fine';

    #[Describe]
    case Broken = 'broken';
}
