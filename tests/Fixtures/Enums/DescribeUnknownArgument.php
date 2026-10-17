<?php

declare(strict_types=1);

namespace EarthlingOllie\SystemOneClassification\Tests\Fixtures\Enums;

use EarthlingOllie\SystemOneClassification\Domain\Describe;

/** A #[Describe] that names an argument the attribute does not have. */
enum DescribeUnknownArgument: string
{
    case Fine = 'fine';

    #[Describe(text: 'misnamed')]
    case Broken = 'broken';
}
