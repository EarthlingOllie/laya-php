<?php

declare(strict_types=1);

namespace EarthlingOllie\SystemOneClassification\Tests\Fixtures\Enums;

use EarthlingOllie\SystemOneClassification\Domain\Describe;

/** Only some cases are described: the others go out as bare labels. */
enum Channel: string
{
    #[Describe('a message sent by email')]
    case Email = 'email';

    case Phone = 'phone';
}
