<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Gecka <contact@gecka.nc>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Gecka\WP\Plugin\Geolocation;

use Gecka\WP\Plugin\Geolocation\Mmdb\Archive;

/**
 * Reads a file out of a .tar.gz archive, as MaxMind ships its databases:
 * a folder named after the edition and the date, holding the .mmdb file
 * and its licence.
 *
 * The archive is read as a stream through zlib, 512 bytes at a time, so
 * that a database of several hundred megabytes never sits in memory. It
 * needs zlib only, not the phar extension. Understands ustar archives, GNU
 * long names and pax headers. What the archive unpacks to is bounded: a
 * name entry at NAME_BYTES, the whole at Archive::MAX_BYTES.
 *
 * @author Laurent Dinclaux - Gecka <laurent@gecka.nc>
 */
class Tar
{
    /**
     * Size of a block of the archive.
     */
    private const BLOCK = 512;

    /**
     * Bytes copied at a time.
     */
    private const CHUNK = 1048576;

    /**
     * Bytes a long name or a pax header holds at most.
     */
    private const NAME_BYTES = 65536;

    /**
     * Bytes read from the archive so far, bounded by Archive::MAX_BYTES
     * plus the headers.
     *
     * @var int
     */
    private static int $consumed = 0;

    /**
     * Copies the first regular file of an archive whose name ends with
     * $suffix to $target.
     *
     * @param string $archive Path of the .tar.gz file.
     * @param string $suffix  End of the name of the file wanted, as .mmdb.
     * @param string $target  Path to write it to.
     *
     * @return string Name of the file in the archive.
     *
     * @throws \RuntimeException When the archive cannot be read or holds no such file.
     */
    public static function extract(string $archive, string $suffix, string $target): string
    {
        if (! function_exists('gzopen')) {
            throw new \RuntimeException(esc_html__('PHP lacks the zlib extension, which reads the archives.', 'gecka-geolocation'));
        }

        $in = gzopen($archive, 'rb');

        if ($in === false) {
            /* translators: %s: file on the server */
            throw new \RuntimeException(esc_html(sprintf(__('Cannot read %s.', 'gecka-geolocation'), $archive)));
        }

        try {
            $longName = null;
            self::$consumed = 0;

            for (;;) {
                $header = self::read($in, self::BLOCK);

                if ($header === '' || trim($header, "\0") === '') {
                    break;
                }

                if (strlen($header) !== self::BLOCK) {
                    throw new \RuntimeException(esc_html__('The archive is truncated.', 'gecka-geolocation'));
                }

                $size = self::size(substr($header, 124, 12));
                $type = $header[156];
                $name = $longName ?? self::name($header);
                $longName = null;

                if ($type === 'L' || $type === 'x') {
                    if ($size > self::NAME_BYTES) {
                        throw new \RuntimeException(esc_html__('The archive declares a name too long to read.', 'gecka-geolocation'));
                    }

                    $data = self::read($in, $size);
                    self::skip($in, self::padding($size));
                    $longName = $type === 'L' ? rtrim($data, "\0") : self::paxPath($data);

                    continue;
                }

                if (($type === '0' || $type === "\0") && str_ends_with($name, $suffix)) {
                    self::copy($in, $size, $target);

                    return $name;
                }

                self::skip($in, $size + self::padding($size));
            }
        } finally {
            gzclose($in);
        }

        /* translators: %s: end of a file name, as .mmdb */
        throw new \RuntimeException(esc_html(sprintf(__('The archive holds no %s file.', 'gecka-geolocation'), $suffix)));
    }

    /**
     * Name of the entry of a header: the prefix of ustar, then the name
     *
     * @param string $header
     *
     * @return string
     */
    private static function name(string $header): string
    {
        $name = rtrim(substr($header, 0, 100), "\0");
        $prefix = substr($header, 257, 5) === 'ustar' ? rtrim(substr($header, 345, 155), "\0") : '';

        return $prefix !== '' ? $prefix . '/' . $name : $name;
    }

