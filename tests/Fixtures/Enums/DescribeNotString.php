<?php

declare(strict_types=1);

namespace EarthlingOllie\SystemOneClassification\Tests\Fixtures\Enums;

use EarthlingOllie\SystemOneClassification\Domain\Describe;

/** A #[Describe] whose description is not a string. */
enum DescribeNotString: string
{
    case Fine = 'fine';

    #[Describe(42)]
    case Broken = 'broken';
}
