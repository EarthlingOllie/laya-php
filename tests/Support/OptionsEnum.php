<?php

declare(strict_types=1);

namespace EarthlingOllie\SystemOneClassification\Tests\Support;

use BackedEnum;
use EarthlingOllie\SystemOneClassification\Domain\Describe;

use function array_map;
use function class_exists;
use function implode;
use function range;
use function sprintf;

/**
 * String-backed enums with a given number of cases, for testing option limits at their
 * boundaries (99, 100, 101, 254, ...) without a fixture file of hundreds of cases each.
 *
 * Each is declared once, on first use, as Tests\Support\Generated\Options{count}. Its options
 * are the ones bin/laya-record-fixtures sends for `too_many_options`: label(0), label(1), ...
 * ('label_000', 'label_001', ...), described as 'option 0', 'option 1', ...
 */
final readonly class OptionsEnum
{
    /**
     * @param  positive-int  $count
     * @return class-string<BackedEnum>
     */
    public static function withCases(int $count): string
    {
        $name = "Options{$count}";
        /** @var class-string<BackedEnum> $class */
        $class = __NAMESPACE__."\\Generated\\{$name}";

        if (! class_exists($class)) {
            $cases = implode(' ', array_map(
                static fn (int $i): string => sprintf("#[\\%s('option %d')] case O%d = '%s';", Describe::class, $i, $i, self::label($i)),
                range(0, $count - 1),
            ));

            // The enum's whole source is built here from integers, never from input.
            eval('namespace '.__NAMESPACE__."\\Generated; enum {$name}: string { {$cases} }");
        }

        return $class;
    }

    /** The label of option $index. */
    public static function label(int $index): string
    {
        return sprintf('label_%03d', $index);
    }
}
