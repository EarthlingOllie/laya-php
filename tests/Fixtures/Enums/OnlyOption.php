<?php

declare(strict_types=1);

namespace EarthlingOllie\SystemOneClassification\Tests\Fixtures\Enums;

use EarthlingOllie\SystemOneClassification\Domain\Describe;

/** A choice with a single option, which a model can only pick with certainty. */
enum OnlyOption: string
{
    #[Describe('the one queue')]
    case Queue = 'queue';
}
