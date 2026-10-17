<?php

declare(strict_types=1);

namespace EarthlingOllie\SystemOneClassification\Laravel\Facades;

use EarthlingOllie\SystemOneClassification\Client\Decider;
use EarthlingOllie\SystemOneClassification\Domain\Decision;
use EarthlingOllie\SystemOneClassification\Domain\Question\QuestionHandle;
use EarthlingOllie\SystemOneClassification\Exceptions\InvalidConfiguration;
use EarthlingOllie\SystemOneClassification\Laravel\ConnectionResolver;
use EarthlingOllie\SystemOneClassification\Laravel\SystemOneManager;
use EarthlingOllie\SystemOneClassification\Laravel\Testing\FakeAnswer;
use EarthlingOllie\SystemOneClassification\Laravel\Testing\SystemOneFake;
use EarthlingOllie\SystemOneClassification\Result\Answer\Answer;
use EarthlingOllie\SystemOneClassification\Result\Result;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Facades\Facade;

/**
 * @method static Result decide(Decision $decision)
 * @method static Decider connection(?string $name = null)
 * @method static void assertDecided(callable(Decision, string): bool $callback)
 * @method static void assertDecidedTimes(int $times)
 * @method static void assertNothingDecided()
 * @method static void assertAsked(QuestionHandle<Answer> $question)
 *
 * @see SystemOneManager
 * @see SystemOneFake for the assertions, once fake() has swapped it in
 */
final class SystemOne extends Facade
{
    /**
     * Swaps in a fake that answers from the given scripts instead of a model, and records the
     * decisions it is sent for the assertions:
     *
     *     SystemOne::fake([FakeAnswer::choice($department, Department::Billing, confidence: 0.9)]);
     *
     * The facade and the container's ConnectionResolver then resolve to the fake; inject that
     * interface to be faked. SystemOneManager itself stays the real manager (the fake cannot
     * be one, as the manager is final), and so does anything that resolved the interface
     * before fake() was called, as with any Laravel fake. Calling it again starts a new fake,
     * with no scripts or decisions from the last.
     *
     * @param  list<FakeAnswer<Answer>>  $answers
     *
     * @throws InvalidConfiguration when no application is set behind the facades
     */
    public static function fake(array $answers = []): SystemOneFake
    {
        $app = self::getFacadeApplication() ?? throw InvalidConfiguration::noApplication();
        $fake = new SystemOneFake($app->make(Repository::class), $app->make(SystemOneManager::class), $answers);
        self::swap($fake);

        return $fake;
    }

    protected static function getFacadeAccessor(): string
    {
        return ConnectionResolver::class;
    }
}
