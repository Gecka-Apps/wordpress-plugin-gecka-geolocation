<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Gecka <contact@gecka.nc>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Gecka\WP\Plugin\Geolocation\Mmdb;

use Gecka\WP\Plugin\Geolocation\Setup;
use Gecka\WP\Plugin\Geolocation\Storage;
use Gecka\WP\Plugin\Geolocation\Value;

/**
 * Downloads the databases the settings of a provider name and keeps them up
 * to date.
 *
 * Updating goes in steps, so that no request carries more than one
 * download: check() asks the provider for the latest release of each
 * database wanted and queues those missing or older; next() downloads the
 * first database of the queue, checks it against the checksum the provider
 * publishes, if any, takes the .mmdb file out, checks it is the database it
 * should be, and moves it in place of the old one. The tab of the databases
 * runs the steps through the REST API, showing the progress; the daily
 * event runs them all.
 *
 * When the licence of the provider limits how long an old release may be
 * used once a new one is out, stale() names the databases past that, which
 * Site Health and an admin notice report.
 *
 * Every site of a network shares the state and the files, and the events
 * are scheduled on the main site.
 *
 * @author Laurent Dinclaux - Gecka <laurent@gecka.nc>
 */
class Updater
{
    /**
     * Seconds before credentials refused are checked again: a key is not
     * always active right after its creation.
     */
    public const RETRY_DELAY = 1800;

    /**
     * Seconds between two checks of the releases.
     */
    public const INTERVAL = 20 * 3600;

    /**
     * Seconds between two checks while a database wanted is missing.
     */
    public const RETRY = 3600;

    /**
     * Days after which a release no check could compare counts as outdated,
     * when the licence limits the use of old releases.
     */
    public const UNCHECKED_DAYS = 37;

    /**
     * Seconds after which a .part file belongs to a download that died.
     */
    private const PART_AGE = 3600;

    /**
     * The state read from the option, until it changes.
     *
     * @var array{editions: array<string, array{installed: int, remote: int, release: string, installed_release: string, downloaded: int, bytes: int, error: string}>, access: array<string, bool>, access_checked: int, checked: int, error: string, pending: string[]}|null
     */
    private ?array $state = null;

    /**
     * Handle of the lock file while this request holds it.
     *
     * @var resource|null
     */
    private $lock = null;

    /**
     * @var Provider
     */
    private Provider $provider;

    /**
     * @param Provider $provider
     */
    public function __construct(Provider $provider)
    {
        $this->provider = $provider;
    }

    /**
     * Network option holding the state of the databases
     *
     * @return string As gecka_geolocation_maxmind_state.
     */
    public function option(): string
    {
        return 'gecka_geolocation_' . $this->provider->id() . '_state';
    }

    /**
     * Daily event checking for new releases
     *
     * @return string As gecka_geolocation_maxmind_update.
     */
    public function event(): string
    {
        return 'gecka_geolocation_' . $this->provider->id() . '_update';
    }

    /**
     * Event updating right away, after the settings change
     *
     * @return string
     */
    public function nowEvent(): string
    {
        return $this->event() . '_now';
    }

    /**
     * Event checking the credentials again, once, after the provider
     * refused them
     *
     * @return string
     */
    public function retryEvent(): string
    {
        return 'gecka_geolocation_' . $this->provider->id() . '_retry';
    }

    /**
     * The events of the provider
     *
     * @return string[]
     */
    public function events(): array
    {
        return [$this->event(), $this->nowEvent(), $this->retryEvent()];
    }

    /**
     * Site transient telling the tab of the databases of a user to start
     * the downloads the settings saved just queued
     *
     * @param int $user
     *
     * @return string
     */
    public function queuedKey(int $user): string
    {
        return 'gecka_geolocation_' . $this->provider->id() . '_queued_' . $user;
    }

