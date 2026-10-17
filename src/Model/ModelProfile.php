<?php

declare(strict_types=1);

namespace EarthlingOllie\SystemOneClassification\Model;

/**
 * What one model behind a connection accepts, and how to read what it returns.
 *
 * Laya and Jev speak the same wire protocol, so a profile encodes nothing: it holds the
 * model's limits, which the codec checks every decision against before anything is sent, and
 * the name its raw `confidence` values are tagged with. A connection picks its profile in
 * config (`profile`).
 */
interface ModelProfile
{
    /** The profile's name, which tags every raw `confidence` it produced ('laya', 'jev'). */
    public string $name { get; }

    /** The most options one choice question may have. */
    public int $maxChoiceOptions { get; }

    /** Whether every score level must have a description. */
    public bool $requiresScoreDescriptions { get; }

    /**
     * The `model` values a decision may pin with Decision::withModel(), as messages list them.
     *
     * @return list<string>
     */
    public function models(): array;

    /** Whether the model would accept $model as written, matched the way its server matches. */
    public function acceptsModel(string $model): bool;
}
