<?php

declare(strict_types=1);

namespace EarthlingOllie\SystemOneClassification\Transport;

use Closure;
use EarthlingOllie\SystemOneClassification\Exceptions\HostUnavailable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response as HttpResponse;

/** Talks JSON to one System One host (laya-serve, hosted Laya or Jev) with Laravel's HTTP client. */
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
        return $this->send(fn (PendingRequest $request): HttpResponse => $request->asJson()->post($path, $body));
    }

    public function get(string $path): Response
    {
        return $this->send(fn (PendingRequest $request): HttpResponse => $request->get($path));
    }

    /**
     * @param  Closure(PendingRequest): HttpResponse  $call
     */
    private function send(Closure $call): Response
    {
        $request = $this->http->createPendingRequest()
            ->baseUrl($this->baseUrl)
            ->acceptJson()
            ->timeout($this->timeout);

        if ($this->apiKey !== null && $this->apiKey !== '') {
            $request = $request->withToken($this->apiKey);
        }

        try {
            $response = $call($request);
        } catch (ConnectionException $exception) {
            $redactor = Redactor::forConnection($this->baseUrl, $this->apiKey);

            throw HostUnavailable::unreachable($redactor->redact($this->baseUrl), $redactor->redact($exception->getMessage()));
        }

        return new Response($response->status(), $response->body());
    }
}
