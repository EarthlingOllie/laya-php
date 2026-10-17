<?php

declare(strict_types=1);

namespace EarthlingOllie\SystemOneClassification\Result\Answer;

use EarthlingOllie\SystemOneClassification\Result\Abstention;

/**
 * The typed answer to one question; each question type has its own answer class.
 *
 * Every answer says whether it abstained (decision 10): `abstained` is true only when the
 * server's gate reported Abstention::Abstained. The answer's value still holds the model's best
 * guess then, so a caller can route it to a human and still show what the model thought.
 */
interface Answer
{
    /** Whether the answer fell below the decision's minimum confidence. */
    public bool $abstained { get; }

    /** The gate's full verdict, including Unevaluated and NotRequested, which read as not abstained. */
    public Abstention $abstention { get; }

    /** The minimum confidence the answer was gated at, or null when the server reported none. */
    public ?float $abstentionThreshold { get; }
}
