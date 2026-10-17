<?php

declare(strict_types=1);

namespace EarthlingOllie\SystemOneClassification\Tests\Fixtures\Enums;

use EarthlingOllie\SystemOneClassification\Domain\Describe;

/** Score levels 0..3: the urgency scale bin/laya-record-fixtures asks about. */
enum Urgency: int
{
    #[Describe('can wait')]
    case Low = 0;

    #[Describe('soon')]
    case Medium = 1;

    #[Describe('today')]
    case High = 2;

    #[Describe('blocking')]
    case Critical = 3;
}
