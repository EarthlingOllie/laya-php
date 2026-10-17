<?php

declare(strict_types=1);

namespace EarthlingOllie\SystemOneClassification\Model;

use function in_array;

/**
 * TypeSafe's hosted Jev API.
 *
 * Its `confidence` uses Jev's own formula, so it is not comparable with Laya's. Jev allows 255
 * choice options and score levels without descriptions. Its `model` values are not published
 * in anything this package has recorded (tests/Fixtures/jev), so a connection lists the ones
 * it accepts; with none listed, withModel() is refused.
 */
final readonly class JevProfile implements ModelProfile
{
    public const int MAX_CHOICE_OPTIONS = 255;

    public string $name;

    public int $maxChoiceOptions;

    public bool $requiresScoreDescriptions;

    /**
     * @param  list<string>  $models  the `model` values the host accepts
     */
    public function __construct(private array $models = [])
    {
        $this->name = 'jev';
        $this->maxChoiceOptions = self::MAX_CHOICE_OPTIONS;
        $this->requiresScoreDescriptions = false;
    }

    public function models(): array
    {
        return $this->models;
    }

    /** Exactly as listed: nothing recorded says Jev folds case. */
    public function acceptsModel(string $model): bool
    {
        return in_array($model, $this->models, true);
    }
}
