<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Gecka <contact@gecka.nc>
// SPDX-License-Identifier: GPL-3.0-or-later

use Gecka\WP\Plugin\Geolocation\MaxMind\Provider as MaxMind;
use Gecka\WP\Plugin\Geolocation\Mmdb\Updater;
use Gecka\WP\Plugin\Geolocation\Storage;

const STORAGE_URL = 'https://mm-prod-geoip-databases.example.r2.cloudflarestorage.com/';

/**
 * The answers of MaxMind for an edition: a HEAD and a GET of its permalink,
 * which redirects to the storage, and its checksum.
 *
 * @param string      $edition
 * @param string      $modified Last-Modified of the release.
 * @param string|null $archive  The archive, made from the test database of the edition by default.
 * @param string|null $checksum The checksum published, the one of the archive by default.
 *
 * @return array<string, array<string, mixed>>
 */
function release(string $edition, string $modified = 'Tue, 06 Oct 2026 12:00:00 GMT', ?string $archive = null, ?string $checksum = null): array
{
    $archive ??= make_tar([
        $edition . '_20261006/COPYRIGHT.txt' => 'Copyright MaxMind',
        $edition . '_20261006/' . $edition . '.mmdb' => (string) file_get_contents(fixture_path($edition)),
    ]);
    $storage = STORAGE_URL . $edition . '.tar.gz?X-Amz-Signature=abc';

    return [
        MaxMind::permalink($edition, 'tar.gz') => ['status' => 302, 'location' => $storage, 'modified' => $modified],
        $storage => ['body' => $archive, 'modified' => $modified],
        MaxMind::permalink($edition, 'tar.gz.sha256') => ['body' => ($checksum ?? hash('sha256', $archive)) . '  ' . $edition . '_20261006.tar.gz'],
    ];
}

/**
 * The updater of the MaxMind provider
 *
 * @return Updater
 */
function updater(): Updater
{
    return mmdb()->updater();
}

/**
 * Path of the file of a MaxMind edition
 *
 * @param string $edition
 *
 * @return string
 */
function maxmind_path(string $edition): string
{
    return mmdb()->databases()->path($edition);
}

beforeEach(function () {
    maxmind_settings(['account_id' => '123456', 'license_key' => 'abc_DEF123', 'editions' => ['GeoLite2-Country']]);
});

it('downloads the databases wanted, checked against their checksum', function () {
    $sent = fake_http(release('GeoLite2-Country'));
    $updated = [];
    add_action('gecka_geolocation_updated', function (string $provider, string $edition, string $path) use (&$updated) {
        $updated[] = [$provider, $edition, $path];
    }, 10, 3);

    expect(updater()->update())->toBeTrue()
        ->and(is_file(maxmind_path('GeoLite2-Country')))->toBeTrue()
        ->and(gecka_geolocation_country('81.2.69.142'))->toBe('GB')
        ->and($updated)->toBe([['maxmind', 'GeoLite2-Country', maxmind_path('GeoLite2-Country')]])
        ->and(updater()->state()['editions']['GeoLite2-Country']['installed'])->toBe(strtotime('Tue, 06 Oct 2026 12:00:00 GMT'))
        ->and(glob(GEOLOCATION_DIRECTORY . '/maxmind/*.part'))->toBe([])
        ->and(is_file(GEOLOCATION_DIRECTORY . '/.htaccess'))->toBeTrue();

    // The credentials go to MaxMind only, not to the storage it redirects to.
    foreach ($sent as $request) {
        $toMaxMind = str_starts_with($request['url'], 'https://' . MaxMind::HOST . '/');

        expect($request['authorization'])->toBe($toMaxMind ? 'Basic ' . base64_encode('123456:abc_DEF123') : null);
    }
});

it('downloads nothing while the release installed is the latest', function () {
    fake_http(release('GeoLite2-Country'));
    updater()->update();

    $sent = fake_http(release('GeoLite2-Country'));

    expect(updater()->check())->toBe([])
        ->and(array_column((array) $sent, 'method'))->toBe(['HEAD', 'HEAD']);
});

it('queues a database MaxMind released again', function () {
    fake_http(release('GeoLite2-Country'));
    updater()->update();

    fake_http(release('GeoLite2-Country', 'Thu, 08 Oct 2026 12:00:00 GMT'));

    expect(updater()->check())->toBe([['edition' => 'GeoLite2-Country', 'label' => 'GeoLite Country']])
        ->and(updater()->outdated('GeoLite2-Country'))->toBeGreaterThan(0);

    updater()->next();

    expect(updater()->outdated('GeoLite2-Country'))->toBe(0)
        ->and(updater()->state()['editions']['GeoLite2-Country']['installed'])->toBe(strtotime('Thu, 08 Oct 2026 12:00:00 GMT'));
});

