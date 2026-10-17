<?php

declare(strict_types=1);

namespace EarthlingOllie\SystemOneClassification\Exceptions;

use OutOfBoundsException;

use function implode;
use function in_array;

/** Result::get() was given a question the decision did not ask. */
final class UnknownQuestion extends OutOfBoundsException implements SystemOneException
{
    /**
     * @param  list<string>  $asked
     */
    public static function notAsked(string $key, array $asked): self
    {
        $keys = $asked === [] ? 'none' : "'".implode("', '", $asked)."'";

        return new self("The result has no answer for the question '{$key}': it was not part of the decision. Asked: {$keys}.");
    }

    /**
     * @param  non-empty-list<string>  $fields  what differs from the asked handle: 'class' or property names
     */
    public static function otherQuestion(string $key, array $fields): self
    {
        $message = "The result's question '{$key}' is not the one passed to get(): it differs in its ".implode(' and its ', $fields).', so the handle is from another decision.';

        if (in_array('options', $fields, true)) {
            $message .= " A choice question's options include each case's description, so one rebuilt with other descriptions (under another locale, say) asks another question: read the result with the handle the decision asked.";
        }

        return new self($message);
    }

    public static function otherType(string $key, string $held, string $expected): self
    {
        return new self("The answer to '{$key}' is a {$held}, not the {$expected} this question expects: it was not part of the decision.");
    }
}
