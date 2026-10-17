<?php

declare(strict_types=1);

namespace EarthlingOllie\SystemOneClassification\Tests\Fixtures\Enums;

use BackedEnum;

/** An interface over backed enums: a subclass of BackedEnum, but not an enum with cases. */
interface BackedEnumInterface extends BackedEnum {}
