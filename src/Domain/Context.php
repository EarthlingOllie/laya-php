<?php

declare(strict_types=1);

namespace EarthlingOllie\SystemOneClassification\Domain;

use EarthlingOllie\SystemOneClassification\Exceptions\InvalidDecision;
use JsonException;

use function get_debug_type;
use function is_array;
use function is_float;
use function is_infinite;
use function is_int;
use function is_nan;
use function is_scalar;
use function json_encode;
use function trim;

/**
 * What a decision is about (the wire's `state`): plain text, or named fields sent as a JSON object.
 *
 * Empty or blank text and empty fields are refused: the server would still answer them, with a
 * meaningless 0.5.
 */
final readonly class Context
{
    /**
     * json_encode's default depth, counted from the request body: the body is level 1 and the
     * fields object (the body's `state`) level 2, so the whole body still encodes.
     */
    private const int MAX_DEPTH = 512;

    /** The JSON level of a top-level field's value inside the request body. */
    private const int FIELD_DEPTH = 3;

    /**
     * @param  string|array<string, mixed>  $state  the text, or the fields as plain JSON data
     */
    private function __construct(public string|array $state) {}

    /** @throws InvalidDecision when the text is empty or only whitespace */
    public static function text(string $text): self
    {
        if (trim($text) === '') {
            throw InvalidDecision::emptyText();
        }

        return new self($text);
    }

    /**
     * Named fields, such as ['subject' => …, 'body' => …], sent as a JSON object.
     *
     * Values may be strings, numbers, booleans, null and arrays of those, nested as deep as
     * JSON allows once the fields sit inside the request body (510 arrays below a field). Objects are refused, even JsonSerializable ones: convert them first, so the
     * context holds plain data that cannot change after it is built.
     *
     * @param  array<mixed>  $fields
     *
     * @throws InvalidDecision when the fields are empty, not named, or not plain JSON data
     */
    public static function fields(array $fields): self
    {
        if ($fields === []) {
            throw InvalidDecision::emptyFields();
        }

        $named = [];

        foreach ($fields as $name => $value) {
            // PHP stores a whole-number string key as an int, so this also refuses '3'.
            if (is_int($name)) {
                throw InvalidDecision::unnamedFields();
            }

            $named[$name] = self::plainCopy($value, $name, $name, self::FIELD_DEPTH);
        }

        try {
            json_encode($named, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw InvalidDecision::unserialisableFields($exception);
        }

        return new self($named);
    }

    /**
     * Checks the value is plain JSON data and returns a copy of it with no PHP references inside, so
     * a reference the caller still holds cannot change the context after it is built.
     *
     * The walk stops where json_encode would, so a self-referencing array is refused instead of
     * recursing until PHP crashes.
     *
     * @param  int  $depth  the JSON level, within the request body, the value sits at if it is an array
     *
     * @throws InvalidDecision
     */
    private static function plainCopy(mixed $value, string $field, string $path, int $depth): mixed
    {
        if (is_float($value) && (is_nan($value) || is_infinite($value))) {
            throw InvalidDecision::nonFiniteField($path, $value);
        }

        if (is_array($value)) {
            if ($depth > self::MAX_DEPTH) {
                throw InvalidDecision::tooDeepField($field, self::MAX_DEPTH);
            }

            $copy = [];

            foreach ($value as $key => $item) {
                $copy[$key] = self::plainCopy($item, $field, "{$path}.{$key}", $depth + 1);
            }

            return $copy;
        }

        if ($value !== null && ! is_scalar($value)) {
            throw InvalidDecision::notPlainField($path, get_debug_type($value));
        }

        return $value;
    }
}
