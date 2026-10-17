<?php

declare(strict_types=1);

namespace EarthlingOllie\SystemOneClassification\Tests\Fixtures\Enums;

/** A pure enum: its cases have no backing value to use as a label. */
enum Unbacked
{
    case Yes;
    case No;
}
