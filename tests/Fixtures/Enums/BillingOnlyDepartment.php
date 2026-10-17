<?php

declare(strict_types=1);

namespace EarthlingOllie\SystemOneClassification\Tests\Fixtures\Enums;

use EarthlingOllie\SystemOneClassification\Domain\Describe;

/** LocalisedDepartment's billing option (in English) alone: the same choice, one option fewer. */
enum BillingOnlyDepartment: string
{
    #[Describe('invoices and payments')]
    case Billing = 'billing';
}
