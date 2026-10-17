<?php

declare(strict_types=1);

namespace Workbench\App\Enums;

use EarthlingOllie\SystemOneClassification\Domain\Describe;

/** The departments the workbench's /choose page routes a ticket to. */
enum Department: string
{
    #[Describe('invoices, payments, refunds')]
    case Billing = 'billing';

    #[Describe('bugs, outages, system errors')]
    case Technical = 'technical';

    #[Describe('everything else')]
    case Other = 'other';
}
