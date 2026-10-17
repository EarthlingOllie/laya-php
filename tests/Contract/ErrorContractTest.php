<?php

declare(strict_types=1);

use EarthlingOllie\SystemOneClassification\Domain\Context;
use EarthlingOllie\SystemOneClassification\Domain\Decision;
use EarthlingOllie\SystemOneClassification\Domain\Question;
use EarthlingOllie\SystemOneClassification\Exceptions\RejectedByModel;
use EarthlingOllie\SystemOneClassification\Protocol\SystemOneCodec;
use EarthlingOllie\SystemOneClassification\Tests\Support\LayaFixtures;
use EarthlingOllie\SystemOneClassification\Transport\Response;

/*
 * The errors a real laya-serve gave (401, 413, 422) are each a RejectedByModel carrying the
 * server's own message, as recorded.
 */

it('carries the reason of a refused size or a refused question', function (int $status, string $scenario): void {
    $recorded = LayaFixtures::responseBody($scenario);

    try {
        (new SystemOneCodec)->decode(Decision::about(Context::text('x'))->ask(Question::yesNo('churn', 'Cancel?')), new Response($status, json_encode($recorded, JSON_THROW_ON_ERROR)));
        test()->fail('Expected RejectedByModel.');
    } catch (RejectedByModel $rejected) {
        expect($rejected->status)->toBe($status)
            ->and($rejected->detail)->toBe($recorded['detail']);
    }
})->with([
    [413, 'too_many_options'],
    [422, 'invalid_min_confidence'],
    [422, 'unknown_model'],
    [401, 'unauthorized_bad_key'],
    [401, 'unauthorized_missing_key'],
]);
