<?php

declare(strict_types=1);

namespace EarthlingOllie\SystemOneClassification\Tests\Fixtures\Enums;

/** A case backed by the empty string, which would go out as an empty option label. */
enum EmptyLabel: string
{
    case Fine = 'fine';
    case Empty = '';
}
