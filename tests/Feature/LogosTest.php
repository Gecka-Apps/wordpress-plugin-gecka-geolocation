<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Gecka <contact@gecka.nc>
// SPDX-License-Identifier: GPL-3.0-or-later

use Gecka\WP\Plugin\Geolocation\Logos;
use Gecka\WP\Plugin\Geolocation\Setup;

/**
 * A PNG image of one pixel
 *
 * @return string
 */
function tiny_png(): string
{
    return (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=');
}

/**
 * Turns the logos on.
 *
 * @return void
 */
function logos_on(): void
{
    gecka_geolocation_plugin()->settings()->save(['network_logos' => true]);
}

beforeEach(function () {
    Logos::purge();
});

afterEach(function () {
    Logos::purge();
});

it('gives no logo and queues nothing while the setting is off', function () {
    expect(gecka_geolocation_network_logo_url(15169))->toBeNull()
        ->and(get_site_option(Logos::QUEUE_OPTION, []))->toBe([]);
});

it('queues a logo asked for, downloads it from the event, then gives its address', function () {
    logos_on();

    expect(gecka_geolocation_network_logo_url(15169))->toBeNull()
        ->and(get_site_option(Logos::QUEUE_OPTION))->toBe([15169])
        ->and(Setup::onMainSite(static fn() => wp_next_scheduled(Logos::EVENT)))->toBeInt();

    $sent = fake_http([Logos::SOURCE . '15169.png' => ['body' => tiny_png()]]);
    do_action(Logos::EVENT);

    expect(gecka_geolocation_network_logo_url(15169))->toEndWith('/' . Logos::FOLDER . '/15169.png')
        ->and(gecka_geolocation_network_logo_url(15169))->toStartWith('/')
        ->and(gecka_geolocation_network_logo_url(15169, true))->toStartWith('http')
        ->and(gecka_geolocation_plugin()->logos()->count())->toBe(1)
        ->and(get_site_option(Logos::QUEUE_OPTION))->toBe([])
        ->and(count($sent))->toBe(1);
});

it('gives the logo of the network of a record', function () {
    logos_on();
    install_fixture('GeoLite2-ASN');
    fake_http([Logos::SOURCE . '1221.png' => ['body' => tiny_png()]]);

    $record = gecka_geolocation('1.128.0.1');

    expect($record->asn())->toBe(1221)
        ->and($record->networkLogoUrl())->toBeNull();

    do_action(Logos::EVENT);

    expect($record->networkLogoUrl())->toEndWith('/1221.png')
        ->and($record->toArray()['network_logo_url'])->toEndWith('/1221.png');
});

it('does not ask again for a month a logo the source does not have', function () {
    logos_on();
    gecka_geolocation_network_logo_url(17480);
    $sent = fake_http([]);

    do_action(Logos::EVENT);

    expect(gecka_geolocation_network_logo_url(17480))->toBeNull()
        ->and(get_site_option(Logos::QUEUE_OPTION))->toBe([])
        ->and(count($sent))->toBe(1);

    $marker = wp_upload_dir(null, false)['basedir'] . '/' . Logos::FOLDER . '/17480.missing';

    expect(filemtime($marker))->toBeGreaterThan(time() + Logos::MISSING_DELAY - 60);
});

it('refuses what is not a PNG image, and tries a failed download again the next day', function (array $route, int $delay) {
    logos_on();
    gecka_geolocation_network_logo_url(64496);
    fake_http([Logos::SOURCE . '64496.png' => $route]);

    do_action(Logos::EVENT);

    $directory = wp_upload_dir(null, false)['basedir'] . '/' . Logos::FOLDER;

    expect(is_file($directory . '/64496.png'))->toBeFalse()
        ->and(glob($directory . '/*.part'))->toBe([])
        ->and(filemtime($directory . '/64496.missing'))->toBeGreaterThan(time() + $delay - 60)
        ->and(filemtime($directory . '/64496.missing'))->toBeLessThanOrEqual(time() + $delay);
})->with([
    'html' => [['body' => '<html><script>alert(1)</script></html>'], Logos::MISSING_DELAY],
    'svg' => [['body' => '<svg xmlns="http://www.w3.org/2000/svg"></svg>'], Logos::MISSING_DELAY],
    'truncated png' => [['body' => substr(tiny_png(), 0, 20)], Logos::MISSING_DELAY],
    'server error' => [['status' => 503], Logos::FAILED_DELAY],
]);

it('deletes the logos once the setting is turned off', function () {
    logos_on();
    gecka_geolocation_network_logo_url(15169);
    fake_http([Logos::SOURCE . '15169.png' => ['body' => tiny_png()]]);
    do_action(Logos::EVENT);

    gecka_geolocation_plugin()->settings()->save(['network_logos' => false]);

    expect(is_dir(wp_upload_dir(null, false)['basedir'] . '/' . Logos::FOLDER))->toBeFalse()
        ->and(gecka_geolocation_network_logo_url(15169))->toBeNull()
        ->and(get_site_option(Logos::QUEUE_OPTION, []))->toBe([]);
});

it('ignores what is not an autonomous system number', function () {
    logos_on();

    expect(gecka_geolocation_network_logo_url(0))->toBeNull()
        ->and(gecka_geolocation_network_logo_url(-5))->toBeNull()
        ->and(get_site_option(Logos::QUEUE_OPTION, []))->toBe([]);
});
