<?php

declare(strict_types=1);

use EarthlingOllie\SystemOneClassification\Exceptions\HostUnavailable;
use EarthlingOllie\SystemOneClassification\Exceptions\MalformedResponse;
use EarthlingOllie\SystemOneClassification\Exceptions\RejectedByModel;
use EarthlingOllie\SystemOneClassification\Protocol\SystemOneCodec;
use EarthlingOllie\SystemOneClassification\Result\Health;
use EarthlingOllie\SystemOneClassification\Transport\Response;

/** @param array<mixed> $body */
function healthResponse(array $body, int $status = 200): Response
{
    return new Response($status, json_encode($body, JSON_THROW_ON_ERROR));
}

it('reads the status, loaded checkpoints, revisions and device', function (): void {
    $health = (new SystemOneCodec)->decodeHealth(healthResponse([
        'status' => 'ok',
        'loaded' => ['english', 'multilingual'],
        'revisions' => ['english' => 'abc123', 'multilingual' => null],
        'device' => 'cpu',
        'cpu_fallbacks' => [],
    ]));

    expect($health)->toEqual(new Health('ok', ['english', 'multilingual'], ['english' => 'abc123', 'multilingual' => null], 'cpu'))
        ->and($health->isHealthy())->toBeTrue();
});

it('reads a liveness-only answer as healthy with the checkpoints not reported', function (): void {
    // laya-serve with LAYA_API_KEY set answers a request without the key with the status alone.
    $health = (new SystemOneCodec)->decodeHealth(healthResponse(['status' => 'ok']));

    expect($health)->toEqual(new Health('ok', null, [], null))
        ->and($health->isHealthy())->toBeTrue();
});

it('reads a null loaded list as not reported', function (): void {
    expect((new SystemOneCodec)->decodeHealth(healthResponse(['status' => 'ok', 'loaded' => null]))->loaded)->toBeNull();
});

it('reads any status other than ok as unhealthy', function (string $status): void {
    expect((new SystemOneCodec)->decodeHealth(healthResponse(['status' => $status, 'loaded' => []]))->isHealthy())->toBeFalse();
})->with(['loading', 'error', 'OK', '']);

it('raises HostUnavailable for a 5xx health response', function (): void {
    (new SystemOneCodec)->decodeHealth(healthResponse(['detail' => 'server busy'], 503));
})->throws(HostUnavailable::class, 'The model server is unavailable (HTTP 503): server busy');

it('raises RejectedByModel for a 4xx health response', function (): void {
    (new SystemOneCodec)->decodeHealth(healthResponse(['detail' => 'Not Found'], 404));
})->throws(RejectedByModel::class, 'HTTP 404');

it('raises MalformedResponse for a health body that does not match the expected shape', function (string $body, string $message): void {
    expect(fn () => (new SystemOneCodec)->decodeHealth(new Response(200, $body)))
        ->toThrow(MalformedResponse::class, $message);
})->with([
    'not JSON' => ['<html>', 'The response is not a JSON object.'],
    'a list' => ['[1]', 'The response is not a JSON object.'],
    'no status' => ['{"loaded":[]}', "The health response has no string 'status'."],
    'numeric status' => ['{"status":1}', "The health response has no string 'status'."],
    'loaded not a list' => ['{"status":"ok","loaded":{"a":"english"}}', "The health response's 'loaded' is not a list of checkpoint names."],
    'loaded with a non-string' => ['{"status":"ok","loaded":["english",1]}', "The health response's 'loaded' is not a list of checkpoint names."],
    'revisions a list' => ['{"status":"ok","revisions":["abc"]}', "The health response's 'revisions' is not an object of checkpoint revisions."],
    'revisions a string' => ['{"status":"ok","revisions":"abc"}', "The health response's 'revisions' is not an object of checkpoint revisions."],
    'a numeric revision' => ['{"status":"ok","revisions":{"english":1}}', "The health response's 'revisions' is not an object of checkpoint revisions."],
    'numeric device' => ['{"status":"ok","device":0}', "The health response's 'device' is not a string."],
]);

it('reads empty revisions as none', function (): void {
    // PHP decodes `{}` and `[]` alike; an empty object is no revisions.
    expect((new SystemOneCodec)->decodeHealth(new Response(200, '{"status":"ok","loaded":[],"revisions":{}}'))->revisions)->toBe([]);
});

it('names the health path laya-serve serves', function (): void {
    expect(SystemOneCodec::HEALTH_PATH)->toBe('/health');
});
