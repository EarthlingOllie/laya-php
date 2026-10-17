<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Orchestra\Testbench\Concerns\WithWorkbench;
use Workbench\App\Enums\Topic;

/*
 * The workbench checks decisions against the default connection's model profile: /limits asks
 * a choice over 101 topics (one more than Laya allows), and every question page pins the
 * checkpoint given as ?model=.
 */

uses(WithWorkbench::class);

/** @return array<string, mixed> */
function topicResponse(): array
{
    $probabilities = array_fill_keys(array_map(static fn (Topic $topic): string => $topic->value, Topic::cases()), 0.0);

    return ['answers' => ['topic' => [
        'type' => 'choice',
        'choice' => 'topic_042',
        'probabilities' => [...$probabilities, 'topic_042' => 1.0],
        'confidence' => 0.7,
    ]]];
}

it('shows the InvalidDecision for a choice over more options than the laya profile allows, sending nothing', function (): void {
    Http::fake();

    $this->get('/limits')
        ->assertStatus(500)
        ->assertSee('data-error="InvalidDecision"', escape: false)
        ->assertSee('data-profile="laya"', escape: false)
        ->assertSee('data-tournament-grouping="no"', escape: false)
        ->assertSee("Choice question 'topic' has 101 options, more than the 100 the laya profile allows per choice question.");

    Http::assertNothingSent();
});

it('sends the same choice when the connection allows 255 options', function (array $connection, string $profile, string $grouping): void {
    config(['system-one.connections.local' => [...config('system-one.connections.local'), ...$connection]]);
    Http::fake(['*' => Http::response(topicResponse())]);

    // Review finding: the page named the profile but not tournament grouping, so it showed
    // 'laya' (100 options) while sending 101.
    $this->get('/limits')
        ->assertOk()
        ->assertSee("data-profile=\"{$profile}\"", escape: false)
        ->assertSee("data-tournament-grouping=\"{$grouping}\"", escape: false)
        ->assertSee('data-value="Topic042"', escape: false)
        ->assertSee('data-options="101"', escape: false);

    Http::assertSent(fn (Request $request): bool => count((array) $request['questions']['topic']['criteria']) === 101);
})->with([
    'jev' => [['profile' => 'jev'], 'jev', 'no'],
    'laya with tournament grouping' => [['profile' => 'laya', 'tournament_grouping' => true], 'laya', 'yes'],
    'laya with tournament grouping from env' => [['profile' => 'laya', 'tournament_grouping' => '1'], 'laya', 'yes'],
]);

it('pins the checkpoint given as ?model= on every question page', function (string $page, array $answer): void {
    Http::fake(['*' => Http::response(['answers' => $answer])]);

    $this->get("{$page}?model=english")
        ->assertOk()
        ->assertSee('data-model="english"', escape: false);

    Http::assertSent(fn (Request $request): bool => $request['model'] === 'english');
})->with([
    '/decide' => ['/decide', ['churn' => ['type' => 'noul', 'noul' => 0.5]]],
    '/choose' => ['/choose', ['department' => [
        'type' => 'choice', 'choice' => 'billing', 'confidence' => 0.4,
        'probabilities' => ['billing' => 0.8, 'technical' => 0.1, 'other' => 0.1],
    ]]],
    '/score' => ['/score', ['urgency' => [
        'type' => 'score', 'score' => 0.5, 'confidence' => 0.4,
        'legend' => ['0' => 'can wait', '1' => 'soon', '2' => 'today', '3' => 'blocking'],
        'probabilities' => ['0' => 0.5, '1' => 0.5, '2' => 0.0, '3' => 0.0],
    ]]],
]);

it('lets the server choose when ?model= is missing, blank or not a string', function (string $query): void {
    Http::fake(['*' => Http::response(['answers' => ['churn' => ['type' => 'noul', 'noul' => 0.5]]])]);

    $this->get("/decide?{$query}")
        ->assertOk()
        ->assertSee('data-model=""', escape: false);

    Http::assertSent(fn (Request $request): bool => ! isset($request['model']));
})->with(['missing' => '', 'blank' => 'model=%20', 'array' => 'model[]=english']);

it('shows the InvalidDecision for a model the profile does not accept, sending nothing', function (): void {
    Http::fake();

    $this->get('/decide?model=someone/else')
        ->assertStatus(500)
        ->assertSee('data-error="InvalidDecision"', escape: false)
        ->assertSee("The decision pins model 'someone/else', which the laya profile does not accept");

    Http::assertNothingSent();
});