    /**
     * Hooks the events up.
     *
     * @return void
     */
    public function setup(): void
    {
        add_action($this->event(), [$this, 'refresh']);
        add_action($this->nowEvent(), [$this, 'updateNow']);
        add_action($this->retryEvent(), [$this, 'retry']);

        foreach (['add_site_option_', 'update_site_option_', 'delete_site_option_'] as $hook) {
            add_action($hook . $this->option(), [$this, 'flush']);
        }
    }

    /**
     * Updates the databases when a check is due.
     *
     * @return void
     */
    public function refresh(): void
    {
        if ($this->due()) {
            $this->update();
        }
    }

    /**
     * Updates the databases right away, for the event the settings saved
     * schedule, whatever the last check.
     *
     * @return void
     */
    public function updateNow(): void
    {
        if ($this->provider->settings()->configured()) {
            $this->update();
        }
    }

    /**
     * Whether a check is due: the provider is configured, and the interval
     * is over, databases wait in the queue, or a database wanted is missing
     * and the last check is an hour old
     *
     * @return bool
     */
    public function due(): bool
    {
        if (! $this->provider->settings()->configured()) {
            return false;
        }

        $state = $this->state();
        $since = time() - $state['checked'];

        return $since >= self::INTERVAL
            || $state['pending'] !== []
            || ($this->missing() !== [] && $since >= self::RETRY);
    }

    /**
     * The databases wanted whose file is missing, or in place but unreadable,
     * as one a full disk truncated
     *
     * @return string[]
     */
    public function missing(): array
    {
        $databases = $this->provider->databases();

        return array_values(array_filter(
            $this->provider->settings()->wanted(),
            static fn(string $edition): bool => ! is_file($databases->path($edition)) || $databases->metadata($edition) === null,
        ));
    }

    /**
     * Checks the releases and downloads the databases queued, a download
     * being started while the time budget lasts; what remains goes to the
     * event again right away, and a database still missing or in error to
     * the event in an hour.
     *
     * @param float $seconds Time budget.
     *
     * @return bool|null Whether everything went through and nothing waits, null when another request is updating.
     */
    public function update(float $seconds = 240): ?bool
    {
        if (! $this->lock()) {
            return null;
        }

        try {
            $deadline = microtime(true) + $seconds;
            $checked = $this->check() !== null;

            while ($checked && microtime(true) < $deadline) {
                if ($this->next() === null) {
                    break;
                }
            }
        } finally {
            $this->unlock();
        }

        $pending = $this->state()['pending'] !== [];

        if ($pending) {
            Setup::scheduleOnce($this->nowEvent());
        } elseif ($this->provider->settings()->configured() && ($this->errors() !== [] || $this->missing() !== [])) {
            Setup::scheduleOnce($this->nowEvent(), self::RETRY);
        }

        return $checked && ! $pending && $this->errors() === [];
    }