it('keeps the database in place when the download does not match its checksum', function () {
    install_fixture('GeoLite2-Country');
    $before = md5_file(maxmind_path('GeoLite2-Country'));
    fake_http(release('GeoLite2-Country', checksum: str_repeat('0', 64)));

    expect(updater()->update())->toBeFalse()
        ->and(md5_file(maxmind_path('GeoLite2-Country')))->toBe($before)
        ->and(updater()->errors()['GeoLite2-Country'])->toContain('checksum')
        ->and(glob(GEOLOCATION_DIRECTORY . '/maxmind/*.part'))->toBe([]);
});

it('refuses an archive holding another database than the one asked for', function () {
    $archive = make_tar(['GeoLite2-Country_20261006/GeoLite2-Country.mmdb' => (string) file_get_contents(fixture_path('GeoLite2-City'))]);
    fake_http(release('GeoLite2-Country', archive: $archive));

    expect(updater()->update())->toBeFalse()
        ->and(is_file(maxmind_path('GeoLite2-Country')))->toBeFalse()
        ->and(updater()->errors()['GeoLite2-Country'])->toContain('GeoLite2-City');
});

it('stops at credentials MaxMind refuses', function () {
    fake_http([MaxMind::permalink('GeoLite2-Country', 'tar.gz') => ['status' => 401]]);

    expect(updater()->check())->toBeNull()
        ->and(updater()->errors()[''])->toBe(mmdb()->message(401, 'GeoLite2-Country'))
        ->and(updater()->state()['checked'])->toBe(0);
});

it('tells a database the account has no access to, and downloads the others', function () {
    maxmind_settings(['editions' => ['GeoLite2-Country', 'GeoIP2-City']]);
    fake_http(release('GeoLite2-Country') + [MaxMind::permalink('GeoIP2-City', 'tar.gz') => ['status' => 403]]);

    updater()->update();

    expect(is_file(maxmind_path('GeoLite2-Country')))->toBeTrue()
        ->and(updater()->state()['access'])->toBe(['GeoLite2-Country' => true, 'GeoIP2-City' => false])
        ->and(updater()->errors())->toBe(['GeoIP2-City' => mmdb()->message(403, 'GeoIP2-City')]);
});

it('stops at the daily download limit', function () {
    fake_http([MaxMind::permalink('GeoLite2-Country', 'tar.gz') => ['status' => 429]]);

    expect(updater()->check())->toBeNull()
        ->and(updater()->errors()[''])->toContain('daily download limit');
});

it('reports a server that cannot reach MaxMind', function () {
    expect(updater()->check())->toBeNull()
        ->and(updater()->errors()[''])->toContain('Cannot reach MaxMind');
});

it('asks for an account before anything', function () {
    maxmind_settings(['license_key' => '']);

    expect(updater()->due())->toBeFalse()
        ->and(updater()->check())->toBeNull()
        ->and(updater()->errors()[''])->toContain('credentials of your MaxMind account');
});

it('deletes a database no longer wanted', function () {
    install_fixture('GeoLite2-City');
    install_fixture('GeoLite2-Country');

    updater()->prune();

    expect(is_file(maxmind_path('GeoLite2-City')))->toBeFalse()
        ->and(is_file(maxmind_path('GeoLite2-Country')))->toBeTrue();
});

it('deletes the leftovers of a download that died', function () {
    Storage::prepare('maxmind');
    touch(GEOLOCATION_DIRECTORY . '/maxmind/GeoLite2-Country.tar.gz.old.part', time() - 2 * HOUR_IN_SECONDS);
    touch(GEOLOCATION_DIRECTORY . '/maxmind/GeoLite2-Country.tar.gz.new.part');

    updater()->prune();

    expect(array_map('basename', glob(GEOLOCATION_DIRECTORY . '/maxmind/*.part')))->toBe(['GeoLite2-Country.tar.gz.new.part']);
});

