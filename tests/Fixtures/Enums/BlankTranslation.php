<?php

declare(strict_types=1);

namespace EarthlingOllie\SystemOneClassification\Tests\Fixtures\Enums;

use EarthlingOllie\SystemOneClassification\Domain\DescribesCases;

/** A translation that came back empty. */
enum BlankTranslation: int implements DescribesCases
{
    case Only = 7;

    public function describe(): string
    {
        return '';
    }
}
