<?php

declare(strict_types=1);

namespace EarthlingOllie\SystemOneClassification\Transport;

use EarthlingOllie\SystemOneClassification\Exceptions\HostUnavailable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use SensitiveParameter;
use SensitiveParameterValue;

use function count;
use function filter_var;
use function is_string;
use function max;
use function min;

/**
 * POSTs JSON to one System One host (laya-serve, hosted Laya or Jev) with Laravel's HTTP client.
 *
 * When the host cannot be reached or answers with a 5xx, the request is sent again as the
 * RetryPolicy says, waiting its backoff delays in between; a 4xx is returned at once.
 *
 * The API key goes out only as the bearer token. It is held as a SensitiveParameterValue, so
 * var_dump(), print_r(), var_export() and Laravel's dump() of the transport (or of the client
 * around it) never show it, serialize() refuses it, and a stack trace never prints it. The key
 * and the URL's secrets are masked in the body of any response that is not a 2xx, since a
 * server may echo them into the message that reaches an exception.
 */
final readonly class HttpTransport implements Transport
{
    private ?SensitiveParameterValue $apiKey;

    /** @param  ?string  $apiKey  sent as the bearer token; null or empty sends none */
    public function __construct(
        private Factory $http,
        private string $baseUrl,
        #[SensitiveParameter] ?string $apiKey = null,
        private float $timeout = 30.0,
        private RetryPolicy $retry = new RetryPolicy,
        private Sleeper $sleeper = new SystemSleeper,
    ) {
        $this->apiKey = $apiKey === null || $apiKey === '' ? null : new SensitiveParameterValue($apiKey);
    }

    public function post(string $path, array $body): Response
    {
        $delays = $this->retry->delays();

        foreach ($delays as $retry => $delay) {
            $outcome = $this->send($path, $body, $retry + 1);

            if ($outcome instanceof Response && $outcome->status < 500) {
                return $outcome;
            }

            $wait = $delay;

            if ($outcome instanceof Response && $outcome->retryAfter !== null) {
                // A busy server's Retry-After wins over a shorter backoff, within the same cap.
                $wait = min(max($delay, $outcome->retryAfter * 1000), RetryPolicy::MAX_DELAY_MILLISECONDS);
            }

            $this->sleeper->sleep($wait * 1000);
        }

        $attempts = count($delays) + 1;
        $outcome = $this->send($path, $body, $attempts);

        if ($outcome instanceof Response) {
            return $outcome;
        }

        $redactor = $this->redactor();

        throw HostUnavailable::unreachable($redactor->redact($this->baseUrl), $redactor->redact($outcome->getMessage()), $attempts);
    }

    /**
     * One attempt: the response, whatever its status, or why nothing came back.
     *
     * @param  array<string, mixed>  $body
     * @param  int  $attempt  which attempt this is, the first being 1
     */
    private function send(string $path, array $body, int $attempt): Response|ConnectionException
    {
        $request = $this->http->createPendingRequest()
            ->baseUrl($this->baseUrl)
            ->acceptJson()
            ->asJson()
            ->timeout($this->timeout);

        $apiKey = $this->apiKey();

        if ($apiKey !== null) {
            $request = $request->withToken($apiKey);
        }

        try {
            $response = $request->post($path, $body);
        } catch (ConnectionException $exception) {
            return $exception;
        }

        $status = $response->status();
        $successful = $status >= 200 && $status < 300;

        // Only the delay-seconds form; a negative one is outwaited by any backoff anyway.
        $retryAfter = filter_var($response->header('Retry-After'), FILTER_VALIDATE_INT);

        return new Response(
            $status,
            $successful ? $response->body() : $this->redactor()->redactBody($response->body()),
            $attempt,
            $retryAfter === false ? null : $retryAfter,
        );
    }

    private function apiKey(): ?string
    {
        $apiKey = $this->apiKey?->getValue();

        return is_string($apiKey) ? $apiKey : null;
    }

    private function redactor(): Redactor
    {
        return Redactor::forConnection($this->baseUrl, $this->apiKey());
    }
}
