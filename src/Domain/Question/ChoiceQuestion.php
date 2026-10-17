<?php

declare(strict_types=1);

namespace EarthlingOllie\SystemOneClassification\Domain\Question;

use BackedEnum;
use EarthlingOllie\SystemOneClassification\Domain\Describe;
use EarthlingOllie\SystemOneClassification\Domain\DescribesCases;
use EarthlingOllie\SystemOneClassification\Exceptions\InvalidDecision;
use EarthlingOllie\SystemOneClassification\Result\Answer\ChoiceAnswer;
use Error;
use ReflectionClassConstant;

use function enum_exists;
use function is_subclass_of;
use function trim;

/**
 * Pick one option, where the options are the cases of a backed enum (Laya's `choice`).
 *
 * Each case's backing value is its option label. Its description comes from DescribesCases
 * when the enum implements it, otherwise from a #[Describe] attribute on the case; a case with
 * neither goes out as its bare label. Build it with Question::choice().
 *
 * @template TEnum of BackedEnum
 *
 * @implements QuestionHandle<ChoiceAnswer<TEnum>>
 */
final readonly class ChoiceQuestion implements QuestionHandle
{
    /** @var non-empty-string */
    public string $key;

    /** @var non-empty-list<array{case: TEnum, description: ?string}> */
    private array $options;

    /**
     * @param  class-string<TEnum>  $enum
     *
     * @throws InvalidDecision when the key or instructions are invalid, the class is not a
     *                         backed enum, the enum has no cases, a label (backing value) or
     *                         a description is blank, or
     *                         a #[Describe] cannot be built (repeated, or wrong arguments)
     */
    public function __construct(string $key, public string $enum, public string $instructions)
    {
        $this->key = QuestionKey::validate($key);
        Instructions::validate($key, $instructions);

        // enum_exists() too: an interface that extends BackedEnum is a subclass of it, but has
        // no cases() to call.
        if (! enum_exists($enum) || ! is_subclass_of($enum, BackedEnum::class)) {
            throw InvalidDecision::notBackedEnum($key, $enum);
        }

        $options = [];

        foreach ($enum::cases() as $case) {
            if (trim((string) $case->value) === '') {
                throw InvalidDecision::blankLabel($key, $case);
            }

            $options[] = ['case' => $case, 'description' => self::describe($key, $case)];
        }

        if ($options === []) {
            throw InvalidDecision::noOptions($key, $enum);
        }

        $this->options = $options;
    }

    /**
     * Every case with its description (null when it has none), in the enum's declaration order.
     *
     * @return non-empty-list<array{case: TEnum, description: ?string}>
     */
    public function options(): array
    {
        return $this->options;
    }

    public function answerType(): string
    {
        return ChoiceAnswer::class;
    }

    private static function describe(string $key, BackedEnum $case): ?string
    {
        if ($case instanceof DescribesCases) {
            $description = $case->describe();
        } else {
            $attributes = new ReflectionClassConstant($case, $case->name)->getAttributes(Describe::class);

            // PHP checks an attribute's arguments, and that it is not repeated, only when it is
            // built here. Every such failure is an Error (ArgumentCountError and TypeError among
            // them), and Describe's constructor does nothing else that could throw.
            try {
                $description = ($attributes[0] ?? null)?->newInstance()->description;
            } catch (Error $e) {
                throw InvalidDecision::malformedDescribe($key, $case, $e);
            }
        }

        if ($description !== null && trim($description) === '') {
            throw InvalidDecision::blankDescription($key, (string) $case->value);
        }

        return $description;
    }
}
