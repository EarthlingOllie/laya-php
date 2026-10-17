<?php

declare(strict_types=1);

/*
 * Links Testbench's Laravel skeleton to this vendor/ before `package:discover` runs (see
 * composer.json's post-autoload-dump).
 *
 * Whatever creates that link first also rebuilds the skeleton's package manifest, from an
 * app that discovers no packages. On a fresh `composer install` (as in CI) that is Larastan's
 * first boot: the manifest loses this package's service provider, Larastan no longer sees the
 * Artisan commands, and it cannot type their options. Creating the link here first, before
 * discovering, keeps the provider in the manifest.
 */

use Orchestra\Testbench\Foundation\Application;

require dirname(__DIR__).'/vendor/autoload.php';

Application::createVendorSymlink(null, dirname(__DIR__).'/vendor');
