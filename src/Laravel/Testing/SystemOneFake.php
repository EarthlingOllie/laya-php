<?php

declare(strict_types=1);

namespace EarthlingOllie\SystemOneClassification\Laravel\Testing;

use EarthlingOllie\SystemOneClassification\Domain\Context;
use EarthlingOllie\SystemOneClassification\Domain\Decision;
use EarthlingOllie\SystemOneClassification\Domain\Question\HandleIdentity;
use EarthlingOllie\SystemOneClassification\Domain\Question\QuestionHandle;
use EarthlingOllie\SystemOneClassification\Exceptions\InvalidConfiguration;
use EarthlingOllie\SystemOneClassification\Exceptions\InvalidDecision;
use EarthlingOllie\SystemOneClassification\Exceptions\UnknownQuestion;
use EarthlingOllie\SystemOneClassification\Exceptions\UnscriptedQuestion;
use EarthlingOllie\SystemOneClassification\Laravel\ConnectionResolver;
use EarthlingOllie\SystemOneClassification\Laravel\SystemOneManager;
use EarthlingOllie\SystemOneClassification\Protocol\SystemOneCodec;
use EarthlingOllie\SystemOneClassification\Result\Answer\Answer;
use EarthlingOllie\SystemOneClassification\Result\Result;
use EarthlingOllie\SystemOneClassification\Result\ResultSet;
use Illuminate\Support\Testing\Fakes\Fake;
use PHPUnit\Framework\Assert;

use function array_filter;
use function array_reverse;
use function count;

/**
 * What SystemOne::fake() swaps in: answers decisions from scripted answers instead of a model,
 * and records every decision for the assertions, the way Http::fake() and Queue::fake() work.
 *
 * - A question is answered by the last script given for an equal handle (HandleIdentity, the
 *   identity Result::get() reads by). A question with no script gets FakeAnswer::unscripted(),
 *   or, once strict(), an UnscriptedQuestion.
 * - A decision the real client would refuse (no questions, an unknown question type) is
 *   refused the same way, and not recorded. So is a connection: the real SystemOneManager
 *   checks it (configuration(), which needs no API key for a remote host, as nothing is ever
 *   sent to it). Its profile tags each answer's raw
 *   confidence ('laya' when unset or empty).
 *
 * The only stateful class of the package (docs/PLAN.md, Quality bar).
 */
final class SystemOneFake implements ConnectionResolver, Fake
{
    /** @var list<FakeAnswer<Answer>> */
    private readonly array $scripts;

    private bool $strict = false;

    /** @var list<array{decision: Decision, connection: string, result: Result}> */
    private array $decided = [];

    /**
     * @param  list<FakeAnswer<Answer>>  $answers
     */
    public function __construct(
        private readonly SystemOneManager $manager,
        array $answers = [],
    ) {
        // The last script given for a question wins.
        $this->scripts = array_reverse($answers);
    }

    /** Throws an UnscriptedQuestion for every question with no script, instead of a default answer. */
    public function strict(): self
    {
        $this->strict = true;

        return $this;
    }

    /** @throws InvalidConfiguration when the connection is not configured */
    public function connection(?string $name = null): FakeConnection
    {
        // The real manager checks the connection, so a config the real client refuses is refused
        // here too, by the same rules: all but a remote host's API key, as nothing is sent.
        $config = $this->manager->configuration($name);

        return new FakeConnection($this, $config->name, $config->profile);
    }

    /** @throws InvalidConfiguration when the default connection is not configured */
    public function decide(Decision $decision): Result
    {
        return $this->connection()->decide($decision);
    }

    /**
     * Asks the decision's questions about each context on the default connection.
     *
     * @param  list<Context>  $contexts
     *
     * @throws InvalidConfiguration when the default connection is not configured
     */
    public function decideMany(array $contexts, Decision $decision): ResultSet
    {
        return $this->connection()->decideMany($contexts, $decision);
    }

