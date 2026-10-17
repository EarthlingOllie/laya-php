<?php

declare(strict_types=1);

namespace EarthlingOllie\SystemOneClassification\Exceptions;

use InvalidArgumentException;
use JsonException;

use function get_debug_type;

/** A decision or question that cannot be sent, caught before anything goes over the wire. */
final class InvalidDecision extends InvalidArgumentException implements SystemOneException
{
    public static function emptyText(): self
    {
        return new self('The context text must not be empty.');
    }

    public static function emptyFields(): self
    {
        return new self('The context fields must not be empty.');
    }

    public static function unnamedFields(): self
    {
        return new self('The context fields must be named: every top-level key must be a string that is not a whole number, so they are sent as a JSON object.');
    }

    public static function notPlainField(string $path, string $type): self
    {
        return new self("The context field '{$path}' holds a {$type}, which is not plain JSON data; pass arrays and scalars only.");
    }

    public static function nonFiniteField(string $path, float $value): self
    {
        return new self("The context field '{$path}' holds a float that is not finite ({$value}), which JSON cannot represent.");
    }

    public static function unserialisableFields(JsonException $reason): self
    {
        return new self('The context fields are not JSON-serialisable: '.$reason->getMessage().'.', previous: $reason);
    }

    /** Worded like json_encode's own depth error, which a field this deep would otherwise raise. */
    public static function tooDeepField(string $field, int $limit): self
    {
        return new self("The context fields are not JSON-serialisable: Maximum stack depth exceeded in the field '{$field}', which nests arrays deeper than the {$limit} levels JSON allows.");
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

    public static function minConfidenceOutOfRange(float $minConfidence): self
    {
        return new self("The minimum confidence must be between 0 and 1, got {$minConfidence}.");
    }

    public static function emptyLanguage(string $name): self
    {
        return new self("{$name} must not be empty.");
    }

    public static function nonPositiveLength(string $name, int $tokens): self
    {
        return new self("{$name} must be a positive number of tokens, got {$tokens}.");
    }
}
