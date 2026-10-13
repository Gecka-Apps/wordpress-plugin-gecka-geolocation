<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Gecka <contact@gecka.nc>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Gecka\WP\Plugin\Geolocation;

/**
 * Logos of the networks, by autonomous system number, when the setting asks
 * for them.
 *
 * They come from asn-data (tiagozip.github.io/asn-data), a mirror of
 * PeeringDB with the favicon of the site of each network, covering the
 * networks PeeringDB knows. A logo is downloaded by the server once, into a public folder of the uploads
 * of the main site, and served from there: the browser never asks the
 * source. Asking for a logo not yet in place queues its number for a WP-Cron
 * event, which downloads the queue by batches; a number the source does not
 * know is not asked again for a month, the time a file next to the logos
 * holds.
 *
 * A downloaded file is kept only when it is a PNG image of reasonable size,
 * since the folder is served from the site itself.
 *
 * @author Laurent Dinclaux - Gecka <laurent@gecka.nc>
 */
class Logos
{
    /**
     * Address of the logos at the source, the number and .png following.
     */
    public const SOURCE = 'https://tiagozip.github.io/asn-data/logos/';

    /**
     * Folder of the logos under the uploads of the main site.
     */
    public const FOLDER = 'gecka-geolocation-logos';

    /**
     * Network option holding the numbers waiting for their logo.
     */
    public const QUEUE_OPTION = 'gecka_geolocation_logos_queue';

    /**
     * Event downloading the logos queued.
     */
    public const EVENT = 'gecka_geolocation_logos';

    /**
     * Seconds before a number the source does not know is asked again.
     */
    public const MISSING_DELAY = 30 * DAY_IN_SECONDS;

    /**
     * Seconds before a number whose download failed is asked again.
     */
    public const FAILED_DELAY = DAY_IN_SECONDS;

    /**
     * Site transient set while the folder cannot be written, which pauses
     * the queue.
     */
    public const PAUSED = 'gecka_geolocation_logos_paused';

    /**
     * Numbers the queue holds at most.
     */
    private const QUEUE_MAX = 1000;

    /**
     * Logos an event downloads at most.
     */
    private const BATCH = 25;

    /**
     * Bytes a logo weighs at most.
     */
    private const MAX_BYTES = 262144;

    /**
     * Pixels a side of a logo measures at most.
     */
    private const MAX_SIDE = 512;

    /**
     * @var Settings
     */
    private Settings $settings;

    /**
     * @param Settings $settings
     */
    public function __construct(Settings $settings)
    {
        $this->settings = $settings;
    }

    /**
     * Hooks the event up, and the removal of the logos once the setting is
     * turned off.
     *
     * @return void
     */
    public function setup(): void
    {
        add_action(self::EVENT, [$this, 'download']);
        add_action('update_site_option_' . Settings::OPTION, [$this, 'settingsChanged'], 10, 3);
    }

    /**
     * Address of the logo of a network, relative to the root of the site
     * unless asked absolute. A logo not yet in place is queued for download.
     *
     * @param int  $asn
     * @param bool $absolute With the scheme and the host of the site.
     *
     * @return string|null Null while the setting is off, the logo is not in place, or the source has none.
     */
    public function url(int $asn, bool $absolute = false): ?string
    {
        if (! $this->settings->networkLogos() || ! self::valid($asn)) {
            return null;
        }

        if ($this->path($asn) === null) {
            $this->enqueue($asn);

            return null;
        }

        $url = self::location()['url'] . '/' . $asn . '.png';

        return $absolute ? $url : wp_make_link_relative($url);
    }

    /**
     * Path of the logo of a network on the server
     *
     * @param int $asn
     *
     * @return string|null Null when it is not in place.
     */
    public function path(int $asn): ?string
    {
        if (! self::valid($asn)) {
            return null;
        }

        $path = self::location()['path'] . '/' . $asn . '.png';

        return is_file($path) ? $path : null;
    }

    /**
     * Number of logos in place
     *
     * @return int
     */
    public function count(): int
    {
        return count((array) glob(self::location()['path'] . '/*.png'));
    }

    /**
     * Downloads a batch of the logos queued, and schedules the next batch
     * while some remain.
     *
     * @return void
     */
    public function download(): void
    {
        if (! $this->settings->networkLogos()) {
            delete_site_option(self::QUEUE_OPTION);

            return;
        }

        $queue = self::queue();
        $batch = array_slice($queue, 0, self::BATCH);

        // Saved before the downloads, so that a request queuing meanwhile
        // keeps what it adds.
        update_site_option(self::QUEUE_OPTION, array_values(array_diff(self::queue(), $batch)));

        if ($batch !== [] && ! self::prepare()) {
            set_site_transient(self::PAUSED, 1, self::FAILED_DELAY);
            update_site_option(self::QUEUE_OPTION, []);

            return;
        }

        if ($batch !== []) {
            Storage::sweep(self::location()['path'], HOUR_IN_SECONDS);

            foreach ($batch as $asn) {
                $this->fetch($asn);
            }
        }

        if (self::queue() !== []) {
            self::schedule(MINUTE_IN_SECONDS);
        }
    }

