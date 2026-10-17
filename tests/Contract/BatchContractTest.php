<?php

declare(strict_types=1);

use EarthlingOllie\SystemOneClassification\Domain\Context;
use EarthlingOllie\SystemOneClassification\Domain\Decision;
use EarthlingOllie\SystemOneClassification\Domain\Question;
use EarthlingOllie\SystemOneClassification\Domain\Question\YesNoQuestion;
use EarthlingOllie\SystemOneClassification\Protocol\SystemOneCodec;
use EarthlingOllie\SystemOneClassification\Result\Usage;
use EarthlingOllie\SystemOneClassification\Tests\Support\LayaFixtures;

/*
 * The batch codec against the `batch` and `batch_below_min_confidence` scenarios recorded from
 * a real laya-serve (see LayaFixtures for which recording is in use).
 *
 * The recorded requests also ask a `choice` question (#4) and send one structured state (#7),
 * which the package cannot build yet. So the encoding is checked against the noul question and
 * the text states of the recording, and the decoding reads the noul answers out of the full
 * recorded responses.
 */

function recordedBatchChurn(): YesNoQuestion
{
    return Question::yesNo('churn', 'Does the customer threaten to cancel or leave?');
}

/** @return list<mixed> */
function recordedBatchResults(string $scenario): array
{
    $results = LayaFixtures::responseBody($scenario)['results'];
    assert(is_array($results) && array_is_list($results));

    return $results;
}

it('encodes the text states and the noul question as the recorded batch request sends them', function (): void {
    $recorded = LayaFixtures::requestBody('batch');
    assert(is_array($recorded['states']) && is_array($recorded['questions']));
    $textStates = array_values(array_filter($recorded['states'], is_string(...)));
    $decision = Decision::about(Context::text('template'))->ask(recordedBatchChurn());

    $bodies = (new SystemOneCodec)->encodeBatches($decision, array_map(Context::text(...), $textStates));

    expect($bodies)->toBe([['states' => $textStates, 'questions' => ['churn' => $recorded['questions']['churn']]]])
        ->and($textStates)->toHaveCount(2)
        ->and(SystemOneCodec::BATCH_PATH)->toBe(LayaFixtures::requestPath('batch'));
});

it('decodes a recorded batch response into one result per state, in order, with its total usage', function (string $scenario, int $states): void {
    $decision = Decision::about(Context::text('template'))->ask(recordedBatchChurn());
    $recorded = recordedBatchResults($scenario);
    $total = LayaFixtures::responseBody($scenario)['total_usage'];
    assert(is_array($total) && is_int($total['input_tokens']) && is_int($total['output_tokens']));

    $set = (new SystemOneCodec)->decodeBatch($decision, $states, LayaFixtures::response($scenario));

    $expected = array_map(static function (mixed $result): float {
        assert(is_array($result) && is_array($result['answers']) && is_array($result['answers']['churn']));

        return (float) $result['answers']['churn']['noul'];
    }, $recorded);

    expect($set)->toHaveCount($states)
        ->and(array_map(static fn ($result): float => $result->get(recordedBatchChurn())->probability, $set->all()))->toBe($expected)
        ->and($set->usage)->toEqual(new Usage($total['input_tokens'], $total['output_tokens']));
})->with([
    'batch' => ['batch', 3],
    'with min_confidence' => ['batch_below_min_confidence', 2],
]);

it('sums the per-result usage of the recording into its total_usage, as the codec reports it', function (): void {
    $inputs = array_map(static function (mixed $result): int {
        assert(is_array($result) && is_array($result['usage']) && is_int($result['usage']['input_tokens']));

        return $result['usage']['input_tokens'];
    }, recordedBatchResults('batch'));

    $set = (new SystemOneCodec)->decodeBatch(
        Decision::about(Context::text('template'))->ask(recordedBatchChurn()),
        3,
        LayaFixtures::response('batch'),
    );

    expect($set->usage?->inputTokens)->toBe(array_sum($inputs));
});
