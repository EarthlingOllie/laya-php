<?php

declare(strict_types=1);

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Symfony\Component\Yaml\Yaml;

/*
 * Each test installs into a throwaway app directory, never into Testbench's shared skeleton.
 */

beforeEach(function (): void {
    $this->appDir = sys_get_temp_dir().'/system-one-install-'.bin2hex(random_bytes(6));
    (new Filesystem)->ensureDirectoryExists($this->appDir.'/config');
    file_put_contents($this->appDir.'/.env', "APP_NAME=Demo\n");
    $this->app?->setBasePath($this->appDir);
});

afterEach(function (): void {
    (new Filesystem)->deleteDirectory($this->appDir);
});

function appFile(string $dir, string $name): string
{
    return (string) file_get_contents($dir.'/'.$name);
}

/** @return array<mixed> */
function appYaml(string $dir, string $name): array
{
    $parsed = Yaml::parseFile($dir.'/'.$name);
    assert(is_array($parsed));

    return $parsed;
}

function useSailFixture(string $dir, string $fixture = 'docker-compose.yml', string $as = 'docker-compose.yml'): string
{
    $contents = (string) file_get_contents(dirname(__DIR__, 2).'/Fixtures/sail/'.$fixture);
    file_put_contents($dir.'/'.$as, $contents);

    return $contents;
}

it('installs standalone by default: config, Compose file and SYSTEM_ONE_URL', function (): void {
    $this->artisan('system-one:install')
        ->expectsChoice('Where should the local Laya server run?', 'standalone', ['standalone', 'sail'])
        ->expectsOutputToContain('Published config/system-one.php')
        ->expectsOutputToContain('Wrote compose.system-one.yaml')
        ->expectsOutputToContain('Set SYSTEM_ONE_URL=http://localhost:8000 in .env')
        ->expectsOutput('Start the server (the first start builds the image and downloads the checkpoint):')
        ->expectsOutput('  docker compose -f compose.system-one.yaml up -d --wait')
        ->expectsOutput('Then check it: php artisan system-one:health')
        ->assertExitCode(Command::SUCCESS);

    expect(appFile($this->appDir, 'config/system-one.php'))->toBe(file_get_contents(dirname(__DIR__, 3).'/config/system-one.php'))
        ->and(appYaml($this->appDir, 'compose.system-one.yaml')['services']['laya']['build']['context'])->toBe(dirname(__DIR__, 3).'/docker/laya')
        ->and(appFile($this->appDir, '.env'))->toBe("APP_NAME=Demo\n\nSYSTEM_ONE_URL=http://localhost:8000\n");
});

it('installs standalone without asking under --no-interaction or --mode=standalone', function (array $options): void {
    $this->artisan('system-one:install', $options)->assertExitCode(Command::SUCCESS);

    expect(file_exists($this->appDir.'/compose.system-one.yaml'))->toBeTrue()
        ->and(appFile($this->appDir, '.env'))->toContain('SYSTEM_ONE_URL=http://localhost:8000');
})->with([
    'no interaction' => [['--no-interaction' => true]],
    'mode option' => [['--mode' => 'standalone']],
]);

it('changes nothing when standalone is installed twice, and keeps what was already there', function (): void {
    $this->artisan('system-one:install', ['--mode' => 'standalone'])->assertExitCode(Command::SUCCESS);
    file_put_contents($this->appDir.'/config/system-one.php', '<?php return []; // mine');
    file_put_contents($this->appDir.'/compose.system-one.yaml', "# mine\n");
    $env = appFile($this->appDir, '.env');

    $this->artisan('system-one:install', ['--mode' => 'standalone'])
        ->expectsOutputToContain('config/system-one.php already exists; left as it is.')
        ->expectsOutputToContain('compose.system-one.yaml already exists; left as it is.')
        ->expectsOutputToContain('SYSTEM_ONE_URL is already set in .env; left as it is.')
        ->assertExitCode(Command::SUCCESS);

    expect(appFile($this->appDir, 'config/system-one.php'))->toBe('<?php return []; // mine')
        ->and(appFile($this->appDir, 'compose.system-one.yaml'))->toBe("# mine\n")
        ->and(appFile($this->appDir, '.env'))->toBe($env);
});

it('creates the config directory when the app has none', function (): void {
    rmdir($this->appDir.'/config');

    $this->artisan('system-one:install', ['--mode' => 'standalone'])->assertExitCode(Command::SUCCESS);

    expect(file_exists($this->appDir.'/config/system-one.php'))->toBeTrue();
});

it('creates .env when the app has none', function (): void {
    unlink($this->appDir.'/.env');

    $this->artisan('system-one:install', ['--mode' => 'standalone'])->assertExitCode(Command::SUCCESS);

    expect(appFile($this->appDir, '.env'))->toBe("SYSTEM_ONE_URL=http://localhost:8000\n");
});

