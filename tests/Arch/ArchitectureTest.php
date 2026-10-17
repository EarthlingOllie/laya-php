<?php

declare(strict_types=1);

/*
 * Architecture rules from docs/PLAN.md ("Quality bar" and "Package layout").
 *
 * The core namespaces hold the immutable, framework-free part of the package. Only
 * src/Laravel may depend on the framework, with one exception: the HTTP transport may
 * use illuminate/http (decision 7).
 *
 * Every rule is declared once per namespace and once per expectation on purpose:
 * - Pest's `not->toUse()` given an array of targets passes as soon as one target is
 *   clean, so a multi-target expectation would let a violation through.
 * - A filter such as `classes()` only applies to the first expectation of a chain.
 * Each rule has been shown to fail against a deliberately violating class.
 */

const PACKAGE = 'EarthlingOllie\\SystemOneClassification';

/*
 * Core namespaces whose classes must be `final readonly`. Interfaces and enums are
 * allowed; traits and any other kind of class are not. Transport is included, as issue
 * #1 asks: anything that records the decisions sent belongs to the testing fake under
 * src/Laravel/Testing, which PLAN.md names as the only stateful part besides Laravel
 * singletons (see the Laravel rules below).
 */
const READONLY_CORE = [
    PACKAGE.'\\Domain',
    PACKAGE.'\\Result',
    PACKAGE.'\\Model',
    PACKAGE.'\\Protocol',
    PACKAGE.'\\Transport',
    // The client itself is immutable too (PLAN.md, Quality bar): one instance per connection.
    PACKAGE.'\\Client',
];

/*
 * Exceptions are core too, but PHP forbids a readonly class from extending a
 * non-readonly one, and every Throwable must extend \Exception or \Error. So the rule
 * there is: final classes (no mutators) or interfaces.
 */
const EXCEPTIONS = PACKAGE.'\\Exceptions';

/** The only framework code a core namespace may use, keyed by namespace. */
const ALLOWED_FRAMEWORK_DEPENDENCIES = [
    PACKAGE.'\\Transport' => ['Illuminate\\Http'],
];

foreach (READONLY_CORE as $namespace) {
    arch("{$namespace} classes are final")
        ->expect($namespace)
        ->classes()
        ->toBeFinal();

    arch("{$namespace} classes are readonly")
        ->expect($namespace)
        ->classes()
        ->toBeReadonly();
}

/*
 * The Laravel layer holds the only stateful code (PLAN.md, Quality bar): the testing fake,
 * which records the decisions sent to it. Everything else there is readonly too, except the
 * classes that extend the framework's own (the service provider and the facade), which PHP
 * does not allow to be readonly.
 */
const LARAVEL = PACKAGE.'\\Laravel';
const TESTING = LARAVEL.'\\Testing';

arch(LARAVEL.' classes are final')
    ->expect(LARAVEL)
    ->classes()
    ->toBeFinal();

arch(LARAVEL.' classes are readonly, but for the framework subclasses and the testing fake')
    ->expect(LARAVEL)
    ->classes()
    ->toBeReadonly()
    ->ignoring([LARAVEL.'\\SystemOneServiceProvider', LARAVEL.'\\Facades', TESTING.'\\SystemOneFake']);

// Production code never reaches the fake: only SystemOne::fake() swaps it in.
arch(TESTING.' is used only by the facade')
    ->expect(TESTING)
    ->toOnlyBeUsedIn([LARAVEL.'\\Facades', TESTING]);

arch(EXCEPTIONS.' classes are final')
    ->expect(EXCEPTIONS)
    ->classes()
    ->toBeFinal();

/*
 * SystemOneException promises that `catch (SystemOneException)` catches everything the
 * package throws. These two rules cover the exception classes themselves: each one
 * implements the marker, and none lives outside the Exceptions namespace.
 * ThrownExceptionsTest covers the other half, the `throw` statements: Pest's `toUse()`
 * ignores PHP's own classes, so it cannot see a `throw new \InvalidArgumentException`.
 */
arch(EXCEPTIONS.' classes implement SystemOneException')
    ->expect(EXCEPTIONS)
    ->classes()
    ->toImplement(EXCEPTIONS.'\\SystemOneException');

arch('only '.EXCEPTIONS.' holds Throwables')
    ->expect(PACKAGE)
    ->not->toImplement(Throwable::class)
    ->ignoring(EXCEPTIONS);

foreach ([...READONLY_CORE, EXCEPTIONS] as $namespace) {
    arch("{$namespace} contains no traits")
        ->expect($namespace)
        ->not->toBeTrait();

    $allowed = ALLOWED_FRAMEWORK_DEPENDENCIES[$namespace] ?? [];

    arch("{$namespace} does not depend on the framework".($allowed === [] ? '' : ' beyond '.implode(', ', $allowed)))
        ->expect($namespace)
        ->not->toUse('Illuminate')
        ->ignoring($allowed);
}

arch('the package uses strict types')
    ->expect(PACKAGE)
    ->toUseStrictTypes();

arch('the package has no debugging or unsafe calls')
    ->preset()->php();

arch('the package passes the security preset')
    ->preset()->security();