    /**
     * Size of an entry: octal digits, or a big-endian number when the first
     * byte has its high bit set
     *
     * @param string $field
     *
     * @return int
     *
     * @throws \RuntimeException When the size does not fit an integer.
     */
    private static function size(string $field): int
    {
        if ((ord($field[0]) & 0x80) !== 0) {
            $size = ord($field[0]) & 0x7f;

            for ($i = 1; $i < strlen($field); $i++) {
                if ($size > intdiv(PHP_INT_MAX - 255, 256)) {
                    throw new \RuntimeException(esc_html__('The archive declares a file too large to read.', 'gecka-geolocation'));
                }

                $size = $size * 256 + ord($field[$i]);
            }

            return $size;
        }

        $digits = trim($field, " \0");

        if (! preg_match('/^[0-7]*$/', $digits)) {
            throw new \RuntimeException(esc_html__('The archive is malformed.', 'gecka-geolocation'));
        }

        $size = octdec($digits);

        if (! is_int($size)) {
            throw new \RuntimeException(esc_html__('The archive declares a file too large to read.', 'gecka-geolocation'));
        }

        return $size;
    }

    /**
     * The path a pax header gives, null when it gives none
     *
     * @param string $data Records as "length key=value\n".
     *
     * @return string|null
     */
    private static function paxPath(string $data): ?string
    {
        return preg_match('/^\d+ path=(.*)$/m', $data, $match) ? $match[1] : null;
    }

    /**
     * Bytes of padding after an entry of $size bytes
     *
     * @param int $size
     *
     * @return int
     */
    private static function padding(int $size): int
    {
        return (self::BLOCK - $size % self::BLOCK) % self::BLOCK;
    }

    /**
     * Reads $length bytes, fewer at the end of the archive.
     *
     * @param resource $in
     * @param int      $length
     *
     * @return string
     */
    private static function read($in, int $length): string
    {
        $data = '';

        while ($length > 0 && ! gzeof($in)) {
            $chunk = gzread($in, min($length, self::CHUNK));

            if ($chunk === false || $chunk === '') {
                break;
            }

            $data .= $chunk;
            $length -= strlen($chunk);
            self::$consumed += strlen($chunk);

            if (self::$consumed > Archive::MAX_BYTES + self::NAME_BYTES) {
                throw new \RuntimeException(esc_html__('The database unpacks to more than the plugin accepts.', 'gecka-geolocation'));
            }
        }

        return $data;
    }

    /**
     * Skips $length bytes.
     *
     * @param resource $in
     * @param int      $length
     *
     * @return void
     *
     * @throws \RuntimeException When the archive ends first.
     */
    private static function skip($in, int $length): void
    {
        while ($length > 0) {
            $chunk = self::read($in, min($length, self::CHUNK));

            if ($chunk === '') {
                throw new \RuntimeException(esc_html__('The archive is truncated.', 'gecka-geolocation'));
            }

            $length -= strlen($chunk);
        }
    }

    /**
     * Copies $size bytes to a file.
     *
     * @param resource $in
     * @param int      $size
     * @param string   $target
     *
     * @return void
     *
     * @throws \RuntimeException When the archive ends first, the file cannot be written, or it is larger than Archive::MAX_BYTES.
     */
    private static function copy($in, int $size, string $target): void
    {
        if ($size > Archive::MAX_BYTES) {
            throw new \RuntimeException(esc_html__('The database unpacks to more than the plugin accepts.', 'gecka-geolocation'));
        }

        // phpcs:disable WordPress.WP.AlternativeFunctions -- A file of the directory of the databases, written as a stream.
        $out = fopen($target, 'wb');

        if ($out === false) {
            /* translators: %s: file on the server */
            throw new \RuntimeException(esc_html(sprintf(__('Cannot write %s.', 'gecka-geolocation'), $target)));
        }

        try {
            while ($size > 0) {
                $chunk = self::read($in, min($size, self::CHUNK));

                if ($chunk === '') {
                    throw new \RuntimeException(esc_html__('The archive is truncated.', 'gecka-geolocation'));
                }

                if (fwrite($out, $chunk) !== strlen($chunk)) {
                    /* translators: %s: file on the server */
                    throw new \RuntimeException(esc_html(sprintf(__('Cannot write %s.', 'gecka-geolocation'), $target)));
                }

                $size -= strlen($chunk);
            }
        } finally {
            fclose($out);
        }
        // phpcs:enable
    }
}
