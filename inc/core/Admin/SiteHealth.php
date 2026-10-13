<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Gecka <contact@gecka.nc>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Gecka\WP\Plugin\Geolocation\Admin;

use Gecka\WP\Plugin\Geolocation\Base;
use Gecka\WP\Plugin\Geolocation\Integrations\WooCommerce;
use Gecka\WP\Plugin\Geolocation\Mmdb\Provider as MmdbProvider;
use Gecka\WP\Plugin\Geolocation\Plugin;
use Gecka\WP\Plugin\Geolocation\Setup;
use Gecka\WP\Plugin\Geolocation\Storage;
use Gecka\WP\Plugin\Geolocation\Value;

/**
 * Tools > Site Health: a test of the databases, with a link to fix what is
 * wrong, and a section of the Info tab describing them, ready to be copied
 * for support. Both show to those who manage the plugin only: on a
 * multisite, the administrator of a site can do nothing about the databases
 * of the network.
 *
 * @author Laurent Dinclaux - Gecka <laurent@gecka.nc>
 */
class SiteHealth extends Base
{
    /**
     * Seconds without a check before the daily task counts as not running.
     */
    private const LATE = 2 * DAY_IN_SECONDS;

    /**
     * @return void
     */
    public function setup(): void
    {
        add_filter('site_status_tests', [$this, 'tests']);
        add_filter('debug_information', [$this, 'information']);
    }

    /**
     * Adds the test of the plugin.
     *
     * @param mixed $tests
     *
     * @return mixed
     */
    public function tests(mixed $tests): mixed
    {
        // Without a user, the weekly event of WordPress or WP-CLI runs the
        // tests; a user needs the capability of the plugin, as a site
        // administrator of a network does not manage the databases.
        if (is_array($tests) && (! is_user_logged_in() || current_user_can(Plugin::capability()))) {
            $direct = Value::list($tests['direct'] ?? []);
            $direct['gecka_geolocation_databases'] = ['label' => __('The databases of Gecka Geolocation', 'gecka-geolocation'), 'test' => [$this, 'testDatabases']];
            $direct['gecka_geolocation_directory'] = ['label' => __('The directory of the databases of Gecka Geolocation', 'gecka-geolocation'), 'test' => [$this, 'testDirectory']];
            $tests['direct'] = $direct;
        }

        return $tests;
    }

    /**
     * Whether the web server keeps the directory of the databases from
     * visitors: the .htaccess the plugin writes holds for Apache, and a
     * server ignoring it, as nginx, serves the files to whoever knows the
     * address.
     *
     * @return array<string, mixed>
     */
    public function testDirectory(): array
    {
        $url = Storage::url();

        if ($url === null || ! is_dir(Storage::directory())) {
            return $this->result('good', __('The directory of the databases of Gecka Geolocation is out of reach', 'gecka-geolocation'), esc_html__('The databases are kept in a directory the site chose, or none is downloaded yet.', 'gecka-geolocation'));
        }

        $verify = (bool) apply_filters('https_local_ssl_verify', false, $url); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- A filter of WordPress, for the requests a site sends itself.
        $response = wp_remote_head($url . '/index.php', ['timeout' => 5, 'redirection' => 0, 'sslverify' => $verify]);
        $status = is_wp_error($response) ? 0 : Value::int(wp_remote_retrieve_response_code($response));

        if ($status === 200) {
            return $this->result(
                'critical',
                __('The directory of the databases of Gecka Geolocation is reachable from the web', 'gecka-geolocation'),
                esc_html__('The web server ignores the .htaccess that denies access to it, so anyone knowing its address can download the databases, which their licences forbid. Deny access to it in the configuration of the server, or set GECKA_GEOLOCATION_DIRECTORY in wp-config.php to a directory out of the web root.', 'gecka-geolocation'),
            );
        }

        return $this->result('good', __('The directory of the databases of Gecka Geolocation is out of reach', 'gecka-geolocation'), esc_html__('The web server answers nothing for its files.', 'gecka-geolocation'));
    }

