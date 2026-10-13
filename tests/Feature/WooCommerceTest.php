<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Gecka <contact@gecka.nc>
// SPDX-License-Identifier: GPL-3.0-or-later

use Gecka\WP\Plugin\Geolocation\Integrations\WooCommerce;

$empty = ['country' => '', 'state' => '', 'city' => '', 'postcode' => ''];

it('fills the location WooCommerce asks for', function () use ($empty) {
    gecka_geolocation_plugin()->settings()->save(['woocommerce' => true]);
    install_fixture('GeoLite2-City');

    expect((new WooCommerce(gecka_geolocation_plugin()))->geolocation($empty, '216.160.83.56'))->toBe([
        'country' => 'US',
        'state' => '',
        'city' => 'Milton',
        'postcode' => '98354',
    ]);
});

it('keeps a country WooCommerce already has', function () use ($empty) {
    install_fixture('GeoLite2-City');
    $data = ['country' => 'NC'] + $empty;

    expect((new WooCommerce(gecka_geolocation_plugin()))->geolocation($data, '216.160.83.56'))->toBe($data);
});

it('leaves the location empty when the address is unknown', function () use ($empty) {
    gecka_geolocation_plugin()->settings()->save(['woocommerce' => true]);
    install_fixture('GeoLite2-Country');

    expect((new WooCommerce(gecka_geolocation_plugin()))->geolocation($empty, '10.0.0.1'))->toBe($empty)
        ->and((new WooCommerce(gecka_geolocation_plugin()))->geolocation($empty, ''))->toBe($empty);
});

it('stays off without WooCommerce, whatever the setting', function () {
    maxmind_settings(['woocommerce' => true]);

    expect(WooCommerce::available())->toBeFalse()
        ->and((new WooCommerce(gecka_geolocation_plugin()))->active())->toBeFalse();
});

it('leaves the location alone while the setting is off', function () use ($empty) {
    install_fixture('GeoLite2-City');

    expect((new WooCommerce(gecka_geolocation_plugin()))->geolocation($empty, '216.160.83.56'))->toBe($empty);
});
