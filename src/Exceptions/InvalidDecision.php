<?php

declare(strict_types=1);

namespace EarthlingOllie\SystemOneClassification\Exceptions;

use InvalidArgumentException;

use function get_debug_type;

/** A decision or question that cannot be sent, caught before anything goes over the wire. */
final class InvalidDecision extends InvalidArgumentException implements SystemOneException
{
    public static function emptyText(): self
    {
        return new self('The context text must not be empty.');
    }

    public static function emptyKey(): self
    {
        return new self('A question key must not be empty.');
    }

    /**
     * PHP turns a key like '0' into an integer array key, so the questions would be sent as a
     * JSON list and the answers object read back as a list.
     */
    public static function integerKey(string $key): self
    {
        return new self("Question key '{$key}' is a whole number, which PHP stores as an integer array key; use a name such as 'q{$key}'.");
    }

    public static function missingInstructions(string $key): self
    {
        return new self("Question '{$key}' needs instructions.");
    }

    public static function duplicateKey(string $key): self
    {
        return new self("The decision already asks a question with the key '{$key}'.");
    }

    public static function noQuestions(): self
    {
        return new self('The decision asks no questions; add one with ask().');
    }

    public static function unsupportedQuestion(object $question): self
    {
        return new self('The wire format has no question type for '.get_debug_type($question).'.');
    }
}
