<?php

declare(strict_types=1);

namespace EarthlingOllie\SystemOneClassification\Tests\Fixtures\Enums;

use EarthlingOllie\SystemOneClassification\Domain\Describe;
use EarthlingOllie\SystemOneClassification\Domain\DescribesCases;

/** Describes its cases in code (translated text, say), which wins over the attributes. */
enum TranslatedDepartment: string implements DescribesCases
{
    #[Describe('ignored: the interface takes precedence')]
    case Billing = 'billing';

    case Technical = 'technical';

    public function describe(): string
    {
        return match ($this) {
            self::Billing => 'Rechnungen und Zahlungen',
            self::Technical => 'Fehler und Ausfälle',
        };
    }
}
