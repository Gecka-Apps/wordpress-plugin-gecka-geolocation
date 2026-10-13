<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Gecka <contact@gecka.nc>
// SPDX-License-Identifier: GPL-3.0-or-later

use Gecka\WP\AdminMenu\Menus;
use Gecka\WP\Plugin\Geolocation\Admin\Notices;
use Gecka\WP\Plugin\Geolocation\Admin\SettingsPage;
use Gecka\WP\Plugin\Geolocation\Logos;
use Gecka\WP\Plugin\Geolocation\Plugin;
use Gecka\WP\Plugin\Geolocation\Setup;
use Gecka\WP\Plugin\Geolocation\Storage;

/*
 * What a network gets on top of the suite: run with WP_MULTISITE=1, where
 * WordPress is installed as a multisite; skipped on a single site.
 */
beforeEach(function () {
    if (! is_multisite()) {
        $this->markTestSkipped('A network only.');
    }

    $this->second ??= (int) wpmu_create_blog('example.org', '/second/', 'Second site', 1);
});

afterEach(function () {
    while (is_multisite() && ms_is_switched()) {
        restore_current_blog();
    }
});

it('keeps its settings and state for the whole network', function () {
    maxmind_settings(['account_id' => '123456', 'editions' => ['GeoLite2-City']]);

    expect(get_site_option(mmdb()->settings()->option())['account_id'])->toBe('123456')
        ->and(get_option(mmdb()->settings()->option()))->toBeFalse()
        ->and(Plugin::capability())->toBe('manage_network_options');

    switch_to_blog($this->second);
    mmdb()->settings()->flush();

    expect(mmdb()->settings()->wanted())->toBe(['GeoLite2-City']);
});

it('keeps one directory, under the uploads of the main site, for every site', function () {
    remove_all_filters('gecka_geolocation_directory');
    $main = Storage::directory();

    switch_to_blog($this->second);

    expect(Storage::directory())->toBe($main)
        ->and($main)->not->toContain('/sites/');

    restore_current_blog();
    remove_tree($main);
});

it('answers every site from the same databases', function () {
    install_fixture('GeoLite2-City');

    switch_to_blog($this->second);

    expect(gecka_geolocation_country('81.2.69.142'))->toBe('GB')
        ->and(gecka_geolocation('81.2.69.142')->city('en_US'))->toBe('London');
});

it('gives every site the address of the flags the main site gives', function () {
    $main = gecka_geolocation_flag_url('NC');
    $absolute = gecka_geolocation_flag_url('NC', absolute: true);

    switch_to_blog($this->second);
    add_filter('plugins_url', static fn(string $url): string => str_replace(untrailingslashit(get_site_url(get_main_site_id())), untrailingslashit(site_url()), $url));

    expect(plugins_url('x', dirname(__DIR__, 2) . '/bootstrap.php'))->toContain('/second/')
        ->and(gecka_geolocation_flag_url('NC'))->toBe($main)
        ->and(gecka_geolocation_flag_url('NC', absolute: true))->toBe($absolute);
});

it('puts its page under the Settings menu of the network admin', function () {
    // The screens hook up in the admin only; the page declares on init,
    // which fired before the test.
    (new SettingsPage(gecka_geolocation_plugin()))->declare();
    (new Notices(gecka_geolocation_plugin()))->setup();
    $page = Menus::options(SettingsPage::PAGE);

    expect($page->isNetwork())->toBeTrue()
        ->and(SettingsPage::url('settings'))->toStartWith(network_admin_url('settings.php'))
        ->and(has_action('network_admin_notices'))->toBeTrue();
});

it('refuses the settings to the administrator of a site', function () {
    $admin = wp_insert_user(['user_login' => 'site-admin-' . wp_generate_password(6, false), 'user_pass' => wp_generate_password(), 'role' => 'administrator']);
    wp_set_current_user($admin);

    expect(current_user_can(Plugin::capability()))->toBeFalse();

    grant_super_admin($admin);

    expect(current_user_can(Plugin::capability()))->toBeTrue();

    revoke_super_admin($admin);
    wp_set_current_user(0);
});

it('schedules its events on the main site, whichever site runs the setup', function () {
    Setup::deactivate();
    maxmind_settings(['account_id' => '123456', 'license_key' => 'abc_DEF123']);

    switch_to_blog($this->second);
    Setup::activate();
    mmdb()->updater()->settingsChanged([]);
    $onSecond = [wp_next_scheduled(mmdb()->updater()->event()), wp_next_scheduled(mmdb()->updater()->nowEvent())];
    restore_current_blog();

    expect($onSecond)->toBe([false, false])
        ->and(wp_next_scheduled(mmdb()->updater()->event()))->toBeInt()
        ->and(wp_next_scheduled(mmdb()->updater()->nowEvent()))->toBeInt();

    switch_to_blog($this->second);
    Setup::deactivate();
    restore_current_blog();

    expect(wp_next_scheduled(mmdb()->updater()->event()))->toBeFalse()
        ->and(wp_next_scheduled(mmdb()->updater()->nowEvent()))->toBeFalse();
});

it('keeps the logos of the networks under the uploads of the main site, for every site', function () {
    gecka_geolocation_plugin()->settings()->save(['network_logos' => true]);
    fake_http([Logos::SOURCE . '15169.png' => ['body' => (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=')]]);

    switch_to_blog($this->second);
    gecka_geolocation_network_logo_url(15169);
    $onSecond = wp_next_scheduled(Logos::EVENT);
    restore_current_blog();

    do_action(Logos::EVENT);
    $main = gecka_geolocation_network_logo_url(15169);

    switch_to_blog($this->second);
    $second = gecka_geolocation_network_logo_url(15169);
    restore_current_blog();

    expect($onSecond)->toBeFalse()
        ->and($main)->toEndWith('/15169.png')
        ->and($second)->toBe($main)
        ->and($main)->not->toContain('/sites/');

    Logos::purge();
});
