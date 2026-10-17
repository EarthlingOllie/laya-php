<?php

declare(strict_types=1);

namespace EarthlingOllie\SystemOneClassification\Client;

use EarthlingOllie\SystemOneClassification\Domain\Context;
use EarthlingOllie\SystemOneClassification\Domain\Decision;
use EarthlingOllie\SystemOneClassification\Exceptions\HostUnavailable;
use EarthlingOllie\SystemOneClassification\Exceptions\InvalidDecision;
use EarthlingOllie\SystemOneClassification\Exceptions\MalformedResponse;
use EarthlingOllie\SystemOneClassification\Exceptions\RejectedByModel;
use EarthlingOllie\SystemOneClassification\Exceptions\SystemOneException;
use EarthlingOllie\SystemOneClassification\Protocol\SystemOneCodec;
use EarthlingOllie\SystemOneClassification\Result\Health;
use EarthlingOllie\SystemOneClassification\Result\Result;
use EarthlingOllie\SystemOneClassification\Result\ResultSet;
use EarthlingOllie\SystemOneClassification\Result\Usage;
use EarthlingOllie\SystemOneClassification\Transport\Response;
use EarthlingOllie\SystemOneClassification\Transport\Transport;

use function array_column;
use function array_is_list;
use function array_key_exists;
use function array_keys;
use function array_map;
use function array_slice;
use function count;
use function hash;
use function in_array;
use function serialize;