    /**
     * Answers a batch on a connection: each context is one decision, answered and recorded by
     * answer(). The batch is first encoded as the real client's batches are, so a batch it
     * would refuse is refused before anything is recorded. Its usage is null: no tokens were
     * counted. FakeConnection's half of decideMany().
     *
     * @internal
     *
     * @param  array<mixed>  $contexts
     *
     * @throws InvalidDecision when the real client would refuse the batch
     * @throws UnscriptedQuestion when strict and a question has no script
     */
    public function answerMany(array $contexts, Decision $decision, FakeConnection $connection): ResultSet
    {
        new SystemOneCodec($connection->profile)->encodeBatches($decision, $contexts);
        $results = [];

        /** @var list<Context> $contexts encodeBatches() refuses anything else */
        foreach ($contexts as $context) {
            $results[] = $this->answer($decision->withContext($context), $connection);
        }

        return new ResultSet($results, null);
    }

    /**
     * Answers a decision on a connection and records it. FakeConnection's half of decide().
     *
     * Every way to send decisions (decideMany() too) comes through here, one decision at a
     * time, so each is answered and recorded alike.
     *
     * @internal
     *
     * @throws InvalidDecision when the real client would refuse the decision
     * @throws UnscriptedQuestion when strict and a question has no script
     */
    public function answer(Decision $decision, FakeConnection $connection): Result
    {
        // Encoded only to be refused exactly where the real client refuses it.
        new SystemOneCodec($connection->profile)->encode($decision);
        $answers = [];

        foreach ($decision->questions() as $question) {
            $answers[$question->key] = ['question' => $question, 'answer' => $this->script($question)->answerFor($connection->profile->name, $decision->minConfidence)];
        }

        $result = new Result($answers);
        $this->decided[] = ['decision' => $decision, 'connection' => $connection->name, 'result' => $result];

        return $result;
    }

    /**
     * Asserts that a decision was made for which the callback returns true.
     *
     * @param  callable(Decision, string): bool  $callback  given each decision and the name of the connection it was made on
     */
    public function assertDecided(callable $callback): void
    {
        $matching = array_filter($this->decided, static fn (array $made): bool => $callback($made['decision'], $made['connection']));

        Assert::assertNotEmpty($matching, 'No decision matching the callback was made ('.$this->made().').');
    }

    public function assertDecidedTimes(int $times): void
    {
        $made = count($this->decided);

        Assert::assertSame($times, $made, 'Expected '.self::decisions($times)." to be made, but {$made} were.");
    }

    public function assertNothingDecided(): void
    {
        Assert::assertSame(0, count($this->decided), 'Expected no decisions, but '.$this->made().'.');
    }

    /**
     * Asserts that some decision asked the question: one whose result an equal handle reads.
     *
     * @param  QuestionHandle<Answer>  $question
     */
    public function assertAsked(QuestionHandle $question): void
    {
        $asked = array_filter($this->decided, static function (array $made) use ($question): bool {
            try {
                $made['result']->get($question);
            } catch (UnknownQuestion) {
                return false;
            }

            return true;
        });

        Assert::assertNotEmpty($asked, "The question '{$question->key}' was not asked.");
    }

    /** "1 decision was made", "2 decisions were made": for the failure messages. */
    private function made(): string
    {
        $made = count($this->decided);

        return self::decisions($made).($made === 1 ? ' was' : ' were').' made';
    }

    private static function decisions(int $count): string
    {
        return $count === 1 ? '1 decision' : "{$count} decisions";
    }

    /**
     * @param  QuestionHandle<Answer>  $question
     * @return FakeAnswer<Answer>
     *
     * @throws UnscriptedQuestion when strict and the question has no script
     */
    private function script(QuestionHandle $question): FakeAnswer
    {
        $nearMisses = [];

        foreach ($this->scripts as $script) {
            if ($script->question->key !== $question->key) {
                continue;
            }

            $differing = HandleIdentity::differences($script->question, $question);

            if ($differing === []) {
                return $script;
            }

            $nearMisses[] = $differing;
        }

        if ($this->strict) {
            throw UnscriptedQuestion::notScripted($question->key, $nearMisses);
        }

        return FakeAnswer::unscripted($question);
    }
}
