<?php

declare(strict_types=1);

namespace EarthlingOllie\SystemOneClassification\Transport;

use EarthlingOllie\SystemOneClassification\Exceptions\HostUnavailable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;

/** POSTs JSON to one System One host (laya-serve, hosted Laya or Jev) with Laravel's HTTP client. */
final readonly class HttpTransport implements Transport
{
    public function __construct(
        private Factory $http,
        private string $baseUrl,
        private ?string $apiKey = null,
        private float $timeout = 30.0,
    ) {}

    public function post(string $path, array $body): Response
    {
        $request = $this->http->createPendingRequest()
            ->baseUrl($this->baseUrl)
            ->acceptJson()
            ->asJson()
            ->timeout($this->timeout);

        if ($this->apiKey !== null && $this->apiKey !== '') {
            $request = $request->withToken($this->apiKey);
        }

        try {
            $response = $request->post($path, $body);
        } catch (ConnectionException $exception) {
            $redactor = Redactor::forConnection($this->baseUrl, $this->apiKey);

            throw HostUnavailable::unreachable($redactor->redact($this->baseUrl), $redactor->redact($exception->getMessage()));
        }

        return new Response($response->status(), $response->body());
    }
}
