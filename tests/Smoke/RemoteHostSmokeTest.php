<?php

declare(strict_types=1);

use EarthlingOllie\SystemOneClassification\Domain\Context;
use EarthlingOllie\SystemOneClassification\Domain\Decision;
use EarthlingOllie\SystemOneClassification\Domain\Question;
use EarthlingOllie\SystemOneClassification\Exceptions\RejectedByModel;
use EarthlingOllie\SystemOneClassification\Laravel\Facades\SystemOne;
use EarthlingOllie\SystemOneClassification\Tests\Fixtures\Enums\Department;
use EarthlingOllie\SystemOneClassification\Tests\Fixtures\Enums\Urgency;
use EarthlingOllie\SystemOneClassification\Tests\Support\RemoteHost;

/*
 * Opt-in smoke tests against the real remote hosts (issue #9). Not in the default suites:
 * `composer test:smoke`, or the manually run "Smoke (remote hosts)" workflow. Each test skips
 * unless its host's key is in the environment (SYSTEM_ONE_LAYA_HOSTED_API_KEY,
 * SYSTEM_ONE_JEV_API_KEY); the other SYSTEM_ONE_LAYA_HOSTED_* / SYSTEM_ONE_JEV_* settings of
 * config/system-one.php apply as usual. Every call is billed by the host.
 */

const SMOKE_TICKET = 'Hi, we were billed twice for March. Please refund the duplicate charge today or we will cancel our plan.';

dataset('remote hosts', ['laya-hosted', 'jev']);

it('answers every question type through the named connection', function (string $connection): void {
    RemoteHost::fromEnvironment($connection)->guard();
    $churn = Question::yesNo('churn', 'Does the customer threaten to cancel or leave?');
    $department = Question::choice('department', Department::class, 'Which department should handle this?');
    $urgency = Question::score('urgency', Urgency::class, 'How urgent is this?');

    $result = SystemOne::connection($connection)->decide(Decision::about(Context::text(SMOKE_TICKET))->ask($churn, $department, $urgency));

    expect($result->get($churn)->probability)->toBeGreaterThanOrEqual(0.0)->toBeLessThanOrEqual(1.0)
        ->and($result->get($department)->value)->toBeInstanceOf(Department::class)
        ->and($result->get($department)->confidence->profile)->toBe(config("system-one.connections.{$connection}.profile"))
        ->and($result->get($urgency)->value)->toBeInstanceOf(Urgency::class);
})->with('remote hosts');

it('is refused with a wrong key, as RejectedByModel carrying the server message and not the key', function (string $connection): void {
    RemoteHost::fromEnvironment($connection)->guard();
    $wrongKey = 'smoke-wrong-key-'.bin2hex(random_bytes(8));
    config(["system-one.connections.{$connection}.api_key" => $wrongKey]);

    try {
        SystemOne::connection($connection)->decide(Decision::about(Context::text(SMOKE_TICKET))->ask(Question::yesNo('churn', 'Cancel?')));
        $this->fail('Expected RejectedByModel for a wrong key.');
    } catch (RejectedByModel $rejected) {
        expect($rejected->status)->toBeIn([401, 403])
            ->and((string) $rejected)->not->toContain($wrongKey);
    }
})->with('remote hosts');