    /**
     * Whether the databases of the providers configured are in place and up
     * to date.
     *
     * @return array<string, mixed>
     */
    public function testDatabases(): array
    {
        $configured = array_filter($this->plugin->bundled(), static fn(MmdbProvider $provider): bool => $provider->settings()->configured());

        foreach ($this->plugin->bundled() as $provider) {
            $stale = $provider->updater()->stale();

            if ($stale) {
                return $this->result('critical', __('Gecka Geolocation uses databases outdated past the limit of their licence', 'gecka-geolocation'), Notices::staleMessage($provider, $stale), self::errors($provider));
            }
        }

        if ($configured === []) {
            return $this->result('recommended', __('Gecka Geolocation downloads no database', 'gecka-geolocation'), esc_html__('No provider has its databases chosen and its credentials set: the plugins asking Gecka Geolocation where an address is get no answer.', 'gecka-geolocation'));
        }

        $missing = [];
        $errors = [];
        $late = [];

        foreach ($configured as $provider) {
            $updater = $provider->updater();
            $missing = array_merge($missing, array_map([$provider, 'editionLabel'], $updater->missing()));
            $errors += self::errors($provider);

            if (time() - $updater->state()['checked'] > self::LATE) {
                $late[] = $provider->label();
            }
        }

        if ($missing) {
            return $this->result(
                'recommended',
                __('Gecka Geolocation has not downloaded all its databases', 'gecka-geolocation'),
                esc_html(
                    sprintf(
                        /* translators: %s: names of databases */
                        __('Missing or unreadable: %s. The download is tried again every hour while one is.', 'gecka-geolocation'),
                        implode(', ', $missing),
                    ),
                ),
                $errors,
            );
        }

        if ($errors) {
            return $this->result('recommended', __('Gecka Geolocation could not update its databases', 'gecka-geolocation'), esc_html__('The databases in place keep answering. The daily task tries again.', 'gecka-geolocation'), $errors);
        }

        if ($late) {
            return $this->result(
                'recommended',
                __('The daily task of Gecka Geolocation does not run', 'gecka-geolocation'),
                esc_html(
                    sprintf(
                        /* translators: %s: names of providers */
                        __('The databases of %s were not compared with the latest releases for more than two days. WP-Cron runs the daily task on a visit of the site, or from the cron of the server when DISABLE_WP_CRON is set.', 'gecka-geolocation'),
                        implode(', ', $late),
                    ),
                ),
            );
        }

        return $this->result('good', __('The databases of Gecka Geolocation are up to date', 'gecka-geolocation'), esc_html__('Each database chosen is in place, and the daily task compares it with the latest release of its provider.', 'gecka-geolocation'));
    }

