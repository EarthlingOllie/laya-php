<?php

declare(strict_types=1);

namespace EarthlingOllie\SystemOneClassification\Tests\Fixtures\Enums;

use EarthlingOllie\SystemOneClassification\Domain\Describe;

/** A description that says nothing. */
enum BlankDescription: string
{
    #[Describe('  ')]
    case Blank = 'blank';
}
