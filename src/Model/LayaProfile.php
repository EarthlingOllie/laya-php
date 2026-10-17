<?php

declare(strict_types=1);

namespace EarthlingOllie\SystemOneClassification\Model;

use function array_map;
use function in_array;
use function strtolower;

/**
 * Laya, as served by laya-serve (local Docker or hosted at laya-ai.com).
 *
 * Its `confidence` is 1 - normalised entropy. The limits are laya-serve 0.4.8's own, as its
 * 413 and 422 responses in tests/Fixtures/laya state them.
 */
final readonly class LayaProfile implements ModelProfile
{
    /** laya-serve's cap on choice options (413 above it). */
    public const int MAX_CHOICE_OPTIONS = 100;

    /** The cap when the server runs with LAYA_TOURNAMENT_GROUP (a group size from 2 to 100). */
    public const int TOURNAMENT_MAX_CHOICE_OPTIONS = 255;

    /**
     * The checkpoints, then their aliases, in the order laya-serve's "unknown model" 422
     * lists them.
     */
    public const array MODELS = [
        'english', 'multilingual', 'typed-decisions',
        'decisions', 'default', 'en', 'laya', 'laya-multilingual', 'laya-typed-decisions',
        'ml', 'multi', 'typed', 'typed_decisions',
    ];

    public string $name;

    public int $maxChoiceOptions;

    public bool $requiresScoreDescriptions;

    /**
     * @param  bool  $tournamentGrouping  whether the server runs with LAYA_TOURNAMENT_GROUP set
     *                                    to a group size from 2 to 100
     * @param  list<string>  $extraModels  further `model` values the server accepts: the names
     *                                     in its LAYA_EXTRA_MODELS (a JSON object of name to
     *                                     source)
     */
    public function __construct(bool $tournamentGrouping = false, private array $extraModels = [])
    {
        $this->name = 'laya';
        $this->maxChoiceOptions = $tournamentGrouping ? self::TOURNAMENT_MAX_CHOICE_OPTIONS : self::MAX_CHOICE_OPTIONS;
        // laya-serve answers a score level without a description with a 422.
        $this->requiresScoreDescriptions = true;
    }

    public function models(): array
    {
        return [...self::MODELS, ...$this->extraModels];
    }

    /**
     * laya-serve lowercases a name before looking it up, for its own checkpoints and aliases and
     * for the ones it was configured with alike (laya.router.canonical_name).
     */
    public function acceptsModel(string $model): bool
    {
        return in_array(strtolower($model), array_map(strtolower(...), $this->models()), true);
    }
}