/** Sends decisions to one host and returns typed results. One client per connection. */
final readonly class DecisionClient implements Decider
{
    /**
     * @param  ?string  $model  sent as `model` with every decision that pins none; null lets the server route
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
     * Asks the decision's questions, with its request settings, about each context, through the
     * batch endpoint. The decision's own context is not sent; Decision::withContext() gives the
     * decision each context is asked as.
     *
     * More than SystemOneCodec::MAX_BATCH_STATES contexts, or contexts too long for one
     * SystemOneCodec::MAX_BATCH_BODY_BYTES body, go out as several requests, one after another; the results come back in the order of the contexts either way. Every request is
     * encoded before the first is sent. If a request fails, its exception is raised and no later
     * request is sent.
     *
     * Each context counts as one decision, as the testing fake records it: the observer is told
     * about each (made, or failed with the batch's exception; a batch refused before it has
     * contexts to ask about fails once, as the decision itself). With a cache, the server's
     * revisions are read once, each context is keyed exactly as decide() keys it (a batch body
     * is decide()'s with `state` swapped for `states`), so both share entries; only the misses
     * are sent, and each answer is stored as decide() stores its response, as soon as its
     * request answers: when a later request fails, asking again sends only what is left. The
     * call itself still fails, so every context is reported failed: the caller gets no results.
     * The set's usage counts the requests sent, so a batch answered from the cache alone used
     * none.
     *
     * @param  list<Context>  $contexts
     *
     * @throws InvalidDecision before anything is sent: no contexts, not a list of Context, or a
     *                         decision decide() would refuse
     * @throws HostUnavailable
     * @throws RejectedByModel
     * @throws MalformedResponse
     */
    public function decideMany(array $contexts, Decision $decision): ResultSet
    {
        try {
            $batches = $this->codec->encodeBatches($decision, $contexts, $this->model);
            [$results, $cached] = $this->cache === null
                ? [$this->sendBatches($decision, $batches), []]
                : $this->resolveManyCached($this->cache, $decision, $contexts, $batches);
        } catch (SystemOneException $exception) {
            foreach (self::asked($contexts, $decision) as $asked) {
                $this->observer?->failed($asked, $exception);
            }

            throw $exception;
        }

        foreach (self::asked($contexts, $decision) as $position => $asked) {
            $this->observer?->decided($asked, $results[$position], $cached[$position] ?? false);
        }

        return $results;
    }

    /**
     * The server's own report: whether it is up and which checkpoints it has loaded.
     *
     * @throws HostUnavailable when it cannot be reached or answers with a 5xx
     * @throws RejectedByModel on a 4xx (a server without laya-serve's `/health`, for example)
     * @throws MalformedResponse
     */
    public function health(): Health
    {
        return $this->codec->decodeHealth($this->transport->get(SystemOneCodec::HEALTH_PATH));
    }

    /**
     * The decision asked about each context, or, for contexts encodeBatches() refused as a
     * batch (none, or not a list of Context), the decision itself.
     *
     * @param  array<mixed>  $contexts
     * @return non-empty-list<Decision>
     */
    private static function asked(array $contexts, Decision $decision): array
    {
        $asked = [];

        foreach ($contexts as $context) {
            if (! $context instanceof Context) {
                return [$decision];
            }

            $asked[] = $decision->withContext($context);
        }

        return $asked === [] || ! array_is_list($contexts) ? [$decision] : $asked;
    }

    /**
     * Sends the batch requests, one after another.
     *
     * @param  non-empty-list<array{states: non-empty-list<string|array<string, mixed>>, questions: array<string, array<string, mixed>>}>  $batches
     *
     * @throws SystemOneException
     */
    private function sendBatches(Decision $decision, array $batches): ResultSet
    {
        $results = new ResultSet([], new Usage(0, 0));

        foreach ($batches as $body) {
            $results = $results->concat($this->sendBatch($decision, $body)[0]);
        }

        return $results;
    }

    /**
     * Sends one batch request, measuring its round trip for its results' meta.
     *
     * @param  array{states: non-empty-list<string|array<string, mixed>>, questions: array<string, array<string, mixed>>}  $body
     * @return array{ResultSet, Response} the results and the response they were decoded from
     *
     * @throws SystemOneException
     */
    private function sendBatch(Decision $decision, array $body): array
    {
        $started = $this->clock->now();
        $response = $this->transport->post(SystemOneCodec::BATCH_PATH, $body);
        $latencyMs = ($this->clock->now() - $started) * 1000;

        return [$this->codec->decodeBatch($decision, count($body['states']), $response, $latencyMs), $response];
    }

    /**
     * @param  list<Context>  $contexts  as encodeBatches() accepted them
     * @param  non-empty-list<array{states: non-empty-list<string|array<string, mixed>>, questions: array<string, array<string, mixed>>}>  $batches  what encodeBatches() made of them
     * @return array{ResultSet, list<bool>} the results, and for each position whether it came from the cache
     *
     * @throws SystemOneException
     */
    private function resolveManyCached(ResponseCache $cache, Decision $decision, array $contexts, array $batches): array
    {
        $revisions = $this->revisions($cache);

        if ($revisions === null) {
            return [$this->sendBatches($decision, $batches), []];
        }

        $hits = [];
        $missing = [];

        foreach ($contexts as $context) {
            $asked = $decision->withContext($context);
            $key = self::key($revisions, $this->codec->encode($asked, $this->model));
            $hit = $this->hit($cache, $asked, $key);
            $hits[] = $hit;

            if ($hit === null) {
                $missing[] = ['context' => $context, 'key' => $key];
            }
        }

        // Nothing sent, no tokens used.
        $sent = new ResultSet([], new Usage(0, 0));

        if ($missing !== []) {
            // Each request's answers are stored as soon as they decode, so a later request that
            // fails does not lose the ones already paid for: asking again sends only the rest.
            foreach ($this->codec->encodeBatches($decision, array_column($missing, 'context'), $this->model) as $body) {
                [$answered, $response] = $this->sendBatch($decision, $body);
                $entries = $this->codec->batchEntries($response);
                $stored = count($sent);
                $sent = $sent->concat($answered);

                // decodeBatch() checked there is one result, and so one entry, per context sent.
                array_map(
                    fn (array $miss, Response $entry, int $index) => $this->store($cache, $revisions, $miss['key'], $answered[$index], $entry),
                    array_slice($missing, $stored, count($entries)),
                    $entries,
                    array_keys($entries),
                );
            }
        }

        // The misses' results come back in the order they were sent: the order of the contexts.
        $results = [];
        $next = 0;

        foreach ($hits as $hit) {
            $results[] = $hit ?? $sent[$next++];
        }

        return [new ResultSet($results, $sent->usage), array_map(static fn (?Result $hit): bool => $hit !== null, $hits)];
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

        $key = self::key($revisions, $body);
        $hit = $this->hit($cache, $decision, $key);

        if ($hit !== null) {
            return [$hit, true];
        }

        [$result, $response] = $this->send($decision, $body);
        $this->store($cache, $revisions, $key, $result, $response);

        return [$result, false];
    }

    /**
     * @param  non-empty-array<array-key, string>  $revisions
     * @param  array<string, mixed>  $body  the encoded single-call request
     */
    private static function key(array $revisions, array $body): string
    {
        // serialize(), not json_encode(): it takes any bytes, so the key never fails a decision,
        // and it keeps every value exactly (floats round-trip, strings byte for byte).
        return hash('sha256', serialize(['revisions' => $revisions, 'request' => $body]));
    }

    /** The cached result for the key, or null on a miss or an entry that does not decode (reported). */
    private function hit(ResponseCache $cache, Decision $decision, string $key): ?Result
    {
        $hit = $cache->get($key);

        if ($hit === null) {
            return null;
        }

        try {
            return $this->codec->decode($decision, $hit);
        } catch (SystemOneException $unreadable) {
            $cache->skipped($unreadable);

            return null;
        }
    }

    /**
     * Keeps the response only when the checkpoint that answered is one the key holds a revision
     * for: a server that loads checkpoints lazily may route to one it had not loaded when
     * /health was read.
     *
     * @param  non-empty-array<array-key, string>  $revisions
     */
    private function store(ResponseCache $cache, array $revisions, string $key, Result $result, Response $response): void
    {
        if (array_key_exists($result->meta->checkpoint ?? '', $revisions)) {
            $cache->put($key, $response);
        }
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
     * The probe is sent once (Transport::get()). When it cannot reach the server, or its answer
     * is refused or unreadable, the cache is skipped for this decision (and the reason reported):
     * the decision is then sent with the connection's retries, which decide whether the host is
     * really down.
     *
     * @return ?non-empty-array<array-key, string> null when the cache is skipped for this decision
     */
    private function revisions(ResponseCache $cache): ?array
    {
        try {
            $revisions = $this->codec->decodeRevisions($this->transport->get(SystemOneCodec::HEALTH_PATH));
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
