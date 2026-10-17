<?php

declare(strict_types=1);

namespace EarthlingOllie\SystemOneClassification\Tests\Support;

use JsonSchema\Constraints\Constraint;
use JsonSchema\Constraints\Factory;
use JsonSchema\SchemaStorage;
use JsonSchema\Validator;
use RuntimeException;
use stdClass;

use function file_get_contents;
use function is_array;
use function is_file;
use function is_string;
use function json_decode;
use function json_encode;

/**
 * The request schema of Jev's POST /v1/systemone, out of its saved OpenAPI document, that the
 * Contract suite checks every encoded request body against.
 *
 * FETCHED is where bin/fetch-jev-openapi saves TypeSafe's document. Until it has been fetched
 * (api.typesafe.ai was unreachable wherever this package was built so far), the PROVISIONAL
 * stand-in next to it is used instead: written by hand from the protocol laya-ai.com documents
 * and laya-serve accepted, and marked `x-provisional` so isProvisional() can say so. Fetching
 * the real document switches every contract test over to it: the bodies the codec encodes
 * must then match Jev's own schema, and the bodies the tests expect it to refuse must still be
 * refused (only the stand-in's error wording is not checked against it).
 */
final readonly class JevSchema
{
    public const string FETCHED = __DIR__.'/../Fixtures/jev/openapi.json';

    public const string PROVISIONAL = __DIR__.'/../Fixtures/jev/provisional/openapi.json';

    private const string URI = 'file://jev-openapi';

    /** The request body schema, as a JSON pointer into the document. */
    private const string REQUEST = '#/paths/~1v1~1systemone/post/requestBody/content/application~1json/schema';

    public static function path(): string
    {
        return is_file(self::FETCHED) ? self::FETCHED : self::PROVISIONAL;
    }

    /** Whether the document in use is the hand-written stand-in, not TypeSafe's own. */
    public static function isProvisional(): bool
    {
        return self::at(['info', 'x-provisional']) === true;
    }

    /** The document in use, read once per path for the whole run. */
    public static function document(): stdClass
    {
        /** @var array<string, stdClass> $documents */
        static $documents = [];
        $path = self::path();

        if (! isset($documents[$path])) {
            $json = file_get_contents($path);
            $document = is_string($json) ? json_decode($json) : null;
            $documents[$path] = $document instanceof stdClass ? $document : throw new RuntimeException($path.' is not a JSON object.');
        }

        return $documents[$path];
    }

    /**
     * Why the body does not match the request schema: one "path: message" line per error, none
     * when it matches.
     *
     * @param  array<string, mixed>  $body  as SystemOneCodec::encode() returns it
     * @return list<string>
     */
    public static function errors(array $body): array
    {
        $document = self::document();

        if (self::at(['paths', '/v1/systemone', 'post', 'requestBody', 'content', 'application/json', 'schema']) === null) {
            throw new RuntimeException(self::path().' has no application/json request body schema for POST /v1/systemone.');
        }

        $storage = new SchemaStorage;
        $storage->addSchema(self::URI, $document);
        $validator = new Validator(new Factory($storage));
        // Encoded and decoded as the server will read it: criteria objects stay objects.
        $data = json_decode(json_encode($body, JSON_THROW_ON_ERROR));
        $validator->validate($data, (object) ['$schema' => 'http://json-schema.org/draft-07/schema#', '$ref' => self::URI.self::REQUEST], Constraint::CHECK_MODE_NORMAL);

        $errors = [];

        foreach ($validator->getErrors() as $error) {
            $property = is_array($error) ? ($error['property'] ?? '') : '';
            $message = is_array($error) ? ($error['message'] ?? '') : '';
            $errors[] = (is_string($property) ? $property : '').': '.(is_string($message) ? $message : '');
        }

        return $errors;
    }

    /**
     * The value at a path of keys into the document, or null when there is none.
     *
     * @param  list<string>  $keys
     */
    private static function at(array $keys): mixed
    {
        $value = self::document();

        foreach ($keys as $key) {
            $value = $value instanceof stdClass ? ($value->{$key} ?? null) : null;
        }

        return $value;
    }
}
