<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Gecka <contact@gecka.nc>
// SPDX-License-Identifier: GPL-3.0-or-later

/*
 * Plugin Name: Gecka Geolocation
 * Plugin URI: https://github.com/Gecka-Apps/wordpress-plugin-gecka-geolocation
 * Description: Downloads the IP geolocation databases of MaxMind (GeoLite or GeoIP®), DB-IP and IPinfo, keeps them up to date, and lets other plugins and WooCommerce locate an IP address on the server.
 * Version: 1.0.1
 * Author: Gecka
 * Author URI: https://gecka.nc
 * License: GPL-3.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-3.0.html
 * Requires at least: 6.8
 * Requires PHP: 8.2
 * Network: true
 * Text Domain: gecka-geolocation
 * Domain Path: /languages/
 */

// Basic security, prevents file from being loaded directly.
defined('ABSPATH') || exit;

use Gecka\WP\Plugin\Geolocation\Countries;
use Gecka\WP\Plugin\Geolocation\Facet;
use Gecka\WP\Plugin\Geolocation\Flags;
use Gecka\WP\Plugin\Geolocation\Plugin;
use Gecka\WP\Plugin\Geolocation\Provider;
use Gecka\WP\Plugin\Geolocation\Record;
use Gecka\WP\Plugin\Geolocation\Setup;

if (! class_exists('\Gecka\WP\Plugin\Geolocation\Plugin')) {
    require_once __DIR__ . '/vendor/autoload.php';
}

/**
 * Plugin instance
 *
 * @return Plugin
 */
function gecka_geolocation_plugin(): Plugin
{
    /** @var Plugin|null $plugin */
    static $plugin = null;

    if (! $plugin) {
        $plugin = new Plugin(__FILE__);
    }

    return $plugin;
}

/**
 * What a provider knows of an IP address: country, city, network,
 * anonymity, as far as its data goes.
 *
 * @param string|null $ip       The address of the visitor when null, as
 *                              gecka_geolocation_client_ip() gives it.
 * @param string|null $provider Identifier of a provider, as maxmind; the
 *                              default provider when null.
 *
 * @return Record|null Null for an invalid address, an unknown provider, or one that answers nothing yet.
 */
function gecka_geolocation(?string $ip = null, ?string $provider = null): ?Record
{
    return gecka_geolocation_plugin()->lookup($ip, $provider);
}

/**
 * ISO 3166-1 code of the country of an IP address, as FR.
 *
 * @param string|null $ip       The address of the visitor when null.
 * @param string|null $provider The default provider when null.
 *
 * @return string|null Null when unknown: an invalid or private address, a provider with no data yet.
 */
function gecka_geolocation_country(?string $ip = null, ?string $provider = null): ?string
{
    return gecka_geolocation($ip, $provider)?->country();
}

/**
 * Name of a country from its code, in the language of the request unless
 * another is asked, as Nouvelle-Calédonie for NC in fr_FR: from the intl
 * extension of PHP, or without it from the translated list of the plugin.
 *
 * @param string      $country ISO 3166-1 alpha-2 code, as NC.
 * @param string|null $locale  Locale of WordPress, as fr_FR.
 *
 * @return string|null Null for a code that is not two letters.
 */
function gecka_geolocation_country_name(string $country, ?string $locale = null): ?string
{
    return Countries::name($country, $locale);
}

/**
 * Emoji of the flag of a country, as 🇫🇷. Windows shows the two letters
 * instead: gecka_geolocation_flag_url() gives an image drawn everywhere.
 *
 * @param string $country ISO 3166-1 alpha-2 code, as FR.
 *
 * @return string|null Null for a code that is not two letters.
 */
function gecka_geolocation_flag(string $country): ?string
{
    return Flags::emoji($country);
}

/**
 * Address of the SVG image of the flag of a country, relative to the root of
 * the site, as /wp-content/plugins/gecka-geolocation/assets/flags/4x3/fr.svg?ver=1.0.0:
 * the same whatever the request computing it, so it can be stored.
 *
 * @param string $country  ISO 3166-1 alpha-2 code, as FR.
 * @param string $ratio    4x3, or 1x1 for a square.
 * @param bool   $absolute With the scheme and the host of the site.
 *
 * @return string|null Null when the plugin has no image for the code.
 */
function gecka_geolocation_flag_url(string $country, string $ratio = '4x3', bool $absolute = false): ?string
{
    return Flags::url($country, $ratio, $absolute);
}

/**
 * Path of the SVG file of the flag of a country on the server, to inline the
 * image or attach it.
 *
 * @param string $country ISO 3166-1 alpha-2 code, as FR.
 * @param string $ratio   4x3, or 1x1 for a square.
 *
 * @return string|null Null when the plugin has no image for the code.
 */
function gecka_geolocation_flag_path(string $country, string $ratio = '4x3'): ?string
{
    return Flags::path($country, $ratio);
}

/**
 * Address of the logo of a network, relative to the root of the site unless
 * asked absolute, once the setting of the logos is on. A logo not downloaded
 * yet is queued, and comes on a later request.
 *
 * @param int  $asn      Autonomous system number, as 17480.
 * @param bool $absolute With the scheme and the host of the site.
 *
 * @return string|null Null while the setting is off, and for a network without a logo or whose logo is not downloaded yet.
 */
function gecka_geolocation_network_logo_url(int $asn, bool $absolute = false): ?string
{
    return gecka_geolocation_plugin()->logos()->url($asn, $absolute);
}

/**
 * Whether a provider answers a facet.
 *
 * @param string      $facet    country, city, asn, isp, connection_type, domain or anonymous.
 * @param string|null $provider The default provider when null.
 *
 * @return bool
 */
function gecka_geolocation_available(string $facet = Facet::COUNTRY, ?string $provider = null): bool
{
    return gecka_geolocation_plugin()->available($facet, $provider);
}

/**
 * The providers of locations, MaxMind, DB-IP, IPinfo and those other plugins
 * add through the gecka_geolocation_providers filter.
 *
 * @return array<string, string> Names by identifier, the default provider first.
 */
function gecka_geolocation_providers(): array
{
    $plugin = gecka_geolocation_plugin();
    $names = array_map(static fn(Provider $provider): string => $provider->label(), $plugin->providers());
    $default = $plugin->defaultProvider();

    return isset($names[$default]) ? [$default => $names[$default]] + $names : $names;
}

/**
 * The credit the licence of the data of a provider asks for, to show where
 * its results are shown or used: the text, as the licence words it, and the
 * address to link.
 *
 * @param string|null $provider The default provider when null.
 *
 * @return array{text: string, url: string|null}|null Null for an unknown provider, or data that asks for none.
 */
function gecka_geolocation_credit(?string $provider = null): ?array
{
    return gecka_geolocation_plugin()->credit($provider);
}

/**
 * Address of the visitor: REMOTE_ADDR, or what the gecka_geolocation_client_ip
 * filter returns.
 *
 * @return string Empty when unknown.
 */
function gecka_geolocation_client_ip(): string
{
    return gecka_geolocation_plugin()->clientIp();
}

add_action(
    'plugins_loaded',
    static function (): void {
        gecka_geolocation_plugin();
    },
);

add_action(
    'init',
    static function (): void {
        load_plugin_textdomain('gecka-geolocation', false, dirname(plugin_basename(__FILE__)) . '/languages'); // phpcs:ignore PluginCheck.CodeAnalysis.DiscouragedFunctions.load_plugin_textdomainFound -- The translations ship in the languages folder of the plugin.
    },
);

Setup::hooks(__FILE__);
