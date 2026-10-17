<?php

declare(strict_types=1);

namespace EarthlingOllie\SystemOneClassification\Client;

use EarthlingOllie\SystemOneClassification\Domain\Decision;
use EarthlingOllie\SystemOneClassification\Exceptions\HostUnavailable;
use EarthlingOllie\SystemOneClassification\Exceptions\InvalidDecision;
use EarthlingOllie\SystemOneClassification\Exceptions\MalformedResponse;
use EarthlingOllie\SystemOneClassification\Exceptions\RejectedByModel;
use EarthlingOllie\SystemOneClassification\Protocol\SystemOneCodec;
use EarthlingOllie\SystemOneClassification\Result\Result;
use EarthlingOllie\SystemOneClassification\Transport\Transport;

/** Sends decisions to one host and returns typed results. One client per connection. */
final readonly class DecisionClient
{
    public function __construct(
        private Transport $transport,
        private SystemOneCodec $codec,
        private Clock $clock = new SystemClock,
    ) {}

    /**
     * The result's meta carries the latency of the transport round trip only, not encoding or decoding.
     *
     * @throws InvalidDecision before anything is sent
     * @throws HostUnavailable
     * @throws RejectedByModel
     * @throws MalformedResponse
     */
    public function decide(Decision $decision): Result
    {
        $body = $this->codec->encode($decision);

        $started = $this->clock->now();
        $response = $this->transport->post(SystemOneCodec::PATH, $body);
        $latencyMs = ($this->clock->now() - $started) * 1000;

        return $this->codec->decode($decision, $response, $latencyMs);
    }
}
