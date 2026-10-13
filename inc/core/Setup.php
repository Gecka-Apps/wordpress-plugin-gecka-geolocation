<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Gecka <contact@gecka.nc>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Gecka\WP\Plugin\Geolocation;

use Gecka\WP\Plugin\Geolocation\Mmdb\Provider as MmdbProvider;

/**
 * What the plugin leaves on an installation: network options, the
 * databases in their directory, the logos of the networks in theirs, a
 * daily event per provider on the main site and, for a while, transients
 * per administrator. Deleting the plugin removes all of it.
 *
 * @author Laurent Dinclaux - Gecka <laurent@gecka.nc>
 */
class Setup
{
    /**
     * Network option holding the version of the plugin whose setup ran.
     */
    public const VERSION_OPTION = 'gecka_geolocation_version';

    /**
     * Hooks the state changes of the plugin up.
     *
     * @param string $file Main file of the plugin
     *
     * @return void
     */
    public static function hooks(string $file): void
    {
        register_activation_hook($file, [self::class, 'activate']);
        register_deactivation_hook($file, [self::class, 'deactivate']);
    }

    /**
     * Schedules the daily events, and a download right away for a provider
     * already configured, as from wp-config.php.
     *
     * @return void
     */
    public static function activate(): void
    {
        self::schedule();
        update_site_option(self::VERSION_OPTION, Plugin::VERSION);

        foreach (gecka_geolocation_plugin()->bundled() as $provider) {
            if ($provider->settings()->configured()) {
                self::scheduleOnce($provider->updater()->nowEvent());
            }
        }
    }

    /**
     * Schedules a single event on the main site, in a delay, unless one
     * is already due by then; one due later is moved up.
     *
     * @param string $event
     * @param int    $delay Seconds from now.
     *
     * @return void
     */
    public static function scheduleOnce(string $event, int $delay = 0): void
    {
        self::onMainSite(
            static function () use ($event, $delay): void {
                $next = wp_next_scheduled($event);

                if ($next !== false && $next <= time() + $delay) {
                    return;
                }

                if ($next !== false) {
                    wp_unschedule_event($next, $event);
                }

                wp_schedule_single_event(time() + $delay, $event);
            },
        );
    }

    /**
     * Forgets what the caches hold of a network option, so that the next
     * read goes to the database: a long request sees what another saved
     * meanwhile.
     *
     * @param string $option
     *
     * @return void
     */
    public static function uncache(string $option): void
    {
        wp_cache_delete(get_current_network_id() . ':' . $option, 'site-options');
        wp_cache_delete($option, 'options');
        wp_cache_delete('alloptions', 'options');
    }

    /**
     * Stops the events.
     *
     * @return void
     */
    public static function deactivate(): void
    {
        self::onMainSite(
            static function (): void {
                foreach (self::events() as $event) {
                    wp_clear_scheduled_hook($event);
                }
            },
        );
    }

    /**
     * Events of the plugin, all scheduled on the main site
     *
     * @return string[]
     */
    public static function events(): array
    {
        return array_merge([Logos::EVENT], ...array_values(array_map(static fn(MmdbProvider $provider): array => $provider->updater()->events(), Plugin::bundle())));
    }

    /**
     * Schedules the daily events when they went missing, and records the
     * version. On a network, a sub-site checks nothing: the main site does,
     * sparing every request of the others a switch to it.
     *
     * @param string $version Version of the plugin.
     *
     * @return void
     */
    public static function upgrade(string $version): void
    {
        $upgraded = get_site_option(self::VERSION_OPTION) !== $version;

        if ($upgraded || ! is_multisite() || is_main_site()) {
            self::schedule();
        }

        if ($upgraded) {
            update_site_option(self::VERSION_OPTION, $version);
        }
    }

    /**
     * Removes the databases, the options and the events.
     *
     * @return void
     */
    public static function uninstall(): void
    {
        self::deactivate();

        foreach (Plugin::bundle() as $provider) {
            Storage::delete($provider->id());
            delete_site_option($provider->settings()->option());
            delete_site_option($provider->updater()->option());
        }

        Storage::delete();
        Logos::purge();

        foreach ([Settings::OPTION, Storage::KEY_OPTION, self::VERSION_OPTION] as $option) {
            delete_site_option($option);
        }

        self::deleteTransients();
    }

    /**
     * Deletes the site transients of the plugin, those queued per
     * administrator and the pause of the logos, whatever their key.
     *
     * @return void
     */
    private static function deleteTransients(): void
    {
        /** @var \wpdb $wpdb */
        global $wpdb;

        $like = $wpdb->esc_like('_site_transient_') . '%' . $wpdb->esc_like('gecka_geolocation_') . '%';

        $sql = is_multisite()
            ? $wpdb->prepare('DELETE FROM %i WHERE site_id = %d AND meta_key LIKE %s', $wpdb->sitemeta, get_current_network_id(), $like)
            : $wpdb->prepare('DELETE FROM %i WHERE option_name LIKE %s', $wpdb->options, $like);

        if (is_string($sql)) {
            $wpdb->query($sql); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- The keys carry a user id, no API lists them; the statement is prepared above.
        }

        wp_cache_flush();
    }

    /**
     * Runs a callback on the main site of the network, where the events are
     * scheduled, or on the only site
     *
     * @param callable $callback
     *
     * @return mixed What the callback returns.
     */
    public static function onMainSite(callable $callback): mixed
    {
        if (! is_multisite() || is_main_site()) {
            return $callback();
        }

        switch_to_blog(get_main_site_id());

        try {
            return $callback();
        } finally {
            restore_current_blog();
        }
    }

    /**
     * Schedules the daily check of each provider on the main site, unless
     * it is.
     *
     * @return void
     */
    private static function schedule(): void
    {
        self::onMainSite(
            static function (): void {
                foreach (Plugin::bundle() as $provider) {
                    $event = $provider->updater()->event();

                    if (! wp_next_scheduled($event)) {
                        wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', $event);
                    }
                }
            },
        );
    }
}
