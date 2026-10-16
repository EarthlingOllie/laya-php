<?php

declare(strict_types=1);

use EarthlingOllie\SystemOneClassification\Laravel\SystemOneServiceProvider;
use Orchestra\Testbench\Concerns\WithWorkbench;
use Orchestra\Testbench\TestCase as Orchestra;
use Symfony\Component\Process\Process;

/*
 * The workbench app (testbench.yaml, workbench/) is what `composer serve` runs. These
 * tests cover its status page, and that the Testbench CLI, which `composer serve` uses,
 * registers the provider through package auto-discovery alone: testbench.yaml lists no
 * providers.
 */

uses(WithWorkbench::class);

it('reports the provider as loaded when the application registered it', function (): void {
    $this->get('/')
        ->assertOk()
        ->assertSee('System One Classification')
        ->assertSee(SystemOneServiceProvider::class)
        ->assertSee('data-provider-loaded="yes"', escape: false);
});

it('reports the provider as missing when the application did not register it', function (): void {
    $test = new class('status page without the provider') extends Orchestra
    {
        use WithWorkbench;

        public function statusPage(): string
        {
            $this->setUpTheTestEnvironment();

            try {
                return (string) $this->get('/')->assertOk()->getContent();
            } finally {
                $this->tearDownTheTestEnvironment();
            }
        }
    };

    expect($test->statusPage())->toContain('data-provider-loaded="no"');
});

it('is registered by auto-discovery in the Testbench CLI app that `composer serve` runs', function (): void {
    $root = dirname(__DIR__, 2);
    $testbench = static fn (string ...$arguments): Process => (new Process([PHP_BINARY, $root.'/vendor/bin/testbench', ...$arguments], $root))->mustRun();

    $testbench('package:discover');
    $tinker = $testbench('tinker', '--execute=echo json_encode(app()->providerIsLoaded('.var_export(SystemOneServiceProvider::class, true).'));');

    expect(trim($tinker->getOutput()))->toEndWith('true');
});
