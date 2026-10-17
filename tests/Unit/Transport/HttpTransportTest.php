<?php

declare(strict_types=1);

use EarthlingOllie\SystemOneClassification\Exceptions\HostUnavailable;
use EarthlingOllie\SystemOneClassification\Transport\HttpTransport;
use EarthlingOllie\SystemOneClassification\Transport\Response;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;

it('posts the body as JSON to the path under the base URL and returns status and raw body', function (): void {
    $http = new Factory;
    $http->fake(['*' => Factory::response('{"answers":{}}', 200)]);

    $response = (new HttpTransport($http, 'http://laya.test:8000'))->post('/v1/systemone', ['state' => 'x', 'questions' => []]);

    expect($response)->toEqual(new Response(200, '{"answers":{}}'));
    $http->assertSent(fn (Request $request): bool => $request->url() === 'http://laya.test:8000/v1/systemone'
        && $request->method() === 'POST'
        && $request->body() === '{"state":"x","questions":[]}'
        && $request->hasHeader('Content-Type', 'application/json')
        && $request->hasHeader('Accept', 'application/json')
        && ! $request->hasHeader('Authorization'));
});

it('joins a base URL with a trailing slash or a path prefix', function (string $base, string $url): void {
    $http = new Factory;
    $http->fake(['*' => Factory::response('{}')]);

    (new HttpTransport($http, $base))->post('/v1/systemone', []);

    $http->assertSent(fn (Request $request): bool => $request->url() === $url);
})->with([
    ['http://laya.test/', 'http://laya.test/v1/systemone'],
    ['https://proxy.test/laya', 'https://proxy.test/laya/v1/systemone'],
]);

it('sends the API key as a bearer token when one is configured', function (): void {
    $http = new Factory;
    $http->fake(['*' => Factory::response('{}')]);

    (new HttpTransport($http, 'http://laya.test', 'secret-key'))->post('/v1/systemone', []);

    $http->assertSent(fn (Request $request): bool => $request->hasHeader('Authorization', 'Bearer secret-key'));
});

it('sends no Authorization header for an empty key', function (): void {
    $http = new Factory;
    $http->fake(['*' => Factory::response('{}')]);

    (new HttpTransport($http, 'http://laya.test', ''))->post('/v1/systemone', []);

    $http->assertSent(fn (Request $request): bool => ! $request->hasHeader('Authorization'));
});

it('returns error statuses as responses instead of throwing', function (int $status): void {
    $http = new Factory;
    $http->fake(['*' => Factory::response('{"detail":"no"}', $status)]);

    expect((new HttpTransport($http, 'http://laya.test'))->post('/v1/systemone', []))
        ->toEqual(new Response($status, '{"detail":"no"}'));
})->with([401, 422, 500, 503]);

it('raises HostUnavailable when the server cannot be reached', function (): void {
    $http = new Factory;
    $http->fake(['*' => Factory::failedConnection('Connection refused')]);

    expect(fn () => (new HttpTransport($http, 'http://laya.test:8000'))->post('/v1/systemone', []))
        ->toThrow(HostUnavailable::class, 'The model server at http://laya.test:8000 could not be reached: Connection refused');
});

it('keeps the URL credentials, query secrets and API key out of HostUnavailable', function (): void {
    $http = new Factory;
    // Guzzle 7 appends the request URI, query string included, to a connection error.
    $http->fake(['*' => Factory::failedConnection('cURL error 7: refused for http://***@laya.test/laya?api_key=q-secret/v1/systemone (key sk-bearer)')]);
    $transport = new HttpTransport($http, 'http://ollie:s3cret-pass@laya.test/laya?api_key=q-secret', 'sk-bearer');

    try {
        $transport->post('/v1/systemone', []);
        $this->fail('Expected HostUnavailable.');
    } catch (HostUnavailable $unavailable) {
        expect($unavailable->getMessage())->toBe('The model server at http://***@laya.test/laya?api_key=*** could not be reached: cURL error 7: refused for http://***@laya.test/laya?api_key=*** (key ***)')
            ->and($unavailable->getPrevious())->toBeNull()
            ->and((string) $unavailable)->not->toContain('s3cret-pass')->not->toContain('q-secret')->not->toContain('sk-bearer')->not->toContain('ollie');
    }
});

