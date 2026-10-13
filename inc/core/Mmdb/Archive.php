<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Gecka <contact@gecka.nc>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Gecka\WP\Plugin\Geolocation\Mmdb;

use Gecka\WP\Plugin\Geolocation\Tar;

/**
 * Takes the .mmdb file out of what a provider serves: a .tar.gz archive, a
 * gzipped file, or the file itself.
 *
 * @author Laurent Dinclaux - Gecka <laurent@gecka.nc>
 */
final class Archive
{
    /**
     * A .tar.gz archive holding the database among other files.
     */
    public const TAR_GZ = 'tar.gz';

    /**
     * The database compressed with gzip.
     */
    public const GZ = 'gz';

    /**
     * The database itself, or gzipped when its first bytes say so.
     */
    public const MMDB = 'mmdb';

    /**
     * Bytes a database may weigh at most once unpacked.
     */
    public const MAX_BYTES = 2147483648;

    /**
     * Bytes copied at a time.
     */
    private const CHUNK = 1048576;

    /**
     * Writes the database a download holds to $target.
     *
     * @param string $format   One of the constants.
     * @param string $download Path of the download.
     * @param string $target
     *
     * @return void
     *
     * @throws \RuntimeException When the download cannot be read.
     */
    public static function extract(string $format, string $download, string $target): void
    {
        match ($format) {
            self::TAR_GZ => Tar::extract($download, '.mmdb', $target),
            self::GZ => self::gunzip($download, $target),
            default => self::gzipped($download) ? self::gunzip($download, $target) : self::copy($download, $target),
        };
    }

    /**
     * Decompresses a gzipped file, as a stream.
     *
     * @param string $download
     * @param string $target
     *
     * @return void
     *
     * @throws \RuntimeException When zlib is missing, a file cannot be read or written, or the file unpacks past MAX_BYTES.
     */
    private static function gunzip(string $download, string $target): void
    {
        if (! function_exists('gzopen')) {
            throw new \RuntimeException(esc_html__('PHP lacks the zlib extension, which reads the archives.', 'gecka-geolocation'));
        }

        $in = gzopen($download, 'rb');

        // phpcs:disable WordPress.WP.AlternativeFunctions -- Files of the directory of the databases, written as a stream.
        $out = $in !== false ? fopen($target, 'wb') : false;

        if ($in === false || $out === false) {
            /* translators: %s: file on the server */
            throw new \RuntimeException(esc_html(sprintf(__('Cannot read %s.', 'gecka-geolocation'), $download)));
        }

        try {
            $written = 0;

            while (! gzeof($in)) {
                $chunk = gzread($in, self::CHUNK);

                if ($chunk === false || fwrite($out, $chunk) !== strlen($chunk)) {
                    /* translators: %s: file on the server */
                    throw new \RuntimeException(esc_html(sprintf(__('Cannot write %s.', 'gecka-geolocation'), $target)));
                }

                $written += strlen($chunk);

                if ($written > self::MAX_BYTES) {
                    throw new \RuntimeException(esc_html__('The database unpacks to more than the plugin accepts.', 'gecka-geolocation'));
                }
            }
        } finally {
            gzclose($in);
            fclose($out);
        }
        // phpcs:enable
    }

    /**
     * Whether a file starts with the magic bytes of gzip
     *
     * @param string $file
     *
     * @return bool
     */
    private static function gzipped(string $file): bool
    {
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Two bytes of a local file.
        return file_get_contents($file, false, null, 0, 2) === "\x1f\x8b";
    }

    /**
     * Copies the database as it came.
     *
     * @param string $download
     * @param string $target
     *
     * @return void
     *
     * @throws \RuntimeException When the file cannot be written.
     */
    private static function copy(string $download, string $target): void
    {
        if (! copy($download, $target)) {
            /* translators: %s: file on the server */
            throw new \RuntimeException(esc_html(sprintf(__('Cannot write %s.', 'gecka-geolocation'), $target)));
        }
    }
}
