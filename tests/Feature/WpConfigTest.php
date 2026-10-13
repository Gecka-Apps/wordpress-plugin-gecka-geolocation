<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Gecka <contact@gecka.nc>
// SPDX-License-Identifier: GPL-3.0-or-later

/*
 * Credentials defined in wp-config.php. A constant stays defined for the rest
 * of the run, so this file comes last in the order the files run in, and no
 * other test relies on the licence key of MaxMind being absent.
 */

use Gecka\WP\Plugin\Geolocation\Admin\SettingsPage;

beforeEach(function () {
    if (! defined('GECKA_GEOLOCATION_MAXMIND_LICENSE_KEY')) {
        define('GECKA_GEOLOCATION_MAXMIND_LICENSE_KEY', 'key_FROM_config');
    }

    $user = wp_insert_user(['user_login' => 'admin-' . wp_generate_password(6, false), 'user_pass' => wp_generate_password(), 'role' => 'administrator']);

    if (is_multisite()) {
        grant_super_admin($user);
    }

    wp_set_current_user($user);

    $page = new SettingsPage(gecka_geolocation_plugin());
    $page->setup();
    $page->declare();

    add_filter('wp_redirect', static function (string $location): never {
        throw new RuntimeException('Redirected to ' . $location);
    });
});

afterEach(function () {
    unset($_REQUEST['_wpnonce']);
    $_POST = [];
    wp_set_current_user(0);
});

it('takes the licence key from the constant and the account ID from the settings', function () {
    maxmind_settings(['account_id' => '123456', 'license_key' => 'abc_DEF123']);
    $settings = mmdb()->settings();

    expect($settings->fromConstant('license_key'))->toBeTrue()
        ->and($settings->fromConstant('account_id'))->toBeFalse()
        ->and($settings->fromConstants())->toBeTrue()
        ->and($settings->value('license_key'))->toBe('key_FROM_config')
        ->and($settings->value('account_id'))->toBe('123456')
        ->and($settings->hasCredentials())->toBeTrue();
});

it('disables the field of a credential wp-config.php defines and names the constant', function () {
    maxmind_settings(['account_id' => '123456']);

    $html = render_provider_tab();

    expect($html)->toMatch('/<input type="text" class="regular-text code gecka-geolocation-masked" id="gecka-geolocation-maxmind-license-key"[^>]* disabled/')
        ->and($html)->not->toContain('key_FROM_config')
        ->and($html)->not->toContain('gecka-geolocation-reveal')
        ->and($html)->toContain('Defined by <code>GECKA_GEOLOCATION_MAXMIND_LICENSE_KEY</code> in <code>wp-config.php</code>.')
        ->and($html)->toMatch('/<input type="text" class="regular-text code" id="gecka-geolocation-maxmind-account-id"[^>]* value="123456"(?![^>]* disabled)/')
        ->and($html)->toContain('Forget the credentials');
});

it('ignores a licence key posted while the constant defines it', function () {
    maxmind_settings(['account_id' => '123456']);
    $sent = fake_http([]);

    post_provider('save_provider', [SettingsPage::FIELD => ['account_id' => '654321', 'license_key' => 'posted_KEY', 'editions' => ['GeoLite2-Country']]]);

    expect(mmdb()->settings()->value('account_id'))->toBe('654321')
        ->and(mmdb()->settings()->value('license_key'))->toBe('key_FROM_config')
        ->and(mmdb()->settings()->all()['license_key'])->toBe('')
        ->and($sent[0]['authorization'])->toBe('Basic ' . base64_encode('654321:key_FROM_config'));
});

it('hides the forget button once every credential comes from wp-config.php', function () {
    maxmind_settings(['account_id' => '']);

    expect(render_provider_tab())->not->toContain('Forget the credentials');
});
