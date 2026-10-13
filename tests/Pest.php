<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Gecka <contact@gecka.nc>
// SPDX-License-Identifier: GPL-3.0-or-later

/*
 * WordPress installed once for the whole run, SQLite standing for MySQL, the
 * plugin loaded as a site loads it. The installation lands in .tests/ unless
 * WP_CORE_DIR names another directory; WP_MULTISITE=1 installs a network
 * instead, in a directory of its own.
 */

use Gecka\WP\Plugin\Geolocation\Mmdb\Provider as MmdbProvider;
use Gecka\WP\Plugin\Geolocation\Settings;
use Gecka\WP\Plugin\Geolocation\Storage;
use Mantle\Testkit\TestCase;

if (! getenv('WP_CORE_DIR')) {
    putenv('WP_CORE_DIR=' . dirname(__DIR__) . (getenv('WP_MULTISITE') === '1' ? '/.tests/wordpress-multisite' : '/.tests/wordpress'));
}

/*
 * The databases of a test live in a directory of their own, removed after
 * it, and no request leaves for a provider unless a test answers it.
 */
define('GEOLOCATION_DIRECTORY', dirname(__DIR__) . (getenv('WP_MULTISITE') === '1' ? '/.tests/geolocation-multisite' : '/.tests/geolocation'));

uses(TestCase::class)
    ->beforeEach(function () {
        remove_tree(GEOLOCATION_DIRECTORY);
        add_filter('gecka_geolocation_directory', static fn(): string => GEOLOCATION_DIRECTORY);
        add_filter('pre_http_request', static fn() => new WP_Error('http_request_blocked', 'No request leaves the tests.'), 1);
        delete_site_option(Settings::OPTION);
        gecka_geolocation_plugin()->flush();
        gecka_geolocation_plugin()->settings()->flush();

        foreach (gecka_geolocation_plugin()->bundled() as $provider) {
            delete_site_option($provider->settings()->option());
            delete_site_option($provider->updater()->option());
            $provider->settings()->flush();
            $provider->updater()->flush();
            $provider->databases()->flush();
        }
    })
    ->afterEach(function () {
        foreach (gecka_geolocation_plugin()->bundled() as $provider) {
            $provider->databases()->flush();
        }

        remove_tree(GEOLOCATION_DIRECTORY);
    })
    ->in(__DIR__);

\Mantle\Testing\manager()
    ->with_sqlite()
    ->with_multisite(getenv('WP_MULTISITE') === '1')
    ->loaded(static function (): void {
        require dirname(__DIR__) . '/bootstrap.php';
    })
    ->install();

/**
 * A provider the plugin downloads the databases of
 *
 * @param string $id
 *
 * @return MmdbProvider
 */
function mmdb(string $id = 'maxmind'): MmdbProvider
{
    $provider = gecka_geolocation_plugin()->mmdb($id);

    if ($provider === null) {
        throw new RuntimeException('No provider ' . $id);
    }

    return $provider;
}

/**
 * Puts a test database in place of an edition of a provider.
 *
 * @param string      $edition
 * @param string|null $fixture  Path of the test database, the test database of MaxMind named after the edition by default.
 * @param string      $provider
 *
 * @return string Path of the file.
 */
function install_fixture(string $edition, ?string $fixture = null, string $provider = 'maxmind'): string
{
    $databases = mmdb($provider)->databases();

    Storage::prepare($provider);
    $databases->forget($edition);
    gecka_geolocation_plugin()->flush();
    copy($fixture ?? fixture_path($edition), $databases->path($edition));

    return $databases->path($edition);
}

/**
 * Path of a test database of MaxMind
 *
 * @param string $name Edition the test database stands for, as GeoIP2-City.
 *
 * @return string
 */
function fixture_path(string $name): string
{
    return __DIR__ . '/fixtures/' . $name . '-Test.mmdb';
}

/**
 * Settings of the MaxMind provider, some of them changed.
 *
 * @param array<string, mixed> $settings
 *
 * @return void
 */
function maxmind_settings(array $settings): void
{
    mmdb()->settings()->save($settings);
}

/**
 * A tar.gz archive holding files, as MaxMind ships its databases.
 *
 * @param array<string, string> $files Contents by name in the archive.
 * @param bool                  $gnu   Write names longer than 100 bytes as GNU long names.
 *
 * @return string The archive.
 */
function make_tar(array $files, bool $gnu = false): string
{
    $tar = '';

    foreach ($files as $name => $content) {
        if ($gnu && strlen($name) > 100) {
            $tar .= tar_header('././@LongLink', strlen($name) + 1, 'L') . str_pad($name . "\0", (int) ceil((strlen($name) + 1) / 512) * 512, "\0");
            $name = substr($name, 0, 100);
        }

        $tar .= tar_header($name, strlen($content), '0') . str_pad($content, (int) ceil(strlen($content) / 512) * 512, "\0");
    }

    return (string) gzencode($tar . str_repeat("\0", 1024));
}

/**
 * A ustar header block.
 *
 * @param string $name
 * @param int    $size
 * @param string $type
 *
 * @return string
 */
function tar_header(string $name, int $size, string $type): string
{
    $header = str_pad($name, 100, "\0")
        . str_pad('0000644', 8, "\0")
        . str_pad('0000000', 8, "\0")
        . str_pad('0000000', 8, "\0")
        . str_pad(sprintf('%011o', $size), 12, "\0")
        . str_pad(sprintf('%011o', 0), 12, "\0")
        . '        '
        . $type
        . str_repeat("\0", 100)
        . "ustar\00000"
        . str_repeat("\0", 247);

    $checksum = array_sum(array_map('ord', str_split($header)));

    return substr_replace($header, sprintf('%06o', $checksum) . "\0 ", 148, 8);
}

/**
 * Answers the requests to a provider and its storage the way they would,
 * and keeps what was asked.
 *
 * @param array<string, array{status?: int, modified?: string, body?: string, location?: string}> $routes Answers by URL; an URL missing gets a 404.
 *
 * @return ArrayObject<int, array{method: string, url: string, authorization: string|null}> The requests sent.
 */
function fake_http(array $routes): ArrayObject
{
    $sent = new ArrayObject();

    add_filter(
        'pre_http_request',
        static function ($preempt, array $args, string $url) use ($routes, $sent) {
            $sent[] = ['method' => $args['method'], 'url' => $url, 'authorization' => $args['headers']['Authorization'] ?? null];
            $route = $routes[$url] ?? ['status' => 404];
            $status = $route['status'] ?? 200;
            $body = $args['method'] === 'HEAD' ? '' : ($route['body'] ?? '');
            $headers = array_filter(['last-modified' => $route['modified'] ?? null, 'location' => $route['location'] ?? null]);

            if (! empty($args['stream']) && $args['filename']) {
                file_put_contents($args['filename'], $body);
            }

            return [
                'headers' => $headers,
                'body' => ! empty($args['stream']) ? '' : $body,
                'response' => ['code' => $status, 'message' => ''],
                'cookies' => [],
                'filename' => $args['filename'] ?? null,
            ];
        },
        5,
        3,
    );

    return $sent;
}

/**
 * Removes a directory and everything in it, if it exists.
 *
 * @param string $directory
 *
 * @return void
 */
function remove_tree(string $directory): void
{
    if (! is_dir($directory)) {
        return;
    }

    $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);

    foreach ($items as $item) {
        $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }

    rmdir($directory);
}

require __DIR__ . '/mmdb.php';
