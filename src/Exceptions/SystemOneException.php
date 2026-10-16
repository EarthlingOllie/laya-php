<?php

declare(strict_types=1);

namespace EarthlingOllie\SystemOneClassification\Exceptions;

use Throwable;

/** Implemented by every exception this package throws, so callers can catch them all. */
interface SystemOneException extends Throwable {}