    /**
     * Asks the provider for the latest release of each database wanted,
     * queues those missing or older, and deletes those not wanted. A
     * check that fails keeps the time of the last one that went through.
     * The caller holds the lock.
     *
     * @return array<int, array{edition: string, label: string}>|null The databases queued, null when the check failed.
     */
    public function check(): ?array
    {
        $state = $this->state();
        $state['pending'] = [];
        $state['error'] = '';

        try {
            $this->requireCredentials();
            Storage::prepare($this->provider->id());
            $client = new Client($this->provider);
            $missing = $this->missing();

            foreach ($this->provider->settings()->wanted() as $edition) {
                $release = $this->provider->release($client, $edition);
                $entry = $state['editions'][$edition] ?? self::entry();

                if ($release['status'] === 200) {
                    $state['access'][$edition] = true;
                    $entry['error'] = '';

                    if ($release['modified'] > 0) {
                        $entry['remote'] = $release['modified'];
                    } elseif ($release['release'] !== '' && $release['release'] !== $entry['release']) {
                        $entry['remote'] = time();
                    }

                    $entry['release'] = $release['release'];

                    $outdated = match (true) {
                        $release['release'] !== '' => $release['release'] !== $entry['installed_release'],
                        $release['modified'] > 0 => $release['modified'] > $entry['installed'],
                        default => time() - $entry['downloaded'] >= WEEK_IN_SECONDS,
                    };

                    if (in_array($edition, $missing, true) || $outdated) {
                        $state['pending'][] = $edition;
                    }
                } elseif ($this->provider->fatal($release['status'])) {
                    throw new \RuntimeException(esc_html($this->provider->message($release['status'], $edition)), $release['status']);
                } else {
                    if ($release['status'] === 403) {
                        $state['access'][$edition] = false;
                    }

                    $entry['error'] = $this->provider->message($release['status'], $edition);
                }

                $state['editions'][$edition] = $entry;
            }
        } catch (\RuntimeException $e) {
            $state['error'] = $e->getMessage();
            $this->save($state);

            return null;
        }

        $state['checked'] = time();
        $this->save($state);
        $this->prune();

        return array_values(array_map(fn(string $edition): array => ['edition' => $edition, 'label' => $this->provider->editionLabel($edition)], $this->state()['pending']));
    }

    /**
     * Downloads the first database of the queue and takes it off, whether it
     * went through or not. An edition the settings do not want, another
     * request having changed them, is skipped. The caller holds the lock.
     *
     * @return array{edition: string, label: string, error: string|null, left: int}|null Null when the queue is empty.
     */
    public function next(): ?array
    {
        $this->provider->settings()->reload();
        $state = $this->fresh();
        $wanted = $this->provider->settings()->wanted();

        do {
            $edition = array_shift($state['pending']);
        } while ($edition !== null && ! in_array($edition, $wanted, true));

        if ($edition === null) {
            $this->save($state);

            return null;
        }

        $this->save($state);
        $error = null;

        try {
            $this->install($edition);
        } catch (\Throwable $e) {
            $error = $e->getMessage();
        }

        $state = $this->state();
        $entry = $state['editions'][$edition] ?? self::entry();
        $entry['error'] = $error ?? '';
        $state['editions'][$edition] = $entry;
        $this->save($state);

        return ['edition' => $edition, 'label' => $this->provider->editionLabel($edition), 'error' => $error, 'left' => count($state['pending'])];
    }

    /**
     * Deletes the databases not wanted, takes them off the queue, and
     * deletes the .part files of downloads that died.
     *
     * @return void
     */
    public function prune(): void
    {
        $state = $this->state();
        $wanted = $this->provider->settings()->wanted();
        $databases = $this->provider->databases();

        foreach (array_keys($this->provider->editions()) as $edition) {
            if (in_array($edition, $wanted, true)) {
                continue;
            }

            if (is_file($databases->path($edition))) {
                $databases->forget($edition);
                wp_delete_file($databases->path($edition));
            }

            unset($state['editions'][$edition]);
        }

        Storage::sweep(Storage::directory($this->provider->id()), self::PART_AGE);

        $state['pending'] = array_values(array_intersect($state['pending'], $wanted));
        $this->save($state);
    }

    /**
     * Asks the provider which databases the credentials may download, one
     * request for each.
     *
     * @param bool $retry Whether to check once more, RETRY_DELAY later, when the provider refuses the credentials.
     *
     * @return array<string, bool>|null Edition => whether the credentials give it, null when the check failed.
     */
    public function detect(bool $retry = false): ?array
    {
        $access = [];

        try {
            $this->requireCredentials();
            $client = new Client($this->provider);

            foreach (array_keys($this->provider->editions()) as $edition) {
                $status = $this->provider->release($client, $edition)['status'];

                if ($status !== 200 && $this->provider->fatal($status)) {
                    throw new \RuntimeException(esc_html($this->provider->message($status, $edition)), (int) $status);
                }

                $access[$edition] = $status === 200;
            }
        } catch (\RuntimeException $e) {
            $refused = in_array($e->getCode(), [401, 403], true);
            $state = $this->fresh();
            $state['error'] = $e->getMessage();
            $state['access'] = $refused ? [] : $state['access'];
            $this->save($state);

            if ($retry && $refused) {
                Setup::scheduleOnce($this->retryEvent(), self::RETRY_DELAY);
            }

            return null;
        }

        $state = $this->fresh();
        $state['access'] = $access;
        $state['access_checked'] = time();
        $state['error'] = '';
        $this->save($state);

        return $access;
    }

