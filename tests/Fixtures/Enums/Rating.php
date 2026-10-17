<?php

declare(strict_types=1);

namespace EarthlingOllie\SystemOneClassification\Tests\Fixtures\Enums;

/** String-backed with whole-number strings, which PHP turns into integer array keys. */
enum Rating: string
{
    case Zero = '0';
    case One = '1';
    case Ten = '10';
}
