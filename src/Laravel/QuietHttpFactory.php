<?php

declare(strict_types=1);

namespace EarthlingOllie\SystemOneClassification\Laravel;

use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\Response;

/**
 * The application's HTTP client factory, minus its events: what SystemOneManager hands
 * HttpTransport, so the API key never reaches an event (issue #9).
 *
 * Laravel's HTTP client dispatches RequestSending, ResponseReceived and ConnectionFailed with
 * the whole request, Authorization header included, to every listener. Requests made through
 * this factory dispatch none of them (it has no dispatcher), yet keep everything else of the
 * application's factory: its global middleware and options, and Http::fake() stubs, stray
 * request guards and recording, so Http::assertSent() still sees them. The price is that
 * tools built on those events (Telescope's and Pulse's outgoing-request recorders) do not
 * see the package's requests either.
 *
 * It reads the wrapped factory's protected state, which Laravel 13 lays out as below; the
 * Feature tests fail if a Laravel release changes that.
 */
final class QuietHttpFactory extends Factory
{
    /**
     * Factory's own constructor only sets the dispatcher (none here) and an empty set of stubs,
     * which createPendingRequest() replaces with $http's, so leaving the call out changes nothing
     * a test can see (infection.json5 ignores that mutant); it is made in case Laravel adds more.
     */
    public function __construct(private readonly Factory $http)
    {
        parent::__construct();
    }

    /** A request set up as the wrapped factory sets one up, with this factory (and no dispatcher) behind it. */
    public function createPendingRequest(): PendingRequest
    {
        // Taken afresh each time: Http::fake() and the global settings may change between requests.
        $this->globalMiddleware = $this->http->globalMiddleware;
        $this->globalOptions = $this->http->globalOptions;
        $this->stubCallbacks = $this->http->stubCallbacks;
        $this->preventStrayRequests = $this->http->preventStrayRequests;
        $this->allowedStrayRequestUrls = $this->http->allowedStrayRequestUrls;

        return parent::createPendingRequest();
    }

    /**
     * @param  Request  $request
     * @param  Response|null  $response
     */
    public function recordRequestResponsePair($request, $response): void
    {
        $this->http->recordRequestResponsePair($request, $response);
    }
}
