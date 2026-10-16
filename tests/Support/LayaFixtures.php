<?php

declare(strict_types=1);

namespace EarthlingOllie\SystemOneClassification\Tests\Support;

use EarthlingOllie\SystemOneClassification\Transport\Response;
use RuntimeException;

use function array_key_exists;
use function file_get_contents;
use function is_array;
use function is_file;
use function is_int;
use function is_string;
use function json_decode;
use function json_encode;

/**
 * The recorded laya-serve fixtures the Contract suite reads (bin/laya-record-fixtures).
 *
 * DIRECTORY is the one switch point. It names tests/Fixtures/laya/provisional: answers
 * recorded from the real laya-serve with RANDOM weights (real shape, meaningless values),
 * because the real weights could not be downloaded when they were recorded. Once real
 * fixtures are recorded into tests/Fixtures/laya, point it there and delete provisional/
 * (see its README.md).
 */
final readonly class LayaFixtures
{
    public const string DIRECTORY = __DIR__.'/../Fixtures/laya/provisional';

    /**
     * @return array<string, mixed>
     */
    public static function manifest(): array
    {
        return self::json('manifest.json');
    }

    /** Whether the fixtures in use carry meaningless (random-weight) values. */
    public static function isProvisional(): bool
    {
        return (self::manifest()['weights'] ?? null) === 'random-init, shape only';
    }

    /**
     * The JSON body of the recorded request.
     *
     * @return array<string, mixed>
     */
    public static function requestBody(string $scenario): array
    {
        $body = self::request($scenario)['body'] ?? null;

        return is_array($body) ? self::stringKeys($body) : throw new RuntimeException("Fixture {$scenario} has no request body.");
    }

    public static function requestPath(string $scenario): string
    {
        $path = self::request($scenario)['path'] ?? null;

        return is_string($path) ? $path : throw new RuntimeException("Fixture {$scenario} has no request path.");
    }

    /** The recorded response, re-encoded as the bytes a transport would hand the codec. */
    public static function response(string $scenario): Response
    {
        $response = self::scenario($scenario)['response'] ?? null;

        if (! is_array($response) || ! is_int($response['status'] ?? null) || ! array_key_exists('body', $response)) {
            throw new RuntimeException("Fixture {$scenario} has no response.");
        }

        return new Response($response['status'], json_encode($response['body'], JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));
    }

    /**
     * The decoded response body.
     *
     * @return array<string, mixed>
     */
    public static function responseBody(string $scenario): array
    {
        $response = self::scenario($scenario)['response'] ?? null;
        $body = is_array($response) ? ($response['body'] ?? null) : null;

        return is_array($body) ? self::stringKeys($body) : throw new RuntimeException("Fixture {$scenario} has no JSON response body.");
    }

    /**
     * @return array<string, mixed>
     */
    private static function request(string $scenario): array
    {
        $request = self::scenario($scenario)['request'] ?? null;

        return is_array($request) ? self::stringKeys($request) : throw new RuntimeException("Fixture {$scenario} has no request.");
    }

    /**
     * @return array<string, mixed>
     */
    private static function scenario(string $scenario): array
    {
        return self::json($scenario.'.json');
    }

    /**
     * @return array<string, mixed>
     */
    private static function json(string $file): array
    {
        $path = self::DIRECTORY.'/'.$file;
        $contents = is_file($path) ? file_get_contents($path) : false;

        if ($contents === false) {
            throw new RuntimeException("No fixture file at {$path}.");
        }

        $decoded = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);

        return is_array($decoded) ? self::stringKeys($decoded) : throw new RuntimeException("{$path} is not a JSON object.");
    }

    /**
     * @param  array<mixed>  $array
     * @return array<string, mixed>
     */
    private static function stringKeys(array $array): array
    {
        $keyed = [];

        foreach ($array as $key => $value) {
            $keyed[(string) $key] = $value;
        }

        return $keyed;
    }
}
