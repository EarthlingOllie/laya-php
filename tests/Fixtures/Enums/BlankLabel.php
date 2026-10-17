<?php

declare(strict_types=1);

namespace EarthlingOllie\SystemOneClassification\Tests\Fixtures\Enums;

/** A case backed by whitespace only, which would go out as a blank option label. */
enum BlankLabel: string
{
    case Blank = '  ';
}
