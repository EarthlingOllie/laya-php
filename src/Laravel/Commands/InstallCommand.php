<?php

declare(strict_types=1);

namespace EarthlingOllie\SystemOneClassification\Laravel\Commands;

use EarthlingOllie\SystemOneClassification\Exceptions\InvalidConfiguration;
use EarthlingOllie\SystemOneClassification\Laravel\Install\ComposeFiles;
use EarthlingOllie\SystemOneClassification\Laravel\Install\EnvFile;
use EarthlingOllie\SystemOneClassification\Laravel\SystemOneServiceProvider;
use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Application;

use function dirname;
use function in_array;
use function is_string;

/**
 * `php artisan system-one:install [--mode=standalone|sail]`: from `composer require` to a
 * local Laya server.
 *
 * Publishes config/system-one.php, then either writes compose.system-one.yaml (standalone, the
 * default) or adds a `laya` service and its model-cache volume to the app's Sail Compose file,
 * and sets SYSTEM_ONE_URL in .env. It only ever adds: an existing file, service, volume or
 * .env key is left as it is, so running it again changes nothing.
 *
 * Exit codes: 0 done, 1 Sail chosen but its Compose file is missing or cannot be edited
 * safely, 2 an unknown --mode.
 */
final class InstallCommand extends Command
{
    public const string STANDALONE_FILE = 'compose.system-one.yaml';

    /** The names `docker compose` (and so Sail) looks for, in its order of preference. */
    public const array SAIL_FILES = ['compose.yaml', 'compose.yml', 'docker-compose.yaml', 'docker-compose.yml'];

    private const array MODES = ['standalone', 'sail'];

    /** @var string */
    protected $signature = 'system-one:install
        {--mode= : standalone (a compose.system-one.yaml next to the app) or sail (a service in the Sail Compose file)}';

    /** @var string */
    protected $description = 'Publish the System One config and set up a local Laya server (standalone Compose file or Sail)';

    public function handle(Application $app, Filesystem $files): int
    {
        $mode = $this->option('mode');

        if (is_string($mode) && ! in_array($mode, self::MODES, true)) {
            $this->error("Unknown --mode '{$mode}': use standalone or sail.");

            return self::INVALID;
        }

        $this->publishConfig($files);
        $mode ??= $this->input->isInteractive()
            ? $this->choice('Where should the local Laya server run?', self::MODES, 'standalone')
            : 'standalone';
        $compose = new ComposeFiles(dirname(__DIR__, 3));

        return $mode === 'sail' ? $this->installSail($app, $files, $compose) : $this->installStandalone($app, $files, $compose);
    }

    private function publishConfig(Filesystem $files): void
    {
        $target = $this->laravel->configPath('system-one.php');

        if ($files->exists($target)) {
            $this->line('config/system-one.php already exists; left as it is.');

            return;
        }

        $files->ensureDirectoryExists(dirname($target));
        $files->copy(SystemOneServiceProvider::configFile(), $target);
        $this->info('Published config/system-one.php');
    }

    private function installStandalone(Application $app, Filesystem $files, ComposeFiles $compose): int
    {
        $target = $this->laravel->basePath(self::STANDALONE_FILE);

        if ($files->exists($target)) {
            $this->line(self::STANDALONE_FILE.' already exists; left as it is.');
        } else {
            $files->put($target, $compose->standalone($this->laravel->basePath()));
            $this->info('Wrote '.self::STANDALONE_FILE);
        }

        $this->setUrl($app, $files, 'http://localhost:8000');
        $this->line('Start the server (the first start builds the image and downloads the checkpoint):');
        $this->line('  docker compose -f '.self::STANDALONE_FILE.' up -d --wait');
        $this->line('Then check it: php artisan system-one:health');

        return self::SUCCESS;
    }

    private function installSail(Application $app, Filesystem $files, ComposeFiles $compose): int
    {
        $name = $this->sailFile($files);

        if ($name === null) {
            $this->error('No Sail Compose file (compose.yaml, compose.yml, docker-compose.yaml or docker-compose.yml) in the app. Run `php artisan sail:install` first.');

            return self::FAILURE;
        }

        $path = $this->laravel->basePath($name);

        try {
            $updated = $compose->addToSail($files->get($path), $this->laravel->basePath(), $name);
        } catch (InvalidConfiguration $invalid) {
            $this->error($invalid->getMessage());

            return self::FAILURE;
        }

        if ($updated === null) {
            $this->line("{$name} already has the laya service and the ".ComposeFiles::SAIL_VOLUME.' volume; left as it is.');
        } else {
            $files->put($path, $updated);
            $this->info('Added the laya service and the '.ComposeFiles::SAIL_VOLUME." volume to {$name}");
        }

        $this->setUrl($app, $files, 'http://laya:8000');
        $this->line('Start it with the rest of the app (the first start builds the image and downloads the checkpoint):');
        $this->line('  ./vendor/bin/sail up -d');
        $this->line('Then check it: ./vendor/bin/sail artisan system-one:health');

        return self::SUCCESS;
    }

    private function sailFile(Filesystem $files): ?string
    {
        foreach (self::SAIL_FILES as $name) {
            if ($files->exists($this->laravel->basePath($name))) {
                return $name;
            }
        }

        return null;
    }

    private function setUrl(Application $app, Filesystem $files, string $url): void
    {
        $path = $app->environmentFilePath();
        $updated = EnvFile::withDefault($files->exists($path) ? $files->get($path) : '', 'SYSTEM_ONE_URL', $url);

        if ($updated === null) {
            $this->line("SYSTEM_ONE_URL is already set in .env; left as it is. This setup expects SYSTEM_ONE_URL={$url}.");

            return;
        }

        $files->put($path, $updated);
        $this->info("Set SYSTEM_ONE_URL={$url} in .env");
    }
}
