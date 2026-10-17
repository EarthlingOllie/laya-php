<?php

declare(strict_types=1);

namespace EarthlingOllie\SystemOneClassification\Result;

/** What a model server says about itself (laya-serve's `GET /health`). */
final readonly class Health
{
    /**
     * @param  list<string>|null  $loaded  the resident checkpoints; null when the server did not list them
     *                                     (laya-serve lists them only to a request with its API key)
     * @param  array<string, string|null>  $revisions  each loaded checkpoint's revision, null when it has none
     * @param  ?string  $device  where the checkpoints compute (`cpu`, `cuda`, ...), when reported
     */
    public function __construct(
        public string $status,
        public ?array $loaded = null,
        public array $revisions = [],
        public ?string $device = null,
    ) {}

    /** Whether the server reports itself as up. */
    public function isHealthy(): bool
    {
        return $this->status === 'ok';
    }
}
