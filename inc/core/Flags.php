<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Gecka <contact@gecka.nc>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Gecka\WP\Plugin\Geolocation;

/**
 * Flags of the countries, as an emoji and as an image.
 *
 * The emoji is the pair of regional indicator symbols of the ISO 3166-1
 * code, which the system draws: Windows shows the two letters instead. The
 * images are the SVG files of flag-icons (MIT), shipped in assets/flags, in
 * two shapes: 4x3 and square (1x1).
 *
 * @author Laurent Dinclaux - Gecka <laurent@gecka.nc>
 */
class Flags
{
    /**
     * Shapes of the images, as their folders name them.
     */
    public const RATIOS = ['4x3', '1x1'];

    /**
     * Emoji of the flag of a country
     *
     * @param string $country ISO 3166-1 alpha-2 code, as FR.
     *
     * @return string|null Null for a code that is not two letters.
     */
    public static function emoji(string $country): ?string
    {
        $country = strtoupper(trim($country));

        if (! preg_match('/^[A-Z]{2}$/', $country)) {
            return null;
        }

        // The regional indicator symbols, U+1F1E6 to U+1F1FF, are the four
        // bytes F0 9F 87 A6 to F0 9F 87 BF in UTF-8.
        return "\xF0\x9F\x87" . chr(0xA6 + ord($country[0]) - 65) . "\xF0\x9F\x87" . chr(0xA6 + ord($country[1]) - 65);
    }

    /**
     * Path of the image of the flag of a country
     *
     * @param string $country ISO 3166-1 alpha-2 code.
     * @param string $ratio   4x3 or 1x1.
     *
     * @return string|null Null when there is no image for the code or the shape.
     */
    public static function path(string $country, string $ratio = '4x3'): ?string
    {
        $country = strtolower(trim($country));

        if (! preg_match('/^[a-z]{2}$/', $country) || ! in_array($ratio, self::RATIOS, true)) {
            return null;
        }

        $path = self::directory() . '/' . $ratio . '/' . $country . '.svg';

        return is_file($path) ? $path : null;
    }

    /**
     * Address of the image of the flag of a country, versioned with the
     * plugin so that a flag redrawn reaches the browsers that kept the old
     * one.
     *
     * Relative to the root of the site by default, as /wp-content/plugins/...:
     * the same whatever the request computing it, a page, WP-Cron or WP-CLI,
     * where the host and the scheme of the site may be unknown or wrong, so
     * it can be stored. Absolute, it is built from the request computing it.
     *
     * @param string $country  ISO 3166-1 alpha-2 code.
     * @param string $ratio    4x3 or 1x1.
     * @param bool   $absolute With the scheme and the host of the site.
     *
     * @return string|null Null when there is no image for the code or the shape.
     */
    public static function url(string $country, string $ratio = '4x3', bool $absolute = false): ?string
    {
        $path = self::path($country, $ratio);

        if ($path === null) {
            return null;
        }

        $url = add_query_arg('ver', Plugin::VERSION, self::fromMainSite(plugins_url('assets/flags/' . $ratio . '/' . basename($path), dirname(__DIR__, 2) . '/bootstrap.php')));

        return $absolute ? $url : wp_make_link_relative($url);
    }

    /**
     * An address of the plugins as the main site of a network gives it. The
     * other sites build theirs from their own address, /second/wp-content/...
     * in a network of subdirectories, which the rewrite rules of WordPress
     * serve but which differs from one site to the next.
     *
     * @param string $url
     *
     * @return string
     */
    private static function fromMainSite(string $url): string
    {
        if (! is_multisite() || is_main_site()) {
            return $url;
        }

        // plugins_url() takes the scheme of the request, site_url() the one
        // of the settings: both are compared under the scheme of the first.
        $scheme = (string) wp_parse_url($url, PHP_URL_SCHEME);
        $site = untrailingslashit(set_url_scheme(site_url(), $scheme ?: null));
        $main = untrailingslashit(set_url_scheme(get_site_url(get_main_site_id()), $scheme ?: null));

        return str_starts_with($url, $site . '/') ? $main . substr($url, strlen($site)) : $url;
    }

    /**
     * Directory of the images
     *
     * @return string
     */
    private static function directory(): string
    {
        return dirname(__DIR__, 2) . '/assets/flags';
    }
}
