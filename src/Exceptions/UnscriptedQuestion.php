<?php

declare(strict_types=1);

namespace EarthlingOllie\SystemOneClassification\Exceptions;

use LogicException;

use function implode;

/** A strict SystemOne::fake() was asked a question it has no script for. */
final class UnscriptedQuestion extends LogicException implements SystemOneException
{
    /**
     * @param  list<list<string>>  $nearMisses  for each script with the same key, what differs from the asked question
     */
    public static function notScripted(string $key, array $nearMisses): self
    {
        $message = "The fake has no script for the question '{$key}'.";

        foreach ($nearMisses as $fields) {
            $message .= " A script for '{$key}' is for another question: it differs in its ".implode(' and its ', $fields).'.';
        }

        return new self($message.' Script it with FakeAnswer, or leave the fake unstrict to get a default answer.');
    }
}
