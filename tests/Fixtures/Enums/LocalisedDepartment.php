<?php

declare(strict_types=1);

namespace EarthlingOllie\SystemOneClassification\Tests\Fixtures\Enums;

use EarthlingOllie\SystemOneClassification\Domain\DescribesCases;

/** Describes its cases in the current locale, as an app's translator would. */
enum LocalisedDepartment: string implements DescribesCases
{
    case Billing = 'billing';

    case Technical = 'technical';

    public function describe(): string
    {
        $german = Translator::$locale === 'de';

        return match ($this) {
            self::Billing => $german ? 'Rechnungen und Zahlungen' : 'invoices and payments',
            self::Technical => $german ? 'Fehler und Ausfälle' : 'bugs and outages',
        };
    }
}
