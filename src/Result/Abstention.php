<?php

declare(strict_types=1);

namespace EarthlingOllie\SystemOneClassification\Result;

/**
 * What the server's abstention gate said about one answer (its `abstention` field).
 *
 * Only Abstained makes an answer's `abstained` flag true. The answer's value is the model's
 * best guess in every state (decision 10).
 */
enum Abstention
{
    /** The decision set no minimum confidence, so no gate ran (the server sends no `abstention`). */
    case NotRequested;

    /** The answer's confidence reached the minimum (`"passed"`). */
    case Passed;

    /** The answer's confidence fell below the minimum (`"abstained"`): send it to a human. */
    case Abstained;

    /**
     * The decision set a minimum confidence but the gate gave no verdict: the server reported
     * `"unevaluated"` (no usable confidence to compare), or sent no gate fields at all.
     */
    case Unevaluated;
}
