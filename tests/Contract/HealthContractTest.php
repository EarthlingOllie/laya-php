<?php

declare(strict_types=1);

use EarthlingOllie\SystemOneClassification\Domain\Context;
use EarthlingOllie\SystemOneClassification\Domain\Decision;
use EarthlingOllie\SystemOneClassification\Domain\Question;
use EarthlingOllie\SystemOneClassification\Model\LayaProfile;
use EarthlingOllie\SystemOneClassification\Protocol\SystemOneCodec;
use EarthlingOllie\SystemOneClassification\Result\Health;
use EarthlingOllie\SystemOneClassification\Tests\Support\LayaFixtures;

/*
 * What the result cache keys on besides the request: the checkpoint revisions laya-serve
 * reports on GET /health, and the `model` the request names; and the whole health response
 * system-one:health (#11) prints.
 */

it('reads the recorded health response into its checkpoint revisions', function (): void {
    $recorded = LayaFixtures::responseBody('health');
    assert(is_array($recorded['revisions']));

    expect((new SystemOneCodec)->decodeRevisions(LayaFixtures::response('health')))->toBe($recorded['revisions'])
        ->and(SystemOneCodec::HEALTH_PATH)->toBe(LayaFixtures::requestPath('health'));
});

it('encodes a model as the recorded request names it', function (): void {
    $recorded = LayaFixtures::requestBody('unknown_model');
    assert(is_string($recorded['state']) && is_string($recorded['model']));
    $decision = Decision::about(Context::text($recorded['state']))
        ->ask(Question::yesNo('churn', 'Does the customer threaten to cancel or leave?'));

    // A connection's default model counts as one of its models (#9), as the manager builds it.
    expect((new SystemOneCodec(new LayaProfile(extraModels: [$recorded['model']])))->encode($decision, $recorded['model']))->toBe($recorded);
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
