<?php

declare(strict_types=1);

namespace EarthlingOllie\SystemOneClassification\Result;

/**
 * What one call reported about itself: the server's `model`, its `routing` and `usage`, and the
 * client-side latency of the round trip.
 *
 * Every field is null when the host did not report it (Jev sends no `routing`, for one).
 */
final readonly class Meta
{
    /**
     * @param  ?string  $model  the root `model` field, such as 'laya-rl-agent'
     * @param  ?string  $checkpoint  `routing.model`: the checkpoint the server routed to, such as 'english'
     * @param  ?string  $routingReason  `routing.reason`, such as 'English Latin text'
     * @param  ?non-negative-int  $inputTokens  `usage.input_tokens`
     * @param  ?non-negative-int  $outputTokens  `usage.output_tokens`
     * @param  ?float  $latencyMs  milliseconds from sending the request to receiving the response, measured by the client
     */
    public function __construct(
        public ?string $model = null,
        public ?string $checkpoint = null,
        public ?string $routingReason = null,
        public ?int $inputTokens = null,
        public ?int $outputTokens = null,
        public ?float $latencyMs = null,
    ) {}
}
