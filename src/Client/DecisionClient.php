<?php

declare(strict_types=1);

namespace EarthlingOllie\SystemOneClassification\Client;

use EarthlingOllie\SystemOneClassification\Domain\Decision;
use EarthlingOllie\SystemOneClassification\Exceptions\HostUnavailable;
use EarthlingOllie\SystemOneClassification\Exceptions\InvalidDecision;
use EarthlingOllie\SystemOneClassification\Exceptions\MalformedResponse;
use EarthlingOllie\SystemOneClassification\Exceptions\RejectedByModel;
use EarthlingOllie\SystemOneClassification\Exceptions\SystemOneException;
use EarthlingOllie\SystemOneClassification\Protocol\SystemOneCodec;
use EarthlingOllie\SystemOneClassification\Result\Result;
use EarthlingOllie\SystemOneClassification\Transport\Response;
use EarthlingOllie\SystemOneClassification\Transport\Transport;

use function array_key_exists;
use function hash;
use function in_array;
use function serialize;

/** Sends decisions to one host and returns typed results. One client per connection. */
final readonly class DecisionClient
{
    /**
     * @param  ?string  $model  sent as `model` with every decision; null lets the server route
     * @param  ?ResponseCache  $cache  null sends every decision
     * @param  ?DecisionObserver  $observer  told about every decision made or failed
     */
    public function __construct(
        private Transport $transport,
        private SystemOneCodec $codec,
        private Clock $clock = new SystemClock,
        private ?string $model = null,
        private ?ResponseCache $cache = null,
        private ?DecisionObserver $observer = null,
    ) {}

    /**
     * The result's meta carries the latency of the transport round trip only, not encoding or
     * decoding; a result served from the cache has none.
     *
     * With a cache, the client first reads the server's checkpoint revisions (GET /health) and
     * keys on them together with the whole request body: the context, every question as sent,
     * the request controls and the model. The cache is skipped when the server reports no
     * revision for a loaded checkpoint, since its answers could then change under the same key,
     * and an answer is stored only when the checkpoint it reports (`routing.model`) is keyed.
     *
     * @throws InvalidDecision before anything is sent
     * @throws HostUnavailable
     * @throws RejectedByModel
     * @throws MalformedResponse
     */
    public function decide(Decision $decision): Result
    {
        try {
            [$result, $cached] = $this->resolve($decision);
        } catch (SystemOneException $exception) {
            $this->observer?->failed($decision, $exception);

            throw $exception;
        }

        $this->observer?->decided($decision, $result, $cached);

        return $result;
    }

    /**
     * @return array{Result, bool} the result, and whether it came from the cache
     *
     * @throws SystemOneException
     */
    private function resolve(Decision $decision): array
    {
        $body = $this->codec->encode($decision, $this->model);

        if ($this->cache === null) {
            return [$this->send($decision, $body)[0], false];
        }

        return $this->resolveCached($this->cache, $decision, $body);
    }

    /**
     * @param  array<string, mixed>  $body  the encoded request
     * @return array{Result, bool} the result, and whether it came from the cache
     *
     * @throws SystemOneException
     */
    private function resolveCached(ResponseCache $cache, Decision $decision, array $body): array
    {
        $revisions = $this->revisions($cache);

        if ($revisions === null) {
            return [$this->send($decision, $body)[0], false];
        }

        // serialize(), not json_encode(): it takes any bytes, so the key never fails a decision,
        // and it keeps every value exactly (floats round-trip, strings byte for byte).
        $key = hash('sha256', serialize(['revisions' => $revisions, 'request' => $body]));
        $hit = $cache->get($key);

        if ($hit !== null) {
            try {
                return [$this->codec->decode($decision, $hit), true];
            } catch (SystemOneException $unreadable) {
                $cache->skipped($unreadable);
            }
        }

        [$result, $response] = $this->send($decision, $body);

        // Kept only when the checkpoint that answered is one the key holds a revision for: a server
        // that loads checkpoints lazily may route to one it had not loaded when /health was read.
        if (array_key_exists($result->meta->checkpoint ?? '', $revisions)) {
            $cache->put($key, $response);
        }

        return [$result, false];
    }

    /**
     * @param  array<string, mixed>  $body  the encoded request
     * @return array{Result, Response} the decoded result and the response it was decoded from
     *
     * @throws SystemOneException
     */
    private function send(Decision $decision, array $body): array
    {
        $started = $this->clock->now();
        $response = $this->transport->post(SystemOneCodec::PATH, $body);
        $latencyMs = ($this->clock->now() - $started) * 1000;

        return [$this->codec->decode($decision, $response, $latencyMs), $response];
    }

    /**
     * The checkpoint revisions to key on, read from the server's /health.
     *
     * @return ?non-empty-array<array-key, string> null when the cache is skipped for this decision
     *
     * @throws HostUnavailable when the server cannot be reached, as the decision would fail the same way
     */
    private function revisions(ResponseCache $cache): ?array
    {
        // Only an unreachable server makes the transport throw, and that fails the decision.
        $health = $this->transport->get(SystemOneCodec::HEALTH_PATH);

        try {
            $revisions = $this->codec->decodeRevisions($health);
        } catch (SystemOneException $unreadable) {
            $cache->skipped($unreadable);

            return null;
        }

        if ($revisions === [] || in_array(null, $revisions, true)) {
            return null;
        }

        /** @var non-empty-array<array-key, string> $revisions */
        return $revisions;
    }
}
