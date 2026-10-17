<?php

declare(strict_types=1);

use EarthlingOllie\SystemOneClassification\Domain\Context;
use EarthlingOllie\SystemOneClassification\Exceptions\InvalidDecision;

it('holds plain text as the state', function (): void {
    expect(Context::text('We were billed twice.')->state)->toBe('We were billed twice.');
});

it('holds named fields as a structured state, unchanged', function (): void {
    $fields = [
        'subject' => 'Charged twice',
        'body' => 'We were billed twice for March.',
        'customer' => ['plan' => 'pro', 'seats' => 12, 'monthly' => 49.5, 'trial' => false, 'tags' => ['vip', 'eu']],
        'notes' => null,
        'empty' => [],
    ];

    expect(Context::fields($fields)->state)->toBe($fields);
});

it('rejects empty fields', function (): void {
    Context::fields([]);
})->throws(InvalidDecision::class, 'The context fields must not be empty.');

it('rejects fields that are a list rather than named', function (array $fields): void {
    Context::fields($fields);
})->with([
    'list' => [['a', 'b']],
    'integer key' => [['subject' => 'x', 3 => 'y']],
])->throws(InvalidDecision::class, 'The context fields must be named: every top-level key must be a string that is not a whole number');

it('rejects a nested value that is not JSON-serialisable, naming where it is', function (array $fields, string $message): void {
    expect(fn () => Context::fields($fields))->toThrow(InvalidDecision::class, $message);
})->with([
    'object' => [['customer' => new stdClass], "The context field 'customer' holds a stdClass, which is not plain JSON data; pass arrays and scalars only."],
    'nested object' => [['customer' => ['since' => new DateTimeImmutable('2026-01-01')]], "The context field 'customer.since' holds a DateTimeImmutable, which is not plain JSON data; pass arrays and scalars only."],
    'in a list' => [['tags' => ['a', fn () => 1]], "The context field 'tags.1' holds a Closure, which is not plain JSON data; pass arrays and scalars only."],
    'NAN' => [['score' => NAN], "The context field 'score' holds a float that is not finite (NAN), which JSON cannot represent."],
    'INF' => [['deep' => ['score' => -INF]], "The context field 'deep.score' holds a float that is not finite (-INF), which JSON cannot represent."],
    'invalid UTF-8' => [['body' => "caf\xE9"], 'The context fields are not JSON-serialisable: Malformed UTF-8 characters'],
    'invalid UTF-8 key' => [["b\xE9" => 'x'], 'The context fields are not JSON-serialisable: Malformed UTF-8 characters'],
]);

it('rejects a resource', function (): void {
    $stream = fopen('php://memory', 'r');

    try {
        Context::fields(['file' => $stream]);
    } finally {
        fclose($stream);
    }
})->throws(InvalidDecision::class, "The context field 'file' holds a resource (stream), which is not plain JSON data; pass arrays and scalars only.");

it('rejects fields nested deeper than JSON encoding allows', function (): void {
    $deep = 'leaf';
    for ($i = 0; $i < 600; $i++) {
        $deep = ['n' => $deep];
    }

    Context::fields(['deep' => $deep]);
})->throws(InvalidDecision::class, "The context fields are not JSON-serialisable: Maximum stack depth exceeded in the field 'deep', which nests arrays deeper than the 512 levels JSON allows.");

it('accepts fields nested exactly as deep as the request body allows, and refuses one level more', function (): void {
    // JSON allows 512 levels and the fields object is the request body's second, so a field may nest 510 arrays.
    $nest = function (int $levels, mixed $leaf): array {
        for ($i = 0; $i < $levels; $i++) {
            $leaf = ['n' => $leaf];
        }

        return $leaf;
    };

    expect(Context::fields(['deep' => $nest(510, 'leaf')])->state)->toBe(['deep' => $nest(510, 'leaf')])
        // An empty array is a level too.
        ->and(fn () => Context::fields(['deep' => $nest(510, [])]))
        ->toThrow(InvalidDecision::class, "Maximum stack depth exceeded in the field 'deep', which nests arrays deeper than the 512 levels JSON allows.");
});

it('refuses a self-referencing array instead of recursing until PHP crashes', function (): void {
    $loop = ['x' => 1];
    $loop['self'] = &$loop;

    Context::fields(['ok' => 'fine', 'loop' => $loop]);
})->throws(InvalidDecision::class, "The context fields are not JSON-serialisable: Maximum stack depth exceeded in the field 'loop', which nests arrays deeper than the 512 levels JSON allows.");

it('ends the JSON-failure message with a full stop and keeps the JSON error', function (): void {
    try {
        Context::fields(['body' => "caf\xE9"]);
        $this->fail('Expected InvalidDecision.');
    } catch (InvalidDecision $invalid) {
        expect($invalid->getMessage())->toBe('The context fields are not JSON-serialisable: Malformed UTF-8 characters, possibly incorrectly encoded.')
            ->and($invalid->getPrevious())->toBeInstanceOf(JsonException::class);
    }
});

it('accepts text-free but non-empty fields such as numbers and booleans', function (): void {
    expect(Context::fields(['count' => 0, 'flag' => true])->state)->toBe(['count' => 0, 'flag' => true]);
});

it('cannot be changed after construction', function (): void {
    $context = Context::fields(['subject' => 'x']);

    expect(fn () => $context->state = 'y')->toThrow(Error::class);
});

it('copies the fields, so a PHP reference into them cannot change the context afterwards', function (): void {
    $inner = ['x' => 1, 'list' => ['a', ['b' => 'c']]];
    $nested = &$inner['x'];
    $deeper = &$inner['list'][1]['b'];
    $fields = ['n' => $inner, 'top' => 't'];
    $top = &$fields['top'];

    $context = Context::fields($fields);
    $nested = 999;
    $deeper = 'changed';
    $top = 'changed';

    expect($context->state)->toBe(['n' => ['x' => 1, 'list' => ['a', ['b' => 'c']]], 'top' => 't']);
});
