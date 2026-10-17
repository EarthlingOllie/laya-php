<?php

declare(strict_types=1);

use EarthlingOllie\SystemOneClassification\Domain\Context;
use EarthlingOllie\SystemOneClassification\Domain\Decision;
use EarthlingOllie\SystemOneClassification\Domain\Question;
use EarthlingOllie\SystemOneClassification\Exceptions\InvalidConfiguration;
use EarthlingOllie\SystemOneClassification\Exceptions\InvalidDecision;
use EarthlingOllie\SystemOneClassification\Laravel\Facades\SystemOne;
use EarthlingOllie\SystemOneClassification\Tests\Support\OptionsEnum;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/*
 * Each connection picks its model profile in config: `profile` ('laya' or 'jev'), plus
 * `tournament_grouping` (Laya servers run with LAYA_TOURNAMENT_GROUP) and `models` (the model
 * values the host accepts beyond the profile's own).
 */

/**
 * Configures connection 'other' and sends it a decision with a yes/no question and, if given,
 * a choice over that many options, pinned to $model if given.
 *
 * @param  array<string, mixed>  $connection
 * @return list<Request> the requests sent
 */
function sendThrough(array $connection, ?int $options = null, ?string $model = null): array
{
    config(['system-one.connections.other' => ['url' => 'http://other.test', ...$connection]]);
    $answers = ['churn' => ['type' => 'noul', 'noul' => 0.1]];
    $decision = Decision::about(Context::text('x'))->ask(Question::yesNo('churn', 'Cancel?'));

    if ($options !== null) {
        $labels = array_map(OptionsEnum::label(...), range(0, $options - 1));
        $answers['label'] = [
            'type' => 'choice', 'choice' => 'label_000', 'confidence' => 0.5,
            'probabilities' => [...array_fill_keys($labels, 0.0), 'label_000' => 1.0],
        ];
        $decision = $decision->ask(Question::choice('label', OptionsEnum::withCases($options), 'Which label fits?'));
    }

    if ($model !== null) {
        $decision = $decision->withModel($model);
    }

    $sent = [];
    Http::fake(function (Request $request) use (&$sent, $answers) {
        $sent[] = $request;

        return Http::response(['answers' => $answers]);
    });

    try {
        SystemOne::connection('other')->decide($decision);
    } catch (InvalidDecision $exception) {
        expect($sent)->toBe([]);

        throw $exception;
    }

    return $sent;
}

it('holds a laya connection to 100 options per choice question, sending nothing above it', function (): void {
    expect(sendThrough(['profile' => 'laya'], options: 100))->toHaveCount(1)
        ->and(fn () => sendThrough(['profile' => 'laya'], options: 101))
        ->toThrow(InvalidDecision::class, "Choice question 'label' has 101 options, more than the 100 the laya profile allows per choice question.");
});

it('allows 255 options on a laya connection with tournament grouping', function (mixed $on): void {
    expect(sendThrough(['profile' => 'laya', 'tournament_grouping' => $on], options: 255))->toHaveCount(1)
        ->and(fn () => sendThrough(['profile' => 'laya', 'tournament_grouping' => $on], options: 256))
        ->toThrow(InvalidDecision::class, 'more than the 255 the laya profile allows');
})->with(['true' => true, "'1'" => '1', "'true'" => 'true', "'on'" => 'on']);

it('keeps the 100 option limit when tournament grouping is off or unset', function (mixed $off): void {
    expect(fn () => sendThrough(['profile' => 'laya', 'tournament_grouping' => $off], options: 101))
        ->toThrow(InvalidDecision::class, 'more than the 100 the laya profile allows');
})->with(['false' => false, "'0'" => '0', "'false'" => 'false', 'empty' => '', 'null' => null]);

// Review finding: on a jev connection it was ignored, so the same config failed only once
// the profile was switched to laya.
it('rejects a tournament_grouping that is not true or false, whatever the profile', function (mixed $value, string $profile): void {
    config(['system-one.connections.broken' => ['url' => 'http://x', 'profile' => $profile, 'tournament_grouping' => $value]]);

    SystemOne::connection('broken');
})->with(['words' => 'sometimes', 'a number' => 2, 'a list' => [[true]]])->with(['laya', 'jev'])
    ->throws(InvalidConfiguration::class, "System One connection 'broken' has a 'tournament_grouping' that is not true or false.");

it('holds a jev connection to 255 options per choice question', function (): void {
    expect(sendThrough(['profile' => 'jev'], options: 255))->toHaveCount(1)
        ->and(fn () => sendThrough(['profile' => 'jev'], options: 256))
        ->toThrow(InvalidDecision::class, 'more than the 255 the jev profile allows');
});

it('sends a model the profile accepts and refuses one it does not, sending nothing', function (): void {
    [$request] = sendThrough(['profile' => 'laya'], model: 'english');

    expect($request->data())->toHaveKey('model', 'english')
        ->and(fn () => sendThrough(['profile' => 'laya'], model: 'acme/laya-finetune'))
        ->toThrow(InvalidDecision::class, "The decision pins model 'acme/laya-finetune', which the laya profile does not accept");
});

it('accepts the extra models a connection lists, as a list or a comma-separated string', function (mixed $models): void {
    [$laya] = sendThrough(['profile' => 'laya', 'models' => $models], model: 'acme/laya-finetune');
    [$jev] = sendThrough(['profile' => 'jev', 'models' => $models], model: 'jev-1');

    expect($laya->data())->toHaveKey('model', 'acme/laya-finetune')
        ->and($jev->data())->toHaveKey('model', 'jev-1');
})->with([
    'list' => [['acme/laya-finetune', 'jev-1']],
    'string' => 'acme/laya-finetune, jev-1',
]);

// Review finding: the string form was trimmed but the list form was not, so the same names
// gave different allowlists.
it('trims the names in either form the same way', function (mixed $models): void {
    [$request] = sendThrough(['profile' => 'jev', 'models' => $models], model: 'jev-1');

    expect($request->data())->toHaveKey('model', 'jev-1');
})->with(['list' => [[' jev-1 ']], 'string' => ' jev-1 ']);

it('accepts no models on a jev connection that lists none', function (mixed $models): void {
    expect(fn () => sendThrough(['profile' => 'jev', 'models' => $models], model: 'jev-1'))
        ->toThrow(InvalidDecision::class, "but the jev profile accepts no model values; list the host's in the connection's 'models' config");
})->with(['missing' => null, 'empty string' => '', 'empty list' => [[]], 'only commas' => ' , ']);

it('rejects models that are not a list of names', function (mixed $models): void {
    config(['system-one.connections.broken' => ['url' => 'http://x', 'models' => $models]]);

    SystemOne::connection('broken');
})->with([
    'a number' => 5,
    'a non-string entry' => [['english', 5]],
    'a blank entry' => [['english', ' ']],
    'keyed' => [['a' => 'english']],
])->throws(InvalidConfiguration::class, "System One connection 'broken' has 'models' that are not a list of model names.");
