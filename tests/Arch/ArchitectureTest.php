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
 * #1 asks: a FakeTransport there holds scripted answers immutably, and anything that
 * records the decisions sent belongs to the testing fake under src/Laravel, which PLAN.md
 * names as the only stateful part besides Laravel singletons.
 */
const READONLY_CORE = [
    PACKAGE.'\\Domain',
    PACKAGE.'\\Result',
    PACKAGE.'\\Model',
    PACKAGE.'\\Protocol',
    PACKAGE.'\\Transport',
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

arch(EXCEPTIONS.' classes are final')
    ->expect(EXCEPTIONS)
    ->classes()
    ->toBeFinal();

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
