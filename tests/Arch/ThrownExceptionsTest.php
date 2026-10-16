<?php

declare(strict_types=1);

use EarthlingOllie\SystemOneClassification\Exceptions\SystemOneException;

/*
 * Every `throw` in src/ must throw a class that implements SystemOneException, so that
 * `catch (SystemOneException)` really catches everything the package throws (the
 * marker's promise). Pest's arch `toUse()` skips PHP's own classes, so a plain
 * `throw new \InvalidArgumentException(...)` would pass every arch rule; this test reads
 * the tokens instead.
 *
 * It understands the two forms the package uses, `throw new Name(...)` and
 * `throw Name::factory(...)`, resolving Name through the file's namespace and `use`
 * imports. Anything else (rethrowing a variable, say) fails, because it cannot be checked.
 */

/**
 * Each `throw` in a PHP file, as [line, resolved class name or null when it is not one of
 * the two checkable forms].
 *
 * @return list<array{int, ?string}>
 */
function thrownClasses(string $code): array
{
    $tokens = array_values(array_filter(
        PhpToken::tokenize($code),
        fn (PhpToken $token): bool => ! $token->isIgnorable(),
    ));
    $namespace = '';
    $imports = [];
    $throws = [];

    foreach ($tokens as $i => $token) {
        if ($token->is(T_NAMESPACE)) {
            $namespace = $tokens[$i + 1]->text;
        }

        // A class import, `use A\B;` or `use A\B as C;`. A closure's `use (` is skipped.
        if ($token->is(T_USE) && $tokens[$i + 1]->is([T_NAME_QUALIFIED, T_STRING])) {
            $name = $tokens[$i + 1]->text;
            $alias = $tokens[$i + 2]->is(T_AS) ? $tokens[$i + 3]->text : substr((string) strrchr('\\'.$name, '\\'), 1);
            $imports[strtolower($alias)] = $name;
        }

        if (! $token->is(T_THROW)) {
            continue;
        }

        $next = $tokens[$i + 1];
        $nameToken = match (true) {
            $next->is(T_NEW) => $tokens[$i + 2],
            $tokens[$i + 2]->is(T_DOUBLE_COLON) => $next,
            default => null,
        };

        $throws[] = [$token->line, $nameToken === null ? null : resolveClassName($nameToken, $namespace, $imports)];
    }

    return $throws;
}

/**
 * @param  array<string, string>  $imports
 */
function resolveClassName(PhpToken $token, string $namespace, array $imports): ?string
{
    if ($token->is(T_NAME_FULLY_QUALIFIED)) {
        return ltrim($token->text, '\\');
    }

    if (! $token->is([T_STRING, T_NAME_QUALIFIED])) {
        return null;
    }

    [$first] = explode('\\', $token->text, 2);
    $imported = $imports[strtolower($first)] ?? null;

    if ($imported !== null) {
        return $imported.substr($token->text, strlen($first));
    }

    return ltrim($namespace.'\\'.$token->text, '\\');
}

/**
 * The throws in the given files that do not throw a SystemOneException, as "file:line: why".
 *
 * @param  iterable<SplFileInfo>  $files
 * @return list<string>
 */
function throwsOutsideTheMarker(iterable $files): array
{
    $problems = [];

    foreach ($files as $file) {
        foreach (thrownClasses((string) file_get_contents($file->getPathname())) as [$line, $class]) {
            $where = $file->getPathname().":{$line}";

            if ($class === null) {
                $problems[] = "{$where}: throws something other than `new Name` or `Name::factory()`.";
            } elseif (! class_exists($class) || ! is_subclass_of($class, SystemOneException::class)) {
                $problems[] = "{$where}: throws {$class}, which does not implement SystemOneException.";
            }
        }
    }

    return $problems;
}

/**
 * @return list<SplFileInfo>
 */
function phpFilesIn(string $directory): array
{
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS));

    return array_values(array_filter(
        iterator_to_array($files, false),
        fn (mixed $file): bool => $file instanceof SplFileInfo && $file->getExtension() === 'php',
    ));
}

it('throws only SystemOneExceptions from src/', function (): void {
    $files = phpFilesIn(dirname(__DIR__, 2).'/src');

    expect($files)->not->toBeEmpty()
        ->and(throwsOutsideTheMarker($files))->toBe([]);
});

it('flags a throw that would get past catch (SystemOneException)', function (string $code, string $problem): void {
    $file = tempnam(sys_get_temp_dir(), 'throws').'.php';
    file_put_contents($file, $code);

    try {
        expect(throwsOutsideTheMarker([new SplFileInfo($file)]))->toBe(["{$file}:5: {$problem}"]);
    } finally {
        unlink($file);
    }
})->with([
    'imported SPL exception' => [
        "<?php\nnamespace EarthlingOllie\\SystemOneClassification\\Laravel;\nuse InvalidArgumentException;\nfunction f(): void {\n    throw new InvalidArgumentException('x');\n}\n",
        'throws InvalidArgumentException, which does not implement SystemOneException.',
    ],
    'fully qualified SPL exception' => [
        "<?php\nnamespace EarthlingOllie\\SystemOneClassification\\Laravel;\n\nfunction f(): void {\n    throw new \\RuntimeException('x');\n}\n",
        'throws RuntimeException, which does not implement SystemOneException.',
    ],
    'factory on a class without the marker' => [
        "<?php\nnamespace EarthlingOllie\\SystemOneClassification\\Laravel;\nuse Illuminate\\Http\\Client\\ConnectionException as Gone;\nfunction f(): void {\n    throw Gone::make('x');\n}\n",
        'throws Illuminate\\Http\\Client\\ConnectionException, which does not implement SystemOneException.',
    ],
    'unqualified name in the file namespace' => [
        "<?php\nnamespace EarthlingOllie\\SystemOneClassification\\Laravel;\n\nfunction f(): void {\n    throw new NotAClass();\n}\n",
        'throws EarthlingOllie\\SystemOneClassification\\Laravel\\NotAClass, which does not implement SystemOneException.',
    ],
    'rethrown variable' => [
        "<?php\nnamespace EarthlingOllie\\SystemOneClassification\\Laravel;\n\nfunction f(Throwable \$e): void {\n    throw \$e;\n}\n",
        'throws something other than `new Name` or `Name::factory()`.',
    ],
]);

it('accepts a throw of a package exception', function (): void {
    $file = tempnam(sys_get_temp_dir(), 'throws').'.php';
    file_put_contents($file, "<?php\nnamespace EarthlingOllie\\SystemOneClassification\\Laravel;\nuse EarthlingOllie\\SystemOneClassification\\Exceptions;\nfunction f(): void {\n    throw Exceptions\\InvalidDecision::emptyKey();\n}\n");

    try {
        expect(throwsOutsideTheMarker([new SplFileInfo($file)]))->toBe([]);
    } finally {
        unlink($file);
    }
});
