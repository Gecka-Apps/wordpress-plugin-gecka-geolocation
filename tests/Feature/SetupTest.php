<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Gecka <contact@gecka.nc>
// SPDX-License-Identifier: GPL-3.0-or-later

use Gecka\WP\Plugin\Geolocation\Setup;
use Gecka\WP\Plugin\Geolocation\Storage;

it('schedules the daily check', function () {
    wp_clear_scheduled_hook(mmdb()->updater()->event());

    Setup::upgrade('1.0.0');

    expect(wp_next_scheduled(mmdb()->updater()->event()))->toBeInt()
        ->and(get_site_option(Setup::VERSION_OPTION))->toBe('1.0.0');
});

it('downloads right away once activated with an account', function () {
    wp_clear_scheduled_hook(mmdb()->updater()->nowEvent());
    maxmind_settings(['account_id' => '123456', 'license_key' => 'abc_DEF123']);

    Setup::activate();

    expect(wp_next_scheduled(mmdb()->updater()->nowEvent()))->toBeInt();
});

it('stops the events once deactivated', function () {
    Setup::activate();

    foreach (Setup::events() as $event) {
        wp_schedule_single_event(time() + 60, $event);
    }

    expect(wp_next_scheduled(mmdb()->updater()->nowEvent()))->toBeInt();

    Setup::deactivate();

    foreach (Setup::events() as $event) {
        expect(wp_next_scheduled($event))->toBeFalse();
    }
});

it('removes the databases, the options and the events once deleted', function () {
    maxmind_settings(['account_id' => '123456', 'license_key' => 'abc_DEF123']);
    install_fixture('GeoLite2-Country');
    Setup::activate();

    Setup::uninstall();

    expect(is_file(mmdb()->databases()->path('GeoLite2-Country')))->toBeFalse()
        ->and(is_file(GEOLOCATION_DIRECTORY . '/.htaccess'))->toBeTrue()
        ->and(get_site_option(mmdb()->settings()->option()))->toBeFalse()
        ->and(get_site_option(mmdb()->updater()->option()))->toBeFalse()
        ->and(wp_next_scheduled(mmdb()->updater()->event()))->toBeFalse();
});

it('removes its own directory under uploads', function () {
    remove_all_filters('gecka_geolocation_directory');
    $directory = Storage::directory();

    expect($directory)->toStartWith(wp_upload_dir(null, false)['basedir'] . '/' . Storage::PREFIX)
        ->and(Storage::owned())->toBeTrue();

    install_fixture('GeoLite2-Country');
    Storage::delete();

    expect(is_dir($directory))->toBeFalse();
});

it('names its directory with a random key, kept', function () {
    remove_all_filters('gecka_geolocation_directory');

    $first = Storage::directory();

    expect(basename($first))->toMatch('/^gecka-geolocation-[a-z0-9]{16}$/')
        ->and(Storage::directory())->toBe($first);
});
