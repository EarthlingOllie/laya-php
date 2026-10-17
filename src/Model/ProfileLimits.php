<?php

declare(strict_types=1);

namespace EarthlingOllie\SystemOneClassification\Model;

use EarthlingOllie\SystemOneClassification\Domain\Decision;
use EarthlingOllie\SystemOneClassification\Domain\Question\ChoiceQuestion;
use EarthlingOllie\SystemOneClassification\Domain\Question\ScoreQuestion;
use EarthlingOllie\SystemOneClassification\Exceptions\InvalidDecision;

use function count;

/**
 * Checks a decision against a model profile's limits, so a decision the model would refuse
 * fails in PHP, naming the question and the limit, instead of with a 413 or 422 from the
 * server. SystemOneCodec::encode() runs it before encoding anything.
 */
final readonly class ProfileLimits
{
    /**
     * @throws InvalidDecision for a pinned model the profile does not accept, or naming the
     *                         first question that breaks a limit, and the limit
     */
    public static function check(ModelProfile $profile, Decision $decision): void
    {
        $model = $decision->model;

        if ($model !== null && ! $profile->acceptsModel($model)) {
            throw InvalidDecision::unacceptedModel($model, $profile->name, $profile->models());
        }

        foreach ($decision->questions() as $question) {
            if ($question instanceof ChoiceQuestion) {
                $options = count($question->options());

                if ($options > $profile->maxChoiceOptions) {
                    throw InvalidDecision::tooManyOptions($question->key, $options, $profile->maxChoiceOptions, $profile->name);
                }
            }

            if ($question instanceof ScoreQuestion && $profile->requiresScoreDescriptions) {
                foreach ($question->levels() as ['case' => $case, 'description' => $description]) {
                    if ($description === null) {
                        throw InvalidDecision::undescribedLevel($question->key, $case, $profile->name);
                    }
                }
            }
        }
    }
}
