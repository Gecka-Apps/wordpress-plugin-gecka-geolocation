<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Gecka <contact@gecka.nc>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Gecka\WP\Plugin\Geolocation\Integrations;

use Gecka\WP\Plugin\Geolocation\Base;
use Gecka\WP\Plugin\Geolocation\Value;

/**
 * Locates the customers of WooCommerce with the databases of the plugin,
 * when the setting asks for it.
 *
 * WooCommerce asks its woocommerce_get_geolocation filter for the country,
 * state, city and postal code of an address, wherever it geolocates: the
 * default customer location set to "Geolocate", taxes, shipping. Its own
 * MaxMind integration answers that filter at the default priority, and
 * leaves alone an answer that already holds a country: this one runs
 * before, so WooCommerce needs no licence key of its own. A country a CDN
 * header gave WooCommerce is kept.
 *
 * @author Laurent Dinclaux - Gecka <laurent@gecka.nc>
 */
class WooCommerce extends Base
{
    /**
     * Priority on woocommerce_get_geolocation, ahead of the MaxMind
     * integration of WooCommerce.
     */
    public const PRIORITY = 5;

    /**
     * @return void
     */
    public function setup(): void
    {
        add_filter('woocommerce_get_geolocation', [$this, 'geolocation'], self::PRIORITY, 2);
    }

    /**
     * Whether WooCommerce is active
     *
     * @return bool
     */
    public static function available(): bool
    {
        return class_exists('WooCommerce');
    }

    /**
     * Whether WooCommerce is active and the setting asks to locate its
     * customers
     *
     * @return bool
     */
    public function active(): bool
    {
        return self::available() && $this->plugin->settings()->woocommerce();
    }

    /**
     * Fills the location WooCommerce asks for, unless it already has a
     * country.
     *
     * @param mixed $data Country, state, city and postcode.
     * @param mixed $ip
     *
     * @return array<string, string>
     */
    public function geolocation(mixed $data, mixed $ip): array
    {
        $location = ['country' => '', 'state' => '', 'city' => '', 'postcode' => ''];

        foreach (Value::list($data) as $key => $value) {
            $location[(string) $key] = Value::string($value);
        }

        $data = $location;

        if (! $this->plugin->settings()->woocommerce() || $data['country'] !== '' || ! is_string($ip) || $ip === '') {
            return $data;
        }

        $record = $this->plugin->lookup($ip);
        $country = $record?->country();

        if ($record === null || $country === null) {
            return $data;
        }

        return array_merge(
            $data,
            [
                'country' => $country,
                'state' => self::state($country, array_column($record->subdivisions(), 'code')),
                'city' => (string) $record->city(),
                'postcode' => (string) $record->postalCode(),
            ],
        );
    }

    /**
     * The code of the first subdivision WooCommerce lists among the states
     * of the country, empty otherwise: the codes of the databases are those
     * of ISO 3166-2, which WooCommerce follows for most countries but not
     * all, and its states are the regions of some countries, the provinces
     * of others.
     *
     * @param string              $country
     * @param array<string|null> $codes   Codes of the subdivisions, the largest first.
     *
     * @return string
     */
    private static function state(string $country, array $codes): string
    {
        if (! function_exists('WC') || ! isset(WC()->countries)) {
            return '';
        }

        $states = WC()->countries->get_states($country);

        foreach ($codes as $code) {
            if ($code !== null && is_array($states) && isset($states[$code])) {
                return $code;
            }
        }

        return '';
    }
}
