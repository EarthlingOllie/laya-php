<?php

declare(strict_types=1);

use EarthlingOllie\SystemOneClassification\Protocol\SystemOneCodec;
use EarthlingOllie\SystemOneClassification\Result\Health;
use EarthlingOllie\SystemOneClassification\Tests\Support\LayaFixtures;

/*
 * The health decoder against the `GET /health` response recorded from a real laya-serve.
 */

it('reads the recorded health path', function (): void {
    expect(SystemOneCodec::HEALTH_PATH)->toBe(LayaFixtures::requestPath('health'));
});

it('decodes the recorded health response', function (): void {
    $recorded = LayaFixtures::responseBody('health');

    expect((new SystemOneCodec)->decodeHealth(LayaFixtures::response('health')))->toEqual(new Health(
        'ok',
        $recorded['loaded'],
        $recorded['revisions'],
        $recorded['device'],
    ));
});