    /**
     * Checks the credentials again after the provider refused them, and
     * downloads what is missing once they are accepted. Runs once:
     * credentials still refused wait for the administrator.
     *
     * @return void
     */
    public function retry(): void
    {
        if ($this->detect() !== null) {
            $this->update();
        }
    }

    /**
     * Once the settings are saved: deletes the databases not wanted, and
     * when a database wanted is missing or the list changed, queues the
     * downloads for the event and for the tab of the databases, which
     * starts them when it opens.
     *
     * @param string[] $before The editions wanted before the save.
     *
     * @return bool Whether downloads were queued.
     */
    public function settingsChanged(array $before): bool
    {
        $this->prune();
        $settings = $this->provider->settings();

        if (! $settings->configured() || ($this->missing() === [] && $settings->wanted() === $before)) {
            return false;
        }

        Setup::scheduleOnce($this->nowEvent());

        if (get_current_user_id()) {
            set_site_transient($this->queuedKey(get_current_user_id()), 1, HOUR_IN_SECONDS);
        }

        return true;
    }

    /**
     * Whether the tab of the databases of the current user is to start the
     * downloads the settings saved queued; asking clears it
     *
     * @return bool
     */
    public function takeQueued(): bool
    {
        $key = $this->queuedKey(get_current_user_id());
        $queued = get_site_transient($key) !== false;

        delete_site_transient($key);

        return $queued;
    }

    /**
     * Seconds since the release of a database newer than the one installed;
     * 0 when the one installed is the latest the provider told of
     *
     * @param string $edition
     *
     * @return int
     */
    public function outdated(string $edition): int
    {
        $entry = $this->state()['editions'][$edition] ?? self::entry();
        $newer = $entry['release'] !== '' ? $entry['release'] !== $entry['installed_release'] : $entry['remote'] > $entry['installed'];

        return $newer && $entry['remote'] > 0 ? max(1, time() - $entry['remote']) : 0;
    }

    /**
     * The databases installed past the delay the licence of the provider
     * allows once a new release is out: a newer release has been out that
     * long, or no check went through for that long and the release
     * installed is older than UNCHECKED_DAYS. None when the licence sets no
     * limit.
     *
     * @return string[]
     */
    public function stale(): array
    {
        $days = $this->provider->licenseDays();

        if ($days === null) {
            return [];
        }

        $state = $this->state();
        $limit = $days * DAY_IN_SECONDS;

        return array_values(
            array_filter(
                $this->provider->databases()->installed(),
                function (string $edition) use ($state, $limit): bool {
                    $installed = $state['editions'][$edition]['installed'] ?? 0;

                    return $this->outdated($edition) > $limit
                        || (time() - $state['checked'] > $limit && $installed > 0 && time() - $installed > self::UNCHECKED_DAYS * DAY_IN_SECONDS);
                },
            ),
        );
    }

    /**
     * The errors of the last check and of the downloads since, by edition,
     * the error of the whole under an empty key
     *
     * @return array<string, string>
     */
    public function errors(): array
    {
        $state = $this->state();
        $errors = $state['error'] !== '' ? ['' => $state['error']] : [];

        foreach ($state['editions'] as $edition => $entry) {
            if ($entry['error'] !== '' && in_array($edition, $this->provider->settings()->wanted(), true)) {
                $errors[$edition] = $entry['error'];
            }
        }

        return $errors;
    }