    /**
     * Adds the section of the plugin to the Info tab.
     *
     * @param mixed $info
     *
     * @return mixed
     */
    public function information(mixed $info): mixed
    {
        if (! is_array($info) || ! current_user_can(Plugin::capability())) {
            return $info;
        }

        $format = 'Y-m-d H:i:s';
        $fields = [
            'provider' => [
                'label' => __('Default provider', 'gecka-geolocation'),
                'value' => $this->plugin->defaultProvider() ?: __('None', 'gecka-geolocation'),
            ],
            'directory' => [
                'label' => __('Directory', 'gecka-geolocation'),
                'value' => Storage::directory(),
                'private' => true,
            ],
            'woocommerce' => [
                'label' => __('WooCommerce geolocation', 'gecka-geolocation'),
                'value' => WooCommerce::available() && $this->plugin->settings()->woocommerce() ? __('Yes', 'gecka-geolocation') : __('No', 'gecka-geolocation'),
                'debug' => WooCommerce::available() && $this->plugin->settings()->woocommerce(),
            ],
            'network_logos' => [
                'label' => __('Logos of the networks', 'gecka-geolocation'),
                /* translators: %d: number of logos */
                'value' => $this->plugin->settings()->networkLogos() ? sprintf(_n('Yes, %d logo downloaded', 'Yes, %d logos downloaded', $this->plugin->logos()->count(), 'gecka-geolocation'), $this->plugin->logos()->count()) : __('No', 'gecka-geolocation'),
                'debug' => $this->plugin->settings()->networkLogos() ? $this->plugin->logos()->count() : false,
            ],
        ];

        foreach ($this->plugin->bundled() as $id => $provider) {
            $settings = $provider->settings();
            $databases = $provider->databases();
            $state = $provider->updater()->state();
            $label = $provider->label();

            if ($provider->fields() !== []) {
                $fields[$id . '_account'] = [
                    /* translators: %s: name of a provider */
                    'label' => sprintf(__('%s account', 'gecka-geolocation'), $label),
                    'value' => ! $settings->hasCredentials() ? __('None', 'gecka-geolocation') : ($settings->fromConstants() ? __('From wp-config.php', 'gecka-geolocation') : __('From the settings', 'gecka-geolocation')),
                    'debug' => ! $settings->hasCredentials() ? 'none' : ($settings->fromConstants() ? 'constants' : 'settings'),
                ];
            }

            $fields[$id . '_wanted'] = [
                /* translators: %s: name of a provider */
                'label' => sprintf(__('%s databases chosen', 'gecka-geolocation'), $label),
                'value' => implode(', ', $settings->wanted()) ?: __('None', 'gecka-geolocation'),
            ];

            if (! $settings->configured() && $databases->installed() === []) {
                continue;
            }

            $event = $provider->updater()->event();
            $next = Value::int(Setup::onMainSite(static fn(): int|false => wp_next_scheduled($event)));

            $fields[$id . '_checked'] = [
                /* translators: %s: name of a provider */
                'label' => sprintf(__('%s last check', 'gecka-geolocation'), $label),
                'value' => $state['checked'] ? gmdate($format, $state['checked']) . ' UTC' : __('Never', 'gecka-geolocation'),
            ];
            $fields[$id . '_next'] = [
                /* translators: %s: name of a provider */
                'label' => sprintf(__('%s next check', 'gecka-geolocation'), $label),
                'value' => $next ? gmdate($format, $next) . ' UTC' : __('Not scheduled', 'gecka-geolocation'),
            ];

            foreach ($databases->installed() as $edition) {
                $metadata = $databases->metadata($edition);

                $fields[$id . '_edition_' . $edition] = [
                    'label' => $edition,
                    'value' => $metadata
                        ? sprintf('%s, %s, %s', gmdate('Y-m-d', $metadata['built']), size_format((int) filesize($databases->path($edition)), 1), 'IPv' . $metadata['ip_version'])
                        : (string) $databases->error($edition),
                    'private' => $metadata === null,
                ];
            }

            foreach ($provider->updater()->errors() as $edition => $error) {
                $fields[$id . '_error_' . ($edition ?: 'check')] = [
                    /* translators: %s: name of a database, or of a provider */
                    'label' => sprintf(__('Error: %s', 'gecka-geolocation'), $edition ?: $label),
                    'value' => $error,
                    'private' => true,
                ];
            }
        }

        $info['gecka-geolocation'] = [
            'label' => __('Gecka Geolocation', 'gecka-geolocation'),
            'fields' => $fields,
        ];

        return $info;
    }

    /**
     * The errors of a provider, worded with the names of its databases
     *
     * @param MmdbProvider $provider
     *
     * @return array<string, string> By provider and edition.
     */
    private static function errors(MmdbProvider $provider): array
    {
        $errors = [];

        foreach ($provider->updater()->errors() as $edition => $error) {
            /* translators: 1: name of a database or of a provider, 2: error */
            $errors[$provider->id() . '/' . $edition] = sprintf(__('%1$s: %2$s', 'gecka-geolocation'), $edition !== '' ? $provider->editionLabel($edition) : $provider->label(), $error);
        }

        return $errors;
    }

    /**
     * A result of the test, linking to the tab of the databases
     *
     * @param string                $status      good, recommended or critical.
     * @param string                $label
     * @param string                $description Escaped.
     * @param array<string, string> $errors      Errors to list.
     *
     * @return array<string, mixed>
     */
    private function result(string $status, string $label, string $description, array $errors = []): array
    {
        $list = implode('', array_map(static fn(string $error): string => '<li>' . esc_html($error) . '</li>', $errors));

        return [
            'label' => $label,
            'status' => $status,
            'badge' => ['label' => __('Geolocation', 'gecka-geolocation'), 'color' => $status === 'good' ? 'blue' : 'red'],
            'description' => '<p>' . $description . '</p>' . ($list !== '' ? '<ul>' . $list . '</ul>' : ''),
            'actions' => $status === 'good' ? '' : sprintf('<p><a href="%s">%s</a></p>', esc_url(SettingsPage::url('databases')), esc_html__('Open the Geolocation settings', 'gecka-geolocation')),
            'test' => 'gecka_geolocation_databases',
        ];
    }
}
