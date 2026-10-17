<?php

declare(strict_types=1);

namespace EarthlingOllie\SystemOneClassification\Domain\Question;

use BackedEnum;
use EarthlingOllie\SystemOneClassification\Exceptions\InvalidDecision;
use EarthlingOllie\SystemOneClassification\Result\Answer\ScoreAnswer;

use function array_column;
use function enum_exists;
use function is_int;
use function is_subclass_of;
use function trim;
use function usort;

/**
 * Rate on an ordinal scale, where the levels are the cases of an int-backed enum (Laya's `score`).
 *
 * The backing values order the levels, lowest first, whatever order the cases are declared
 * in. A level's description comes from DescribesCases when the enum implements it, otherwise
 * from a #[Describe] attribute on the case. Whether every level must have one is up to the
 * model (Laya: yes, Jev: no): the connection's profile checks it before sending. Build it with
 * Question::score().
 *
 * @template TEnum of BackedEnum
 *
 * @implements QuestionHandle<ScoreAnswer<TEnum>>
 */
final readonly class ScoreQuestion implements QuestionHandle
{
    /** @var non-empty-string */
    public string $key;

    /** @var non-empty-list<array{case: TEnum, value: int, description: ?string}> */
    private array $levels;

    /**
     * @param  class-string<TEnum>  $enum  an int-backed enum
     *
     * @throws InvalidDecision when the key or instructions are invalid, the class is not an
     *                         int-backed enum, the enum has no cases, or a case has a blank
     *                         description or a #[Describe] PHP cannot build
     */
    public function __construct(string $key, public string $enum, public string $instructions)
    {
        $this->key = QuestionKey::validate($key);
        Instructions::validate($key, $instructions);

        // enum_exists() too: an interface that extends BackedEnum is a subclass of it, but has
        // no cases() to call.
        if (! enum_exists($enum) || ! is_subclass_of($enum, BackedEnum::class)) {
            throw InvalidDecision::notIntBackedEnum($key, $enum);
        }

        $levels = [];

        // A string-backed enum is refused at its first case (one without cases has no levels
        // either way). Checking each value, rather than the enum's backing type, gives the
        // levels their int values without a cast.
        foreach ($enum::cases() as $case) {
            if (! is_int($case->value)) {
                throw InvalidDecision::notIntBackedEnum($key, $enum);
            }

            $levels[] = ['case' => $case, 'value' => $case->value, 'description' => self::describe($key, $case)];
        }

        if ($levels === []) {
            throw InvalidDecision::noLevels($key, $enum);
        }

        usort($levels, static fn (array $a, array $b): int => $a['value'] <=> $b['value']);

        $this->levels = $levels;
    }

    /**
     * Every level with its backing value and description (null when it has none), lowest
     * backing value first.
     *
     * @return non-empty-list<array{case: TEnum, value: int, description: ?string}>
     */
    public function levels(): array
    {
        return $this->levels;
    }

    /**
     * The level descriptions, lowest level first: what goes out as `criteria`, since the
     * server knows levels only by index, and what the answer's `legend` must give back.
     *
     * @return non-empty-list<?string>
     */
    public function criteria(): array
    {
        return array_column($this->levels, 'description');
    }

    public function answerType(): string
    {
        return ScoreAnswer::class;
    }

    private static function describe(string $key, BackedEnum $case): ?string
    {
        $description = CaseDescription::of($case, "Level {$case->value} of score question '{$key}'");

        if ($description !== null && trim($description) === '') {
            throw InvalidDecision::blankLevelDescription($key, $case);
        }

        return $description;
    }
}
