<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Gecka <contact@gecka.nc>
// SPDX-License-Identifier: GPL-3.0-or-later

/**
 * Fills a fresh site for the screenshots of the plugin, required by WP-CLI
 * (`wp eval 'require "...";'`): the credentials of the providers from
 * WP_SHOTS_SECRETS, the databases downloaded, the logos of the networks on
 * and the logo of the network of 8.8.8.8 in place.
 *
 * The providers count the downloads. The state of each updater is saved
 * next to the databases in wp-shots-state.json and put back before the
 * update, so that a run only checks the releases, which costs nothing, and
 * downloads a newer one alone.
 */

use Gecka\WP\Plugin\Geolocation\Storage;
use Gecka\WP\Plugin\Geolocation\Value;

(static function (): void {
    $secrets = Value::list(json_decode(Value::string(getenv('WP_SHOTS_SECRETS')), true));
    $plugin = gecka_geolocation_plugin();
    $cache = Storage::directory() . '/wp-shots-state.json';
    $states = is_file($cache) ? Value::list(json_decode(Value::string(file_get_contents($cache)), true)) : []; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- A local file of the seed.

    $editions = [
        'maxmind' => ['GeoLite2-Country', 'GeoLite2-City', 'GeoLite2-ASN'],
        'dbip' => ['dbip-country-lite', 'dbip-asn-lite'],
        'ipinfo' => ['ipinfo_lite'],
    ];

    foreach ($editions as $id => $wanted) {
        $provider = $plugin->mmdb($id);

        if ($provider === null) {
            continue;
        }

        $provider->settings()->save(array_merge(Value::list($secrets[$id] ?? null), ['editions' => $wanted]));

        if (! $provider->settings()->hasCredentials()) {
            WP_CLI::warning(sprintf('%s: no credentials in WP_SHOTS_SECRETS, nothing downloaded', $provider->label()));

            continue;
        }

        $updater = $provider->updater();

        if (is_array($states[$id] ?? null)) {
            update_site_option($updater->option(), $states[$id]);
            $updater->flush();
        }

        WP_CLI::log(sprintf('%s: checking %s', $provider->label(), implode(', ', $wanted)));
        $updater->update(900);

        foreach ($updater->errors() as $edition => $error) {
            WP_CLI::warning(sprintf('%s: %s', $edition === '' ? $provider->label() : $edition, $error));
        }

        $states[$id] = $updater->state();
    }

    file_put_contents($cache, wp_json_encode($states)); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- A local file of the seed.

    $plugin->settings()->save(['network_logos' => true]);
    $plugin->flush();

    $record = gecka_geolocation('8.8.8.8');
    $asn = $record?->asn();

    if ($asn !== null) {
        gecka_geolocation_network_logo_url($asn);
        $plugin->logos()->download();
        WP_CLI::log(sprintf('AS%d: logo %s', $asn, $plugin->logos()->path($asn) !== null ? 'in place' : 'missing'));
    }

    WP_CLI::success('seeded');
})();
