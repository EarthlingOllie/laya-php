<?php

declare(strict_types=1);

use EarthlingOllie\SystemOneClassification\Domain\Context;
use EarthlingOllie\SystemOneClassification\Domain\Decision;
use EarthlingOllie\SystemOneClassification\Domain\Question;
use EarthlingOllie\SystemOneClassification\Exceptions\InvalidDecision;
use EarthlingOllie\SystemOneClassification\Model\JevProfile;
use EarthlingOllie\SystemOneClassification\Model\LayaProfile;
use EarthlingOllie\SystemOneClassification\Protocol\SystemOneCodec;
use EarthlingOllie\SystemOneClassification\Tests\Fixtures\Enums\PartlyDescribedUrgency;
use EarthlingOllie\SystemOneClassification\Tests\Support\LayaFixtures;
use EarthlingOllie\SystemOneClassification\Tests\Support\OptionsEnum;

/*
 * LayaProfile against what a real laya-serve refused: every request it answered with a 413 or
 * 422 for breaking a limit the profile holds is refused in PHP instead, and the profile's
 * limits are the ones the server's own messages state.
 */

const RECORDED_TICKET = 'Hi, we were billed twice for March. Please refund the duplicate charge today or we will cancel our plan.';

/** The `detail` of a recorded error response. */
function recordedDetail(string $scenario): string
{
    $detail = LayaFixtures::responseBody($scenario)['detail'] ?? null;
    assert(is_string($detail));

    return $detail;
}

it('holds choice questions to the option cap laya-serve states, and refuses the request it refused', function (): void {
    $decision = Decision::about(Context::text(RECORDED_TICKET))
        ->ask(Question::choice('label', OptionsEnum::withCases(101), 'Which label fits?'));

    expect(recordedDetail('too_many_options'))->toBe("too many choice options for 'label' (101 > ".LayaProfile::MAX_CHOICE_OPTIONS.')')
        // The same decision is what the server was sent: Jev's higher cap lets it be encoded.
        ->and(json_decode(json_encode((new SystemOneCodec(new JevProfile))->encode($decision), JSON_THROW_ON_ERROR), true))->toBe(LayaFixtures::requestBody('too_many_options'))
        ->and(fn () => (new SystemOneCodec(new LayaProfile))->encode($decision))
        ->toThrow(InvalidDecision::class, "Choice question 'label' has 101 options, more than the 100 the laya profile allows per choice question.");
});

it('accepts exactly the model values laya-serve lists, and refuses the one it refused', function (): void {
    $detail = recordedDetail('unknown_model');
    preg_match_all("/'([^']+)'/", $detail, $quoted);
    $decision = Decision::about(Context::text(RECORDED_TICKET))
        ->ask(Question::yesNo('churn', 'Does the customer threaten to cancel or leave?'))
        ->withModel('someone/else');

    // The first quoted name is the model that was refused; the rest are the ones it accepts.
    expect(array_slice($quoted[1], 1))->toBe(LayaProfile::MODELS)
        ->and((new SystemOneCodec(new JevProfile(['someone/else'])))->encode($decision))->toBe(LayaFixtures::requestBody('unknown_model'))
        ->and(fn () => (new SystemOneCodec(new LayaProfile))->encode($decision))
        ->toThrow(InvalidDecision::class, "The decision pins model 'someone/else', which the laya profile does not accept");
});

it('requires a description for every score level, and refuses the request laya-serve refused', function (): void {
    $decision = Decision::about(Context::text(RECORDED_TICKET))
        ->ask(Question::score('urgency', PartlyDescribedUrgency::class, 'How urgent is this?'));

    expect(recordedDetail('invalid_request'))->toBe("score question 'urgency' has a null level at index 1; give every level a description")
        ->and((new SystemOneCodec(new JevProfile))->encode($decision))->toBe(LayaFixtures::requestBody('invalid_request'))
        ->and(fn () => (new SystemOneCodec(new LayaProfile))->encode($decision))
        ->toThrow(InvalidDecision::class, "Level 1 of score question 'urgency' has no description, which the laya profile requires of every score level");
});
