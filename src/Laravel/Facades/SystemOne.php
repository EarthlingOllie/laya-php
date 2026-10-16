<?php

declare(strict_types=1);

namespace EarthlingOllie\SystemOneClassification\Laravel\Facades;

use EarthlingOllie\SystemOneClassification\Client\DecisionClient;
use EarthlingOllie\SystemOneClassification\Domain\Decision;
use EarthlingOllie\SystemOneClassification\Laravel\SystemOneManager;
use EarthlingOllie\SystemOneClassification\Result\Result;
use Illuminate\Support\Facades\Facade;

/**
 * @method static Result decide(Decision $decision)
 * @method static DecisionClient connection(?string $name = null)
 *
 * @see SystemOneManager
 */
final class SystemOne extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return SystemOneManager::class;
    }
}
