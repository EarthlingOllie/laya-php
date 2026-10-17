<?php

declare(strict_types=1);

namespace Workbench\App\Enums;

use EarthlingOllie\SystemOneClassification\Domain\Describe;

/** The urgency levels the workbench's /score page rates a ticket on, lowest first. */
enum Urgency: int
{
    #[Describe('can wait')]
    case Low = 0;

    #[Describe('soon')]
    case Medium = 1;

    #[Describe('today')]
    case High = 2;

    #[Describe('blocking')]
    case Critical = 3;
}