    /**
     * What the plugin knows of the databases
     *
     * @return array{editions: array<string, array{installed: int, remote: int, release: string, installed_release: string, downloaded: int, bytes: int, error: string}>, access: array<string, bool>, access_checked: int, checked: int, error: string, pending: string[]}
     */
    public function state(): array
    {
        if ($this->state !== null) {
            return $this->state;
        }

        $saved = get_site_option($this->option(), []);
        $saved = is_array($saved) ? $saved : [];
        $editions = [];

        foreach (Value::list($saved['editions'] ?? null) as $edition => $entry) {
            if ($this->provider->known(Value::string($edition)) && is_array($entry)) {
                $editions[Value::string($edition)] = [
                    'installed' => Value::int($entry['installed'] ?? 0),
                    'remote' => Value::int($entry['remote'] ?? 0),
                    'release' => Value::string($entry['release'] ?? ''),
                    'installed_release' => Value::string($entry['installed_release'] ?? ''),
                    'downloaded' => Value::int($entry['downloaded'] ?? 0),
                    'bytes' => Value::int($entry['bytes'] ?? 0),
                    'error' => Value::string($entry['error'] ?? ''),
                ];
            }
        }

        $access = [];

        foreach (Value::list($saved['access'] ?? null) as $edition => $has) {
            if ($this->provider->known(Value::string($edition))) {
                $access[Value::string($edition)] = (bool) $has;
            }
        }

        return $this->state = [
            'editions' => $editions,
            'access' => $access,
            'access_checked' => Value::int($saved['access_checked'] ?? 0),
            'checked' => Value::int($saved['checked'] ?? 0),
            'error' => Value::string($saved['error'] ?? ''),
            'pending' => array_values(array_filter(Value::strings($saved['pending'] ?? []), [$this->provider, 'known'])),
        ];
    }

    /**
     * Takes the lock of the updates of the provider, so that two requests
     * never download nor replace a database at once. The lock is a file of
     * its folder, which the system releases when the request ends, even by
     * a crash.
     *
     * @return bool Whether the lock was taken; false while another request holds it.
     */
    public function lock(): bool
    {
        if ($this->lock !== null) {
            return true;
        }

        try {
            $directory = Storage::prepare($this->provider->id());
        } catch (\RuntimeException) {
            // check() reports the directory.
            return true;
        }

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- A lock file, held with flock().
        $handle = fopen($directory . '/.lock', 'c');

        // A lock file that cannot be opened, as one left by another system
        // user, stops the updates rather than letting two run at once.
        if ($handle === false) {
            return false;
        }

        // A file system without locks, as some network shares, locks nothing
        // rather than blocking the updates for good.
        if (! flock($handle, LOCK_EX | LOCK_NB, $wouldBlock) && $wouldBlock) {
            fclose($handle); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closes the lock file opened above.

            return false;
        }

        $this->lock = $handle;

        return true;
    }

    /**
     * Releases the lock of the updates.
     *
     * @return void
     */
    public function unlock(): void
    {
        if ($this->lock === null) {
            return;
        }

        flock($this->lock, LOCK_UN);
        fclose($this->lock); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- See lock().
        $this->lock = null;
    }

    /**
     * Forgets the state read, so that the next call reads the option again.
     *
     * @return void
     */
    public function flush(): void
    {
        $this->state = null;
    }

    /**
     * The state as the database holds it now, past the cache of the request,
     * for a long request to write over what another saved meanwhile
     *
     * @return array{editions: array<string, array{installed: int, remote: int, release: string, installed_release: string, downloaded: int, bytes: int, error: string}>, access: array<string, bool>, access_checked: int, checked: int, error: string, pending: string[]}
     */
    private function fresh(): array
    {
        Setup::uncache($this->option());
        $this->flush();

        return $this->state();
    }