it('is due once a day, every hour while a database is missing, and right away while one is queued', function () {
    expect(updater()->due())->toBeTrue();

    update_site_option(updater()->option(), ['checked' => time() - 30 * MINUTE_IN_SECONDS]);
    expect(updater()->due())->toBeFalse();

    update_site_option(updater()->option(), ['checked' => time() - 2 * HOUR_IN_SECONDS]);
    expect(updater()->due())->toBeTrue();

    install_fixture('GeoLite2-Country');
    expect(updater()->due())->toBeFalse();

    update_site_option(updater()->option(), ['checked' => time() - 21 * HOUR_IN_SECONDS]);
    expect(updater()->due())->toBeTrue();

    update_site_option(updater()->option(), ['checked' => time(), 'pending' => ['GeoLite2-Country']]);
    expect(updater()->due())->toBeTrue();
});

it('tells a database past the limit of its licence', function () {
    install_fixture('GeoLite2-Country');
    $entry = ['installed' => time() - 40 * DAY_IN_SECONDS, 'remote' => time() - 10 * DAY_IN_SECONDS];

    update_site_option(updater()->option(), ['checked' => time(), 'editions' => ['GeoLite2-Country' => $entry]]);
    expect(updater()->stale())->toBe([]);

    update_site_option(updater()->option(), ['checked' => time(), 'editions' => ['GeoLite2-Country' => ['remote' => time() - 31 * DAY_IN_SECONDS] + $entry]]);
    expect(updater()->stale())->toBe(['GeoLite2-Country']);

    // No check went through for a month, and the release is older than any
    // MaxMind keeps that long.
    update_site_option(updater()->option(), ['checked' => time() - 31 * DAY_IN_SECONDS, 'editions' => ['GeoLite2-Country' => ['remote' => 0] + $entry]]);
    expect(updater()->stale())->toBe(['GeoLite2-Country']);

    // Lookups go on all the same.
    expect(gecka_geolocation_country('81.2.69.142'))->toBe('GB');
});

it('asks MaxMind which databases the account has', function () {
    $routes = [];

    foreach (['GeoLite2-Country', 'GeoLite2-City', 'GeoLite2-ASN'] as $edition) {
        $routes[MaxMind::permalink($edition, 'tar.gz')] = ['status' => 200, 'modified' => 'Tue, 06 Oct 2026 12:00:00 GMT'];
    }

    $sent = fake_http($routes + [MaxMind::permalink('GeoIP2-City', 'tar.gz') => ['status' => 403]]);
    $access = updater()->detect();

    expect($access['GeoLite2-City'])->toBeTrue()
        ->and($access['GeoIP2-City'])->toBeFalse()
        ->and(array_unique(array_column((array) $sent, 'method')))->toBe(['HEAD']);
});

it('lets one request update at a time', function () {
    $other = new Updater(mmdb());

    expect(updater()->lock())->toBeTrue()
        ->and($other->lock())->toBeFalse()
        ->and($other->update())->toBeNull();

    updater()->unlock();

    expect($other->lock())->toBeTrue();
    $other->unlock();
});

it('checks new credentials MaxMind refuses once more, half an hour later', function () {
    wp_clear_scheduled_hook(updater()->retryEvent());
    fake_http([MaxMind::permalink('GeoLite2-Country', 'tar.gz') => ['status' => 401]]);

    expect(updater()->detect())->toBeNull()
        ->and(wp_next_scheduled(updater()->retryEvent()))->toBeFalse()
        ->and(updater()->errors()[''])->toContain('quarter of an hour');

    expect(updater()->detect(true))->toBeNull()
        ->and(wp_next_scheduled(updater()->retryEvent()))->toBeGreaterThan(time() + Updater::RETRY_DELAY - 60);

    wp_clear_scheduled_hook(updater()->retryEvent());
});

it('downloads the databases once the retry finds the account accepted', function () {
    fake_http(release('GeoLite2-Country'));

    do_action(updater()->retryEvent());

    expect(updater()->state()['access']['GeoLite2-Country'])->toBeTrue()
        ->and(is_file(maxmind_path('GeoLite2-Country')))->toBeTrue()
        ->and(wp_next_scheduled(updater()->retryEvent()))->toBeFalse();
});

it('does not schedule a retry from the retry itself', function () {
    wp_clear_scheduled_hook(updater()->retryEvent());
    fake_http([MaxMind::permalink('GeoLite2-Country', 'tar.gz') => ['status' => 401]]);

    do_action(updater()->retryEvent());

    expect(wp_next_scheduled(updater()->retryEvent()))->toBeFalse()
        ->and(is_file(maxmind_path('GeoLite2-Country')))->toBeFalse();
});

