<?php

declare(strict_types=1);

namespace EarthlingOllie\SystemOneClassification\Tests\Fixtures\Enums;

use EarthlingOllie\SystemOneClassification\Domain\Describe;

/** Int-backed with the values 0..n-1: a plain PHP array keyed by them is a JSON list. */
enum Priority: int
{
    #[Describe('can wait')]
    case Low = 0;

    #[Describe('this week')]
    case Normal = 1;

    #[Describe('today')]
    case High = 2;
}
