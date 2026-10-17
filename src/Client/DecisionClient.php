<?php

declare(strict_types=1);

namespace EarthlingOllie\SystemOneClassification\Client;

use EarthlingOllie\SystemOneClassification\Domain\Context;
use EarthlingOllie\SystemOneClassification\Domain\Decision;
use EarthlingOllie\SystemOneClassification\Exceptions\HostUnavailable;
use EarthlingOllie\SystemOneClassification\Exceptions\InvalidDecision;
use EarthlingOllie\SystemOneClassification\Exceptions\MalformedResponse;
use EarthlingOllie\SystemOneClassification\Exceptions\RejectedByModel;
use EarthlingOllie\SystemOneClassification\Protocol\SystemOneCodec;
use EarthlingOllie\SystemOneClassification\Result\Result;
use EarthlingOllie\SystemOneClassification\Result\ResultSet;
use EarthlingOllie\SystemOneClassification\Result\Usage;
use EarthlingOllie\SystemOneClassification\Transport\Transport;

use function count;

/** Sends decisions to one host and returns typed results. One client per connection. */
final readonly class DecisionClient
{
    public function __construct(
        private Transport $transport,
        private SystemOneCodec $codec,
    ) {}

    /**
     * @throws InvalidDecision before anything is sent
     * @throws HostUnavailable
     * @throws RejectedByModel
     * @throws MalformedResponse
     */
    public function decide(Decision $decision): Result
    {
        $body = $this->codec->encode($decision);

        return $this->codec->decode($decision, $this->transport->post(SystemOneCodec::PATH, $body));
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
        $results = new ResultSet([], new Usage(0, 0));

        foreach ($this->codec->encodeBatches($decision, $contexts) as $body) {
            $response = $this->transport->post(SystemOneCodec::BATCH_PATH, $body);
            $results = $results->concat($this->codec->decodeBatch($decision, count($body['states']), $response));
        }

        return $results;
    }
}
