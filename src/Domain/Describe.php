<?php

declare(strict_types=1);

namespace EarthlingOllie\SystemOneClassification\Domain;

use Attribute;

/**
 * Describes one enum case as an option: `#[Describe('invoices, payments, refunds')]`.
 *
 * The model reads the description next to the case's backing value (the option label). An
 * enum that implements DescribesCases is described by that instead.
 */
#[Attribute(Attribute::TARGET_CLASS_CONSTANT)]
final readonly class Describe
{
    public function __construct(public string $description) {}
}
