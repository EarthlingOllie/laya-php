<?php

declare(strict_types=1);

namespace EarthlingOllie\SystemOneClassification\Client;

use EarthlingOllie\SystemOneClassification\Domain\Decision;
use EarthlingOllie\SystemOneClassification\Exceptions\HostUnavailable;
use EarthlingOllie\SystemOneClassification\Exceptions\InvalidDecision;
use EarthlingOllie\SystemOneClassification\Exceptions\MalformedResponse;
use EarthlingOllie\SystemOneClassification\Exceptions\RejectedByModel;
use EarthlingOllie\SystemOneClassification\Protocol\SystemOneCodec;
use EarthlingOllie\SystemOneClassification\Result\Health;
use EarthlingOllie\SystemOneClassification\Result\Result;
use EarthlingOllie\SystemOneClassification\Transport\Transport;

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
}