it('raises HostUnavailable for a real refused connection, with no secret anywhere in it', function (): void {
    // Port 9 (discard) on loopback: nothing listens there in a test environment.
    $transport = new HttpTransport(new Factory, 'http://ollie:s3cret-pass@127.0.0.1:9/laya?api_key=q-secret', 'sk-bearer', 2.0);

    try {
        $transport->post('/v1/systemone', []);
        $this->fail('Expected HostUnavailable.');
    } catch (HostUnavailable $unavailable) {
        expect($unavailable->status)->toBeNull()
            ->and($unavailable->getMessage())->toStartWith('The model server at http://***@127.0.0.1:9/laya?api_key=*** could not be reached: cURL error 7: ')
            ->and($unavailable->getPrevious())->toBeNull()
            ->and((string) $unavailable)->not->toContain('s3cret-pass')->not->toContain('q-secret')->not->toContain('sk-bearer')->not->toContain('ollie');
    }
});

it('applies the configured timeout', function (): void {
    $http = new Factory;
    $options = null;
    $http->fake(function (Request $request, array $sent) use (&$options) {
        $options = $sent;

        return Factory::response('{}');
    });

    (new HttpTransport($http, 'http://laya.test', timeout: 12.5))->post('/v1/systemone', []);

    expect($options)->toBeArray()->toHaveKey('timeout', 12.5);
});

it('defaults to a 30 second timeout', function (): void {
    $http = new Factory;
    $options = null;
    $http->fake(function (Request $request, array $sent) use (&$options) {
        $options = $sent;

        return Factory::response('{}');
    });

    (new HttpTransport($http, 'http://laya.test'))->post('/v1/systemone', []);

    expect($options)->toBeArray()->toHaveKey('timeout', 30.0);
});

it('gets a path under the base URL as JSON and returns status and raw body', function (): void {
    $http = new Factory;
    $http->fake(['*' => Factory::response('{"status":"ok"}', 200)]);

    $response = (new HttpTransport($http, 'https://proxy.test/laya/', 'secret-key'))->get('/health');

    expect($response)->toEqual(new Response(200, '{"status":"ok"}'));
    $http->assertSent(fn (Request $request): bool => $request->url() === 'https://proxy.test/laya/health'
        && $request->method() === 'GET'
        && $request->body() === ''
        && $request->hasHeader('Accept', 'application/json')
        && $request->hasHeader('Authorization', 'Bearer secret-key'));
});

it('gets with the configured timeout and no Authorization header without a key', function (): void {
    $http = new Factory;
    $options = null;
    $http->fake(function (Request $request, array $sent) use (&$options) {
        $options = $sent;

        return Factory::response('{}', 503);
    });

    $response = (new HttpTransport($http, 'http://laya.test', '', 4.5))->get('/health');

    expect($response->status)->toBe(503)
        ->and($options)->toBeArray()->toHaveKey('timeout', 4.5);
    $http->assertSent(fn (Request $request): bool => ! $request->hasHeader('Authorization'));
});

it('raises a redacted HostUnavailable when a GET cannot reach the server', function (): void {
    $http = new Factory;
    $http->fake(['*' => Factory::failedConnection('cURL error 7: refused (key sk-bearer)')]);

    expect(fn () => (new HttpTransport($http, 'http://ollie:pw@laya.test:8000', 'sk-bearer'))->get('/health'))
        ->toThrow(HostUnavailable::class, 'The model server at http://***@laya.test:8000 could not be reached: cURL error 7: refused (key ***)');
});