it('refuses a download redirected off HTTPS', function () {
    fake_http([MaxMind::permalink('GeoLite2-Country', 'tar.gz') => ['status' => 302, 'location' => 'http://' . substr(STORAGE_URL, 8) . 'x.tar.gz', 'modified' => 'Tue, 06 Oct 2026 12:00:00 GMT']]);

    expect(updater()->check())->toBeNull()
        ->and(updater()->errors()[''])->toContain('insecure');
});

it('updates on the event the settings schedule, whatever the last check', function () {
    update_site_option(updater()->option(), ['checked' => time()]);
    fake_http(release('GeoLite2-Country'));

    expect(updater()->due())->toBeFalse();

    do_action(updater()->nowEvent());

    expect(is_file(maxmind_path('GeoLite2-Country')))->toBeTrue();
});

it('skips a queued database the settings no longer want', function () {
    maxmind_settings(['editions' => ['GeoLite2-Country', 'GeoLite2-ASN']]);
    fake_http(release('GeoLite2-Country') + release('GeoLite2-ASN'));
    updater()->check();

    expect(updater()->state()['pending'])->toBe(['GeoLite2-Country', 'GeoLite2-ASN']);

    update_site_option(mmdb()->settings()->option(), ['account_id' => '123456', 'license_key' => 'abc_DEF123', 'editions' => ['GeoLite2-ASN']]);

    expect(updater()->next()['edition'])->toBe('GeoLite2-ASN')
        ->and(is_file(maxmind_path('GeoLite2-Country')))->toBeFalse()
        ->and(updater()->next())->toBeNull();
});

it('queues downloads on a save only when something is to download', function () {
    install_fixture('GeoLite2-Country');

    expect(updater()->settingsChanged(['GeoLite2-Country']))->toBeFalse()
        ->and(updater()->takeQueued())->toBeFalse()
        ->and(updater()->settingsChanged(['GeoLite2-City']))->toBeTrue();
});

it('tells that databases wait when the time budget ran out, and queues them for the event', function () {
    maxmind_settings(['account_id' => '123456', 'license_key' => 'abc_DEF123', 'editions' => ['GeoLite2-Country', 'GeoLite2-ASN']]);
    fake_http(release('GeoLite2-Country') + release('GeoLite2-ASN'));
    wp_clear_scheduled_hook(updater()->nowEvent());

    expect(updater()->update(0.0))->toBeFalse()
        ->and(updater()->state()['pending'])->toBe(['GeoLite2-Country', 'GeoLite2-ASN'])
        ->and(updater()->errors())->toBe([])
        ->and(wp_next_scheduled(updater()->nowEvent()))->toBeLessThanOrEqual(time());

    $other = new Updater(mmdb());
    expect($other->lock())->toBeTrue();
    $other->unlock();
});

it('tries a failed download again an hour later', function () {
    maxmind_settings(['account_id' => '123456', 'license_key' => 'abc_DEF123', 'editions' => ['GeoLite2-Country']]);
    fake_http(release('GeoLite2-Country', checksum: str_repeat('0', 64)));
    wp_clear_scheduled_hook(updater()->nowEvent());

    expect(updater()->update())->toBeFalse()
        ->and(updater()->errors())->toHaveKey('GeoLite2-Country')
        ->and(wp_next_scheduled(updater()->nowEvent()))->toBeGreaterThan(time() + Updater::RETRY - 60);

    $other = new Updater(mmdb());
    expect($other->lock())->toBeTrue();
    $other->unlock();
});

it('counts an unreadable database as missing and downloads it again', function () {
    maxmind_settings(['account_id' => '123456', 'license_key' => 'abc_DEF123', 'editions' => ['GeoLite2-Country']]);
    fake_http(release('GeoLite2-Country'));

    expect(updater()->update())->toBeTrue()
        ->and(updater()->missing())->toBe([]);

    file_put_contents(maxmind_path('GeoLite2-Country'), 'junk');
    mmdb()->databases()->flush();

    expect(updater()->missing())->toBe(['GeoLite2-Country'])
        ->and(updater()->update())->toBeTrue()
        ->and(filesize(maxmind_path('GeoLite2-Country')))->toBeGreaterThan(100);
});

it('releases the lock when the provider refuses the credentials', function () {
    maxmind_settings(['account_id' => '123456', 'license_key' => 'abc_DEF123']);
    fake_http([MaxMind::permalink('GeoLite2-Country', 'tar.gz') => ['status' => 401]]);

    expect(updater()->update())->toBeFalse();

    $other = new Updater(mmdb());
    expect($other->lock())->toBeTrue();
    $other->unlock();
});
