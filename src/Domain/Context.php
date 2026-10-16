<?php

declare(strict_types=1);

namespace EarthlingOllie\SystemOneClassification\Domain;

use EarthlingOllie\SystemOneClassification\Exceptions\InvalidDecision;

use function trim;

/**
 * The piece of text a decision is about (the wire's `state`).
 *
 * Only plain text for now; structured fields (`Context::fields([...])`) come later. Empty or
 * blank text is refused: the server would still answer it, with a meaningless 0.5.
 */
final readonly class Context
{
    private function __construct(public string $text) {}

    /** @throws InvalidDecision when the text is empty or only whitespace */
    public static function text(string $text): self
    {
        if (trim($text) === '') {
            throw InvalidDecision::emptyText();
        }

        return new self($text);
    }
}