    /**
     * Throws while credentials the provider asks for are missing.
     *
     * @return void
     *
     * @throws \RuntimeException
     */
    private function requireCredentials(): void
    {
        if (! $this->provider->settings()->hasCredentials()) {
            /* translators: %s: name of a provider */
            throw new \RuntimeException(esc_html(sprintf(__('Enter the credentials of your %s account.', 'gecka-geolocation'), $this->provider->label())));
        }
    }

    /**
     * Downloads a database, checks it, and moves it in place of its file.
     * The download and the database are written next to the final file
     * under names of their own, so that a request reading the directory
     * never sees half a database.
     *
     * @param string $edition
     *
     * @return void
     *
     * @throws \RuntimeException When anything fails; the database in place stays.
     */
    private function install(string $edition): void
    {
        $databases = $this->provider->databases();
        $directory = Storage::prepare($this->provider->id());
        $suffix = '.' . strtolower(wp_generate_password(8, false)) . '.part';
        $download = $directory . '/' . $edition . '.download' . $suffix;
        $database = $directory . '/' . $edition . '.mmdb' . $suffix;
        $release = $this->state()['editions'][$edition]['release'] ?? '';

        // The lock is held: a .part file left is one a request died on.
        Storage::sweep($directory, 0);

        if (function_exists('set_time_limit')) {
            set_time_limit(Client::TIMEOUT + 60); // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- A download outlasts the default max_execution_time.
        }

        try {
            $client = new Client($this->provider);
            $checksum = $this->provider->checksum($client, $edition, $release);
            $released = $client->download($this->provider->url($edition, $release), $download, $edition);

            if ($checksum !== null && ! hash_equals($checksum, (string) hash_file('sha256', $download))) {
                /* translators: %s: name of a provider */
                throw new \RuntimeException(esc_html(sprintf(__('The download does not match the checksum %s publishes.', 'gecka-geolocation'), $this->provider->label())));
            }

            Archive::extract($this->provider->format(), $download, $database);
            $metadata = $databases->verify($database, $edition);

            $databases->forget($edition);

            // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- Moved in place in one step.
            if (! rename($database, $databases->path($edition))) {
                /* translators: %s: file on the server */
                throw new \RuntimeException(esc_html(sprintf(__('Cannot write %s.', 'gecka-geolocation'), $databases->path($edition))));
            }
        } finally {
            foreach ([$download, $database] as $part) {
                if (is_file($part)) {
                    wp_delete_file($part);
                }
            }
        }

        $state = $this->state();
        $entry = $state['editions'][$edition] ?? self::entry();
        $entry['installed'] = $released ?: $metadata['built'];
        $entry['remote'] = max($entry['remote'], $entry['installed']);
        $entry['installed_release'] = $release;
        $entry['downloaded'] = time();
        $entry['bytes'] = (int) filesize($databases->path($edition));
        $state['editions'][$edition] = $entry;
        $state['access'][$edition] = true;
        $this->save($state);

        /**
         * Fires once a provider put new data in place.
         *
         * @param string $provider Identifier of the provider, as maxmind.
         * @param string $source   What was updated: the edition ID, as GeoLite2-City.
         * @param string $path     Path of the file.
         */
        do_action('gecka_geolocation_updated', $this->provider->id(), $edition, $databases->path($edition));
    }

    /**
     * Saves the state.
     *
     * @param array<string, mixed> $state
     *
     * @return void
     */
    private function save(array $state): void
    {
        update_site_option($this->option(), $state);
        $this->state = null;
    }

    /**
     * State of an edition never downloaded
     *
     * @return array{installed: int, remote: int, release: string, installed_release: string, downloaded: int, bytes: int, error: string}
     */
    private static function entry(): array
    {
        return ['installed' => 0, 'remote' => 0, 'release' => '', 'installed_release' => '', 'downloaded' => 0, 'bytes' => 0, 'error' => ''];
    }
}
