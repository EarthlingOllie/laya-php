<?php

declare(strict_types=1);

namespace EarthlingOllie\SystemOneClassification\Tests\Fixtures\Enums;

use EarthlingOllie\SystemOneClassification\Domain\Describe;
use EarthlingOllie\SystemOneClassification\Domain\DescribesCases;

/** Score levels described in code (translated text, say), declared highest first. */
enum TranslatedSeverity: int implements DescribesCases
{
    #[Describe('ignored: the interface takes precedence')]
    case High = 2;

    case Low = 1;

    public function describe(): string
    {
        return match ($this) {
            self::High => 'dringend',
            self::Low => 'kann warten',
        };
    }
}