    /**
     * Removes the logos once the setting is turned off.
     *
     * @param string $option
     * @param mixed  $value
     * @param mixed  $old
     *
     * @return void
     */
    public function settingsChanged(string $option, mixed $value, mixed $old): void
    {
        if (! empty(Value::list($old)['network_logos']) && empty(Value::list($value)['network_logos'])) {
            self::purge();
        }
    }

    /**
     * Removes the logos, the queue and the event.
     *
     * @return void
     */
    public static function purge(): void
    {
        Storage::deleteTree(self::location()['path']);
        delete_site_option(self::QUEUE_OPTION);
        delete_site_transient(self::PAUSED);
        Setup::onMainSite(static fn(): int|false => wp_clear_scheduled_hook(self::EVENT));
    }

    /**
     * Queues a number, unless it is already, or the source did not know it
     * lately, and schedules the event.
     *
     * @param int $asn
     *
     * @return void
     */
    private function enqueue(int $asn): void
    {
        $marker = self::location()['path'] . '/' . $asn . '.missing';

        if ((is_file($marker) && filemtime($marker) > time()) || get_site_transient(self::PAUSED)) {
            return;
        }

        $queue = self::queue();

        if (! in_array($asn, $queue, true)) {
            if (count($queue) >= self::QUEUE_MAX) {
                return;
            }

            $queue[] = $asn;
            update_site_option(self::QUEUE_OPTION, $queue);
        }

        self::schedule(0);
    }

    /**
     * Downloads the logo of a network, or marks it missing for a while.
     *
     * @param int $asn
     *
     * @return void
     */
    private function fetch(int $asn): void
    {
        $directory = self::location()['path'];

        if (is_file($directory . '/' . $asn . '.png')) {
            return;
        }

        // No redirection followed: a hop could leave HTTPS, and the source
        // serves the files directly.
        $response = wp_safe_remote_get(
            self::SOURCE . $asn . '.png',
            ['timeout' => 10, 'redirection' => 0, 'limit_response_size' => self::MAX_BYTES + 1, 'user-agent' => 'Gecka-Geolocation/' . Plugin::VERSION],
        );
        $status = is_wp_error($response) ? 0 : Value::int(wp_remote_retrieve_response_code($response));
        $body = is_wp_error($response) ? '' : wp_remote_retrieve_body($response);

        if ($status === 200 && self::image($body)) {
            $part = $directory . '/' . $asn . '.' . strtolower(wp_generate_password(8, false)) . '.part';

            // phpcs:disable WordPress.WP.AlternativeFunctions -- A small file of the folder of the logos, moved in place in one step.
            if (file_put_contents($part, $body) === strlen($body) && rename($part, $directory . '/' . $asn . '.png')) {
                return;
            }
            // phpcs:enable

            if (is_file($part)) {
                wp_delete_file($part);
            }

            $status = 0;
        }

        $missing = $status === 404 || $status === 200 ? self::MISSING_DELAY : self::FAILED_DELAY;

        touch($directory . '/' . $asn . '.missing', time() + $missing); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_touch -- The time of the file is when to ask again.
    }

    /**
     * Whether a body is a PNG image of reasonable size
     *
     * @param string $body
     *
     * @return bool
     */
    private static function image(string $body): bool
    {
        if (strlen($body) > self::MAX_BYTES || ! str_starts_with($body, "\x89PNG\r\n\x1a\n")) {
            return false;
        }

        $size = @getimagesizefromstring($body); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- A malformed image is refused, not reported.

        return is_array($size)
            && $size[2] === IMAGETYPE_PNG
            && $size[0] >= 1 && $size[0] <= self::MAX_SIDE
            && $size[1] >= 1 && $size[1] <= self::MAX_SIDE;
    }

    /**
     * Creates the folder of the logos when missing.
     *
     * @return bool Whether it can be written.
     */
    private static function prepare(): bool
    {
        $directory = self::location()['path'];

        if (! wp_mkdir_p($directory) || ! wp_is_writable($directory)) {
            return false;
        }

        Storage::index($directory);

        return true;
    }

    /**
     * The numbers waiting for their logo
     *
     * @return int[]
     */
    private static function queue(): array
    {
        $queue = array_map(static fn(mixed $asn): int => Value::int($asn), Value::list(get_site_option(self::QUEUE_OPTION, [])));

        return array_values(array_filter($queue, [self::class, 'valid']));
    }

    /**
     * Schedules the event on the main site, unless it is.
     *
     * @param int $delay Seconds from now.
     *
     * @return void
     */
    private static function schedule(int $delay): void
    {
        Setup::scheduleOnce(self::EVENT, $delay);
    }

    /**
     * Folder of the logos and its address, under the uploads of the main
     * site, which every site of a network shares
     *
     * @return array{path: string, url: string}
     */
    private static function location(): array
    {
        /** @var array{basedir: string, baseurl: string} $uploads */
        $uploads = Setup::onMainSite(static fn(): array => wp_upload_dir(null, false));

        return [
            'path' => untrailingslashit($uploads['basedir']) . '/' . self::FOLDER,
            'url' => untrailingslashit($uploads['baseurl']) . '/' . self::FOLDER,
        ];
    }

    /**
     * Whether a number is an autonomous system number
     *
     * @param int $asn
     *
     * @return bool
     */
    private static function valid(int $asn): bool
    {
        return $asn > 0 && $asn <= 4294967295;
    }
}
