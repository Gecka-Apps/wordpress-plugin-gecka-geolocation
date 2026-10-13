<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Gecka <contact@gecka.nc>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Gecka\WP\Plugin\Geolocation;

/**
 * Where the providers keep their data: a directory of the uploads of the
 * main site, shared by every site of a network, whose name ends with a
 * random key, and a folder in it for each provider.
 *
 * The licences of the databases forbid handing them to third parties. The
 * directory carries a .htaccess denying every request, which Apache honours;
 * other servers ignore it, and the random part of the name keeps the files
 * from being found by guessing. The GECKA_GEOLOCATION_DIRECTORY constant, or
 * the gecka_geolocation_directory filter, moves them out of the web root.
 *
 * @author Laurent Dinclaux - Gecka <laurent@gecka.nc>
 */
class Storage
{
    /**
     * Network option holding the random part of the name of the directory.
     */
    public const KEY_OPTION = 'gecka_geolocation_directory';

    /**
     * Prefix of the name of the directory under uploads.
     */
    public const PREFIX = 'gecka-geolocation-';

    /**
     * Directory of the data, or the folder of a provider in it
     *
     * @param string $provider Identifier of a provider, empty for the directory itself.
     *
     * @return string
     */
    public static function directory(string $provider = ''): string
    {
        $constant = defined('GECKA_GEOLOCATION_DIRECTORY') ? Value::string(constant('GECKA_GEOLOCATION_DIRECTORY')) : '';

        // A constant holding anything but an absolute path, as an empty
        // string, would send the databases to the root of the file system
        // or next to the script running.
        $directory = $constant !== '' && path_is_absolute($constant)
            ? $constant
            : self::uploads() . '/' . self::PREFIX . self::key();

        /**
         * Filters the directory the providers keep their data in. It is
         * created when missing, each provider in a folder of its own.
         *
         * @param string $directory
         */
        $directory = untrailingslashit(Value::string(apply_filters('gecka_geolocation_directory', $directory), $directory));

        return $provider !== '' ? $directory . '/' . sanitize_key($provider) : $directory;
    }

    /**
     * Deletes the .part files of a directory older than an age: downloads a
     * request left behind when it died.
     *
     * @param string $directory
     * @param int    $age Seconds; 0 deletes every one.
     *
     * @return void
     */
    public static function sweep(string $directory, int $age): void
    {
        foreach ((array) glob($directory . '/*.part') as $part) {
            if (is_string($part) && time() - (int) filemtime($part) >= $age) {
                wp_delete_file($part);
            }
        }
    }

    /**
     * Writes the index.php that keeps a folder from being listed, when
     * missing.
     *
     * @param string $directory
     *
     * @return void
     */
    public static function index(string $directory): void
    {
        if (! is_file($directory . '/index.php')) {
            file_put_contents($directory . '/index.php', "<?php\n// Silence is golden.\n"); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- A small file written once.
        }
    }

    /**
     * Whether the directory is the one the plugin names under uploads, which
     * holds nothing but what the providers wrote
     *
     * @return bool
     */
    public static function owned(): bool
    {
        return self::directory() === self::uploads() . '/' . self::PREFIX . self::key();
    }

    /**
     * Creates the directory, protected, and the folder of a provider in it,
     * when missing.
     *
     * @param string $provider Identifier of a provider, empty for the directory itself.
     *
     * @return string The directory, or the folder of the provider.
     *
     * @throws \RuntimeException When it cannot be written.
     */
    public static function prepare(string $provider = ''): string
    {
        $root = self::directory();
        $directory = self::directory($provider);
        $protect = self::owned() || ! is_dir($root);

        if (! wp_mkdir_p($directory) || ! wp_is_writable($directory)) {
            /* translators: %s: directory on the server */
            throw new \RuntimeException(esc_html(sprintf(__('Cannot write in %s.', 'gecka-geolocation'), $directory)));
        }

        // A directory the site chose may serve other things: the files
        // denying access go only in one the plugin made.
        if ($protect) {
            if (! is_file($root . '/.htaccess')) {
                file_put_contents($root . '/.htaccess', "Require all denied\n"); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- A small file written once.
            }

            foreach (array_unique([$root, $directory]) as $folder) {
                self::index($folder);
            }
        }

        return $directory;
    }

    /**
     * Deletes the folder of a provider and everything in it; without a
     * provider, the directory and everything in it when it is the one under
     * uploads, nothing otherwise.
     *
     * @param string $provider
     *
     * @return void
     */
    public static function delete(string $provider = ''): void
    {
        if ($provider === '' && ! self::owned()) {
            return;
        }

        self::deleteTree(self::directory($provider));
    }

    /**
     * Removes a directory and everything in it, if it exists.
     *
     * @param string $directory
     *
     * @return void
     */
    public static function deleteTree(string $directory): void
    {
        // A link is removed, never what it points to.
        if (is_link($directory)) {
            wp_delete_file($directory);

            return;
        }

        if (! is_dir($directory)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);

        // phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Folders of the plugin, emptied first.
        foreach ($items as $item) {
            /** @var \SplFileInfo $item */
            $item->isDir() ? rmdir($item->getPathname()) : wp_delete_file($item->getPathname());
        }

        rmdir($directory);
        // phpcs:enable
    }

    /**
     * Address of the directory on the web, when it is the one under
     * uploads: where a web server ignoring .htaccess would serve it from
     *
     * @return string|null Null for a directory the site chose.
     */
    public static function url(): ?string
    {
        if (! self::owned()) {
            return null;
        }

        return untrailingslashit(Value::string(Setup::onMainSite(static fn(): mixed => wp_upload_dir(null, false)['baseurl']))) . '/' . self::PREFIX . self::key();
    }

    /**
     * Uploads directory of the main site, where every site of a network
     * keeps the same data
     *
     * @return string
     */
    private static function uploads(): string
    {
        return untrailingslashit(Value::string(Setup::onMainSite(static fn(): mixed => wp_upload_dir(null, false)['basedir'])));
    }

    /**
     * Random part of the name of the directory, drawn once
     *
     * @return string
     */
    private static function key(): string
    {
        $key = Value::string(get_site_option(self::KEY_OPTION, ''));

        if (! preg_match('/^[a-z0-9]{16}$/', $key)) {
            $key = strtolower(wp_generate_password(16, false));
            update_site_option(self::KEY_OPTION, $key);
        }

        return $key;
    }
}