it('adds a laya service to the Sail file when Sail is chosen', function (): void {
    $original = useSailFixture($this->appDir);

    $this->artisan('system-one:install')
        ->expectsChoice('Where should the local Laya server run?', 'sail', ['standalone', 'sail'])
        ->expectsOutputToContain('Added the laya service and the sail-laya volume to docker-compose.yml')
        ->expectsOutputToContain('Set SYSTEM_ONE_URL=http://laya:8000 in .env')
        ->expectsOutput('Start it with the rest of the app (the first start builds the image and downloads the checkpoint):')
        ->expectsOutput('  ./vendor/bin/sail up -d')
        ->expectsOutput('Then check it: ./vendor/bin/sail artisan system-one:health')
        ->assertExitCode(Command::SUCCESS);

    $compose = appYaml($this->appDir, 'docker-compose.yml');
    $before = Yaml::parse($original);
    assert(is_array($before));

    expect($compose['services']['laravel.test'])->toBe($before['services']['laravel.test'])
        ->and($compose['services']['mysql'])->toBe($before['services']['mysql'])
        ->and($compose['services']['laya']['networks'])->toBe(['sail'])
        ->and($compose['services']['laya']['volumes'])->toBe(['sail-laya:/home/laya/.cache/huggingface'])
        ->and($compose['volumes'])->toHaveKeys(['sail-mysql', 'sail-laya'])
        ->and(file_exists($this->appDir.'/compose.system-one.yaml'))->toBeFalse()
        ->and(appFile($this->appDir, '.env'))->toBe("APP_NAME=Demo\n\nSYSTEM_ONE_URL=http://laya:8000\n");
});

it('changes nothing when Sail is installed twice', function (): void {
    useSailFixture($this->appDir, 'no-volumes.yml');
    $this->artisan('system-one:install', ['--mode' => 'sail'])->assertExitCode(Command::SUCCESS);
    $compose = appFile($this->appDir, 'docker-compose.yml');
    $env = appFile($this->appDir, '.env');

    $this->artisan('system-one:install', ['--mode' => 'sail'])
        ->expectsOutputToContain('docker-compose.yml already has the laya service and the sail-laya volume; left as it is.')
        ->expectsOutputToContain('SYSTEM_ONE_URL is already set in .env; left as it is.')
        ->assertExitCode(Command::SUCCESS);

    expect(appFile($this->appDir, 'docker-compose.yml'))->toBe($compose)
        ->and(appFile($this->appDir, '.env'))->toBe($env);
});

it('finds the Sail file under the other names Compose accepts', function (string $name): void {
    useSailFixture($this->appDir, as: $name);

    $this->artisan('system-one:install', ['--mode' => 'sail'])
        ->expectsOutputToContain("to {$name}")
        ->assertExitCode(Command::SUCCESS);

    expect(appYaml($this->appDir, $name)['services'])->toHaveKey('laya');
})->with(['compose.yaml', 'compose.yml', 'docker-compose.yaml']);

it('edits the file docker compose itself would pick when there are several', function (): void {
    useSailFixture($this->appDir, as: 'docker-compose.yml');
    $other = useSailFixture($this->appDir, as: 'compose.yaml');

    $this->artisan('system-one:install', ['--mode' => 'sail'])
        ->expectsOutputToContain('to compose.yaml')
        ->assertExitCode(Command::SUCCESS);

    expect(appYaml($this->appDir, 'compose.yaml')['services'])->toHaveKey('laya')
        ->and(appFile($this->appDir, 'docker-compose.yml'))->toBe($other);
});

it('keeps a SYSTEM_ONE_URL that is already set and says what Sail expects', function (): void {
    file_put_contents($this->appDir.'/.env', "SYSTEM_ONE_URL=http://localhost:8000\n");
    useSailFixture($this->appDir);

    $this->artisan('system-one:install', ['--mode' => 'sail'])
        ->expectsOutputToContain('SYSTEM_ONE_URL is already set in .env; left as it is. This setup expects SYSTEM_ONE_URL=http://laya:8000.')
        ->assertExitCode(Command::SUCCESS);

    expect(appFile($this->appDir, '.env'))->toBe("SYSTEM_ONE_URL=http://localhost:8000\n");
});

it('exits with 1 for Sail when the app has no Sail Compose file', function (): void {
    $this->artisan('system-one:install', ['--mode' => 'sail'])
        ->expectsOutputToContain('No Sail Compose file (compose.yaml, compose.yml, docker-compose.yaml or docker-compose.yml) in the app. Run `php artisan sail:install` first.')
        ->assertExitCode(Command::FAILURE);

    expect(appFile($this->appDir, '.env'))->toBe("APP_NAME=Demo\n");
});

it('exits with 1 for a Sail file it cannot safely edit, leaving it untouched', function (): void {
    file_put_contents($this->appDir.'/docker-compose.yml', "services: {app: {image: x}}\n");

    $this->artisan('system-one:install', ['--mode' => 'sail'])
        ->expectsOutputToContain("docker-compose.yml has no block-style 'services:' line to add to.")
        ->assertExitCode(Command::FAILURE);

    expect(appFile($this->appDir, 'docker-compose.yml'))->toBe("services: {app: {image: x}}\n")
        ->and(appFile($this->appDir, '.env'))->toBe("APP_NAME=Demo\n");
});

it('exits with 2 for an unknown --mode, before changing anything', function (): void {
    $this->artisan('system-one:install', ['--mode' => 'kubernetes'])
        ->expectsOutputToContain("Unknown --mode 'kubernetes': use standalone or sail.")
        ->assertExitCode(Command::INVALID);

    expect(file_exists($this->appDir.'/config/system-one.php'))->toBeFalse();
});
