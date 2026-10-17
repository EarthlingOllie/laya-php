<?php

declare(strict_types=1);

namespace EarthlingOllie\SystemOneClassification\Exceptions;

use BackedEnum;
use Error;
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

    public static function notBackedEnum(string $key, string $class): self
    {
        return new self("Choice question '{$key}' needs a backed enum (enum X: string or enum X: int); {$class} is not a backed enum.");
    }

    public static function noOptions(string $key, string $enum): self
    {
        return new self("Choice question '{$key}' has no options: {$enum} has no cases.");
    }

    public static function blankLabel(string $key, BackedEnum $case): self
    {
        $enum = $case::class;

        return new self("Choice question '{$key}' has a blank option label: {$enum}::{$case->name} is backed by '{$case->value}', which is a blank option label; back it with a word the model can answer with.");
    }

    public static function blankDescription(string $key, string $label): self
    {
        return new self("Option '{$label}' of choice question '{$key}' has a description that is blank; describe it or leave the description out.");
    }

    /**
     * PHP's own Error stays attached as the previous exception: it names the file and line of
     * the attribute, and there is nothing secret in it.
     */
    public static function malformedDescribe(string $key, BackedEnum $case, Error $previous): self
    {
        $enum = $case::class;

        return new self(
            "Option '{$case->value}' of choice question '{$key}' has a #[Describe] on {$enum}::{$case->name} that PHP cannot build: {$previous->getMessage()}",
            previous: $previous,
        );
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
