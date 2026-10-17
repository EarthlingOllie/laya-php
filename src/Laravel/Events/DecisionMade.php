<?php

declare(strict_types=1);

namespace EarthlingOllie\SystemOneClassification\Laravel\Events;

use EarthlingOllie\SystemOneClassification\Result\Meta;
use EarthlingOllie\SystemOneClassification\Result\Result;

/**
 * A decision was answered, by the server or from the result cache. For metrics and alerting.
 *
 * It never carries the API key, and carries the context only when
 * `system-one.events.include_context` is on, since the context may hold personal data.
 */
final readonly class DecisionMade
{
    /**
     * @param  string  $connection  the configured connection the decision went through
     * @param  ?string  $model  the model asked: the decision's pinned one, else the connection's default; null when the server routes
     * @param  list<non-empty-string>  $questionKeys  the keys of the questions asked, in order
     * @param  Meta  $meta  the result's metadata: model, routed checkpoint, routing reason, tokens, latency
     * @param  ?float  $latencyMs  the round trip's latency; null when the result came from the cache
     * @param  bool  $cached  whether the result came from the cache instead of the server
     * @param  string|array<string, mixed>|null  $context  the context text or fields, only when included
     */
    public function __construct(
        public string $connection,
        public ?string $model,
        public array $questionKeys,
        public Result $result,
        public Meta $meta,
        public ?float $latencyMs,
        public bool $cached,
        public string|array|null $context = null,
    ) {}
}
