<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Gecka <contact@gecka.nc>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Gecka\WP\Plugin\Geolocation\Admin;

use Gecka\WP\Plugin\Geolocation\Base;
use Gecka\WP\Plugin\Geolocation\Mmdb\Provider as MmdbProvider;
use Gecka\WP\Plugin\Geolocation\Plugin;

/**
 * An error on the dashboard and the plugins screen of those who manage the
 * plugin, in the network admin on a multisite, while a database installed is
 * past the delay the licence of its provider allows once a new release is
 * out. The page of the plugin tells it in the block of the provider. The
 * lookups go on meanwhile.
 *
 * @author Laurent Dinclaux - Gecka <laurent@gecka.nc>
 */
class Notices extends Base
{
    /**
     * @return void
     */
    public function setup(): void
    {
        add_action(is_multisite() ? 'network_admin_notices' : 'admin_notices', [$this, 'stale']);
    }

    /**
     * Prints the error on the dashboard and the plugins screen.
     *
     * @return void
     */
    public function stale(): void
    {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;

        if (! $screen || ! in_array($screen->base, ['dashboard', 'plugins'], true) || ! current_user_can(Plugin::capability())) {
            return;
        }

        foreach ($this->plugin->bundled() as $provider) {
            $stale = $provider->updater()->stale();

            if ($stale) {
                wp_admin_notice(
                    self::staleMessage($provider, $stale) . ' ' . sprintf('<a href="%s">%s</a>', esc_url(SettingsPage::url('databases')), esc_html__('See the databases', 'gecka-geolocation')),
                    ['type' => 'error'],
                );
            }
        }
    }

    /**
     * What the error says
     *
     * @param MmdbProvider $provider
     * @param string[]     $editions
     *
     * @return string Escaped.
     */
    public static function staleMessage(MmdbProvider $provider, array $editions): string
    {
        return esc_html(
            sprintf(
                /* translators: 1: names of databases, 2: number of days, 3: name of the provider */
                _n(
                    'Gecka Geolocation could not update %1$s for more than %2$d days after %3$s released a newer one. The licence of %3$s asks that an outdated release be deleted after %2$d days: lookups go on, but the database must be updated or removed.',
                    'Gecka Geolocation could not update %1$s for more than %2$d days after %3$s released newer ones. The licence of %3$s asks that an outdated release be deleted after %2$d days: lookups go on, but the databases must be updated or removed.',
                    count($editions),
                    'gecka-geolocation',
                ),
                implode(', ', array_map([$provider, 'editionLabel'], $editions)),
                $provider->licenseDays() ?? 0,
                $provider->label(),
            ),
        );
    }
}
