<?php

declare(strict_types=1);

namespace EarthlingOllie\SystemOneClassification\Laravel\Install;

use EarthlingOllie\SystemOneClassification\Exceptions\InvalidConfiguration;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

use function array_filter;
use function array_is_list;
use function array_key_exists;
use function array_map;
use function array_slice;
use function array_splice;
use function data_get;
use function explode;
use function file_get_contents;
use function implode;
use function is_array;
use function preg_match;
use function rtrim;
use function str_contains;
use function str_repeat;
use function str_replace;
use function str_starts_with;
use function strlen;
use function strspn;
use function substr;
use function substr_count;

/**
 * The Compose files `system-one:install` writes into an app, both built from the package's own
 * docker/compose.yaml so they cannot drift from it:
 *
 * - standalone(): that file, with its build context pointed at the installed package;
 * - addToSail(): its `laya` service, added to the app's Sail file as new lines only, so every
 *   existing line (comments and formatting included) stays as it was.
 */
final readonly class ComposeFiles
{
    public const string SAIL_VOLUME = 'sail-laya';

    private const string CONTEXT_LINE = 'context: ./laya';

    /** Where the package compose file's header comment ends. */
    private const string FIRST_KEY = "\nname: ";

    private const string HF_CACHE = '/home/laya/.cache/huggingface';

    public function __construct(private string $packageDir) {}

    /**
     * The contents of compose.system-one.yaml for an app at $appBasePath.
     *
     * @throws InvalidConfiguration when the package compose file no longer has the expected build context
     */
    public function standalone(string $appBasePath): string
    {
        $source = $this->packageCompose();

        if (substr_count($source, self::CONTEXT_LINE) !== 1) {
            throw InvalidConfiguration::unusableCompose($this->composePath(), "has no '".self::CONTEXT_LINE."' line to point at the installed package");
        }

        if (substr_count($source, self::FIRST_KEY) !== 1) {
            throw InvalidConfiguration::unusableCompose($this->composePath(), "has no single top-level 'name:' line ending its header");
        }

        // Drop the package's own header (it talks about `composer laya:up`, which only exists in the package repo).
        $body = 'name: '.implode('', array_slice(explode(self::FIRST_KEY, $source), 1));

        return <<<'YAML'
            # Local Laya server for this app, written by `php artisan system-one:install`.
            #
            #   docker compose -f compose.system-one.yaml up -d --wait   # start; waits until the checkpoint is loaded
            #   docker compose -f compose.system-one.yaml down           # stop; the weights stay in the volume
            #
            # The first start builds the image and downloads the english checkpoint (~0.85 GB), which
            # takes a while. Every LAYA_* setting below can be overridden from the environment.
            #
            # The Compose project is named system-one in every app, so all the apps on this machine share
            # one server (on port 8000) and one copy of the weights; `down` in any of them stops it for all.

            YAML.str_replace(self::CONTEXT_LINE, 'context: '.$this->contextFor($appBasePath), $body);
    }

    /**
     * The Sail Compose file with a `laya` service and its model-cache volume added, or null when
     * both are already there. An existing `laya` service is kept as it is.
     *
     * @throws InvalidConfiguration when the file is not YAML or not laid out in a way that can be added to safely
     */
    public function addToSail(string $compose, string $appBasePath, string $file = 'The Sail Compose file'): ?string
    {
        $parsed = $this->parse($compose, $file);
        $services = is_array($parsed) ? ($parsed['services'] ?? null) : null;

        if (! is_array($services) || ($services !== [] && array_is_list($services))) {
            throw InvalidConfiguration::unusableCompose($file, 'has no services section');
        }

        $volumes = $parsed['volumes'] ?? [];

        if (! is_array($volumes)) {
            throw InvalidConfiguration::unusableCompose($file, "'volumes' is not a map");
        }

        // Keep a Windows file's CRLF endings: split and join on what the file uses.
        $eol = str_contains($compose, "\r\n") ? "\r\n" : "\n";
        $lines = explode($eol, $compose);

        // array_key_exists, not isset: a bare `laya:` / `sail-laya:` / `volumes:` / `sail:` key parses as null but is still there.
        if (! array_key_exists('laya', $services)) {
            $networks = $parsed['networks'] ?? null;
            $at = self::sectionLine($lines, $file, 'services');
            $service = $this->sailService(is_array($networks) && array_key_exists('sail', $networks), $appBasePath);
            array_splice($lines, $at + 1, 0, self::block(['laya' => $service], self::indentUnder($lines, $at)));
        }

        if (! array_key_exists(self::SAIL_VOLUME, $volumes)) {
            $volume = [self::SAIL_VOLUME => ['driver' => 'local']];

            if (array_key_exists('volumes', $parsed)) {
                $at = self::sectionLine($lines, $file, 'volumes');
                // An empty `volumes:` shows no indentation of its own: follow the services'.
                $indent = self::indentUnder($lines, $at);
                $indent = $indent > 0 ? $indent : self::indentUnder($lines, self::sectionLine($lines, $file, 'services'));
                array_splice($lines, $at + 1, 0, self::block($volume, $indent));
            } else {
                $indent = self::indentUnder($lines, self::sectionLine($lines, $file, 'services'));
                $lines = [...explode($eol, rtrim(implode($eol, $lines), "\r\n")), 'volumes:', ...self::block($volume, $indent), ''];
            }
        }

        $updated = implode($eol, $lines);

        return $updated === $compose ? null : $updated;
    }

    /** @return array<mixed> the package's `laya` service, adapted to live in a Sail file */
    private function sailService(bool $sailNetwork, string $appBasePath): array
    {
        $parsed = $this->parse($this->packageCompose(), $this->composePath());
        $service = data_get($parsed, 'services.laya');

        if (! is_array($service)) {
            throw InvalidConfiguration::unusableCompose($this->composePath(), 'has no laya service');
        }

        $service['build'] = ['context' => $this->contextFor($appBasePath)];
        $service['volumes'] = [self::SAIL_VOLUME.':'.self::HF_CACHE];

        if ($sailNetwork) {
            $service['networks'] = ['sail'];
        }

        return $service;
    }

    /**
     * The index of the top-level block-style `$section:` line, where new entries go right after.
     *
     * @param  list<string>  $lines
     *
     * @throws InvalidConfiguration when there is no such line
     */
    private static function sectionLine(array $lines, string $file, string $section): int
    {
        foreach ($lines as $i => $line) {
            if (preg_match('/^'.$section.':[ \t]*(?:#.*)?$/', $line) === 1) {
                return $i;
            }
        }

        throw InvalidConfiguration::unusableCompose($file, "has no block-style '{$section}:' line to add to");
    }

    /**
     * How far the entries of the section whose key is on line $at are indented: as far as its
     * first line that is neither blank nor a comment, or 0 when the section has no entries.
     *
     * A block-style map of services always has an entry, so only `volumes:` can give 0.
     *
     * @param  list<string>  $lines
     */
    private static function indentUnder(array $lines, int $at): int
    {
        $entries = array_filter(
            array_slice($lines, $at + 1),
            static fn (string $line): bool => preg_match('/^[ \t]*(?:#.*)?$/', $line) !== 1,
        );

        // No entry is blank, so the leading spaces counted all belong to the first one.
        return strspn(implode('', $entries), ' ');
    }

    /**
     * $entries as YAML lines, nested and indented by $indent spaces like the section they join.
     *
     * @param  array<mixed>  $entries
     * @return list<string>
     */
    private static function block(array $entries, int $indent): array
    {
        $pad = str_repeat(' ', $indent);

        return array_map(
            static fn (string $line): string => $pad.$line,
            explode("\n", rtrim(Yaml::dump($entries, PHP_INT_MAX, $indent), "\n")),
        );
    }

    /** The package's docker/laya directory as an app at $appBasePath should name it. */
    private function contextFor(string $appBasePath): string
    {
        $dir = $this->packageDir.'/docker/laya';
        $base = rtrim($appBasePath, '/').'/';

        return str_starts_with($dir, $base) ? './'.substr($dir, strlen($base)) : $dir;
    }

    private function parse(string $yaml, string $what): mixed
    {
        try {
            // Compose's merge tags (`!reset`, `!override`) are valid in an app's file.
            return Yaml::parse($yaml, Yaml::PARSE_CUSTOM_TAGS);
        } catch (ParseException $exception) {
            throw InvalidConfiguration::unusableCompose($what, 'is not valid YAML: '.$exception->getMessage());
        }
    }

    private function packageCompose(): string
    {
        return (string) file_get_contents($this->composePath());
    }

    private function composePath(): string
    {
        return $this->packageDir.'/docker/compose.yaml';
    }
}
