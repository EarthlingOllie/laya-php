<?php

declare(strict_types=1);

namespace EarthlingOllie\SystemOneClassification\Exceptions;

use BackedEnum;
use Error;
use InvalidArgumentException;
use JsonException;

use function get_debug_type;
use function implode;

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
    public static function malformedDescribe(string $subject, BackedEnum $case, Error $previous): self
    {
        $enum = $case::class;

        return new self(
            "{$subject} has a #[Describe] on {$enum}::{$case->name} that PHP cannot build: {$previous->getMessage()}",
            previous: $previous,
        );
    }

    public static function notIntBackedEnum(string $key, string $class): self
    {
        return new self("Score question '{$key}' needs an int-backed enum (enum X: int), whose backing values order its levels; {$class} is not an int-backed enum.");
    }

    public static function noLevels(string $key, string $enum): self
    {
        return new self("Score question '{$key}' has no levels: {$enum} has no cases.");
    }

    public static function undescribedLevel(string $key, BackedEnum $case, string $profile): self
    {
        $enum = $case::class;

        return new self("Level {$case->value} of score question '{$key}' has no description, which the {$profile} profile requires of every score level: give {$enum}::{$case->name} a #[Describe].");
    }

    public static function blankLevelDescription(string $key, BackedEnum $case): self
    {
        $enum = $case::class;

        return new self("Level {$case->value} of score question '{$key}' has a blank description: describe {$enum}::{$case->name}, because Laya rejects a score level without one.");
    }

    public static function duplicateKey(string $key): self
    {
        return new self("The decision already asks a question with the key '{$key}'.");
    }

    public static function noQuestions(): self
    {
        return new self('The decision asks no questions; add one with ask().');
    }

    public static function tooManyOptions(string $key, int $options, int $limit, string $profile): self
    {
        return new self("Choice question '{$key}' has {$options} options, more than the {$limit} the {$profile} profile allows per choice question.");
    }

    public static function blankModel(): self
    {
        return new self('A model must not be blank; leave it out to let the server choose.');
    }

    public static function paddedModel(string $model, string $trimmed): self
    {
        return new self("Model '{$model}' has whitespace around it; pin '{$trimmed}'.");
    }

    /**
     * @param  list<string>  $accepted
     */
    public static function unacceptedModel(string $model, string $profile, array $accepted): self
    {
        if ($accepted === []) {
            return new self("The decision pins model '{$model}', but the {$profile} profile accepts no model values; list the host's in the connection's 'models' config, or leave the model out.");
        }

        return new self("The decision pins model '{$model}', which the {$profile} profile does not accept; use one of: ".implode(', ', $accepted).'.');
    }

    /**
     * @param  string  $part  what is not UTF-8, as the message names it ("The context text")
     */
    public static function notUtf8(string $part, ?JsonException $previous): self
    {
        return new self("{$part} is not valid UTF-8, which JSON requires.", previous: $previous);
    }

    public static function unsupportedQuestion(object $question): self
    {
        return new self('The wire format has no question type for '.get_debug_type($question).'.');
    }
}
