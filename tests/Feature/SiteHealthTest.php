<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Gecka <contact@gecka.nc>
// SPDX-License-Identifier: GPL-3.0-or-later

use Gecka\WP\Plugin\Geolocation\Admin\SiteHealth;
use Gecka\WP\Plugin\Geolocation\Storage;

beforeEach(function () {
    $this->health = new SiteHealth(gecka_geolocation_plugin());
});

afterEach(function () {
    wp_set_current_user(0);
});

it('adds its tests without a user, as the weekly event and WP-CLI run them, and for a user with the capability', function () {
    expect(array_keys($this->health->tests(['direct' => []])['direct']))->toBe(['gecka_geolocation_databases', 'gecka_geolocation_directory']);

    $user = wp_insert_user(['user_login' => 'reader-' . wp_generate_password(6, false), 'user_pass' => wp_generate_password(), 'role' => 'subscriber']);
    wp_set_current_user($user);

    expect($this->health->tests(['direct' => []]))->toBe(['direct' => []]);
});

it('recommends choosing databases while no provider is configured', function () {
    $result = $this->health->testDatabases();

    expect($result['status'])->toBe('recommended')
        ->and($result['label'])->toContain('downloads no database');
});

it('counts an unreadable database as missing', function () {
    maxmind_settings(['account_id' => '123456', 'license_key' => 'abc_DEF123']);
    install_fixture('GeoLite2-Country');
    file_put_contents(mmdb()->databases()->path('GeoLite2-Country'), 'junk');
    mmdb()->databases()->flush();

    $result = $this->health->testDatabases();

    expect($result['status'])->toBe('recommended')
        ->and($result['description'])->toContain('Missing or unreadable');
});

it('warns when the web server serves the directory of the databases', function () {
    remove_all_filters('gecka_geolocation_directory');
    Storage::prepare('maxmind');
    $url = Storage::url();

    try {
        expect($url)->toBeString();

        fake_http([$url . '/index.php' => ['status' => 200]]);
        $result = $this->health->testDirectory();

        expect($result['status'])->toBe('critical')
            ->and($result['label'])->toContain('reachable from the web');

        fake_http([$url . '/index.php' => ['status' => 403]]);

        expect($this->health->testDirectory()['status'])->toBe('good');
    } finally {
        Storage::delete();
    }
});

it('does not probe a directory the site chose', function () {
    expect(Storage::url())->toBeNull()
        ->and($this->health->testDirectory()['status'])->toBe('good');
});
