<?php

declare(strict_types=1);

namespace EarthlingOllie\SystemOneClassification\Laravel\Events;

use EarthlingOllie\SystemOneClassification\Exceptions\SystemOneException;

/**
 * A decision failed with one of the package's typed exceptions, which is then thrown to the
 * caller. Dispatched for decisions refused before sending (InvalidDecision) too.
 *
 * It never carries the API key (exception messages are redacted), and carries the context only
 * when `system-one.events.include_context` is on.
 */
final readonly class DecisionFailed
{
    /**
     * @param  string  $connection  the configured connection the decision went through
     * @param  ?string  $model  the model asked: the decision's pinned one, else the connection's default; null when the server routes
     * @param  list<non-empty-string>  $questionKeys  the keys of the questions asked, in order
     * @param  string|array<string, mixed>|null  $context  the context text or fields, only when included
     */
    public function __construct(
        public string $connection,
        public ?string $model,
        public array $questionKeys,
        public SystemOneException $exception,
        public string|array|null $context = null,
    ) {}
}
