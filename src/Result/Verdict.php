<?php

declare(strict_types=1);

namespace EarthlingOllie\SystemOneClassification\Result;

/** A yes/no answer read against a yes and a no threshold (YesNoAnswer::verdict()). */
enum Verdict
{
    case Yes;
    case No;
    case Unsure;
}
