<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Gecka <contact@gecka.nc>
// SPDX-License-Identifier: GPL-3.0-or-later

use Gecka\WP\AdminMenu\Menus;
use Gecka\WP\Plugin\Geolocation\Admin\SettingsPage;
use Gecka\WP\Plugin\Geolocation\MaxMind\Provider as MaxMind;
use Gecka\WP\Plugin\Geolocation\Plugin;
use Mantle\Testing\Exceptions\WP_Die_Exception;

/*
 * An administrator manages the plugin on a single site; on a network, only a
 * super administrator does.
 */
$actAs = static function (string $role): void {
    $user = wp_insert_user(['user_login' => $role . '-' . wp_generate_password(6, false), 'user_pass' => wp_generate_password(), 'role' => $role]);

    if (is_multisite() && $role === 'administrator') {
        grant_super_admin($user);
    }

    wp_set_current_user($user);
};

/**
 * Posts a form of the settings page to its handler, and gives the address
 * it redirects to.
 *
 * @param string               $action
 * @param array<string, mixed> $fields
 * @param string|null          $nonce  Nonce action, the action by default.
 *
 * @return string
 */
function post_action(string $action, array $fields = [], ?string $nonce = null): string
{
    $_POST = $fields;
    $_REQUEST['_wpnonce'] = wp_create_nonce($nonce ?? $action);

    try {
        do_action('admin_post_' . $action);
    } catch (RuntimeException $e) {
        if (str_starts_with($e->getMessage(), 'Redirected to ')) {
            return substr($e->getMessage(), strlen('Redirected to '));
        }

        throw $e;
    }

    throw new RuntimeException('The handler did not redirect.');
}

/**
 * Posts a form of the tab of a provider, or another of its actions.
 *
 * @param string               $action  save_provider, update, detect or forget.
 * @param array<string, mixed> $fields
 * @param string               $provider
 *
 * @return string
 */
function post_provider(string $action, array $fields = [], string $provider = 'maxmind'): string
{
    $nonce = 'gecka_geolocation_' . ($action === 'save_provider' ? 'save' : $action) . '_' . $provider;

    return post_action('gecka_geolocation_' . $action, ['provider' => $provider] + $fields, $nonce);
}

beforeEach(function () {
    // The page hooks up in the admin only, and declares its tabs on init,
    // which fired before the test.
    $page = new SettingsPage(gecka_geolocation_plugin());
    $page->setup();
    $page->declare();

    // A handler ends with a redirection, then exit, which would end the run.
    add_filter('wp_redirect', static function (string $location): never {
        throw new RuntimeException('Redirected to ' . $location);
    });
});

afterEach(function () {
    unset($_REQUEST['_wpnonce']);
    $_POST = [];
    wp_set_current_user(0);
});

it('starts with GeoLite Country and no account', function () {
    $settings = mmdb()->settings();

    expect($settings->wanted())->toBe(['GeoLite2-Country'])
        ->and($settings->hasCredentials())->toBeFalse()
        ->and(gecka_geolocation_plugin()->settings()->woocommerce())->toBeFalse();
});

it('keeps the known editions only, each once, in a steady order', function () {
    expect(mmdb()->settings()->editions(['GeoLite2-ASN', 'Nope', 'GeoLite2-Country', 'GeoLite2-ASN', 3]))->toBe(['GeoLite2-Country', 'GeoLite2-ASN'])
        ->and(mmdb()->settings()->editions('GeoLite2-City'))->toBe([]);
});

it('cleans the account ID and the licence key', function (mixed $value, string $account, string $key) {
    expect(mmdb()->settings()->clean('account_id', $value))->toBe($account)
        ->and(mmdb()->settings()->clean('license_key', $value))->toBe($key);
})->with([
    [' 123456 ', '123456', '123456'],
    ['abc_DEF123', '', 'abc_DEF123'],
    ['abc DEF', '', ''],
    ['<script>', '', ''],
    [['123'], '', ''],
]);

it('saves the settings and asks MaxMind what the new account gives', function () use ($actAs) {
    $actAs('administrator');
    $sent = fake_http([MaxMind::permalink('GeoLite2-Country', 'tar.gz') => ['status' => 200], MaxMind::permalink('GeoLite2-City', 'tar.gz') => ['status' => 200]]);

    $location = post_provider('save_provider', [SettingsPage::FIELD => ['account_id' => '123456', 'license_key' => 'abc_DEF123', 'editions' => ['GeoLite2-City']]]);
    $settings = mmdb()->settings();

    expect($settings->value('account_id'))->toBe('123456')
        ->and($settings->value('license_key'))->toBe('abc_DEF123')
        ->and($settings->wanted())->toBe(['GeoLite2-City'])
        ->and(count($sent))->toBe(count(MaxMind::EDITIONS))
        ->and(mmdb()->updater()->state()['access']['GeoIP2-City'])->toBeFalse()
        ->and($location)->toContain('tab=databases')
        ->and($location)->toContain('detected=1')
        ->and(wp_next_scheduled(mmdb()->updater()->nowEvent()))->toBeInt()
        ->and(get_site_transient(mmdb()->updater()->queuedKey(get_current_user_id())))->toBe(1);
});

it('keeps the saved licence key when the field is left empty', function () use ($actAs) {
    $actAs('administrator');
    maxmind_settings(['account_id' => '123456', 'license_key' => 'abc_DEF123']);

    $location = post_provider('save_provider', [SettingsPage::FIELD => ['account_id' => '123456', 'license_key' => '', 'editions' => ['GeoLite2-Country']]]);

    expect(mmdb()->settings()->value('license_key'))->toBe('abc_DEF123')
        ->and($location)->not->toContain('detected');
});

it('refuses a malformed licence key and keeps the one saved', function () use ($actAs) {
    $actAs('administrator');
    maxmind_settings(['account_id' => '123456', 'license_key' => 'abc_DEF123']);

    $location = post_provider('save_provider', [SettingsPage::FIELD => ['account_id' => '123456', 'license_key' => 'bad key!', 'editions' => ['GeoLite2-Country']]]);

    expect(mmdb()->settings()->value('license_key'))->toBe('abc_DEF123')
        ->and($location)->toContain('invalid=license_key')
        ->and($location)->toContain('tab=maxmind');
});

it('deletes a database unticked', function () use ($actAs) {
    $actAs('administrator');
    maxmind_settings(['account_id' => '123456', 'license_key' => 'abc_DEF123', 'editions' => ['GeoLite2-Country', 'GeoLite2-ASN']]);
    install_fixture('GeoLite2-Country');
    install_fixture('GeoLite2-ASN');

    post_provider('save_provider', [SettingsPage::FIELD => ['account_id' => '123456', 'editions' => ['GeoLite2-Country']]]);

    expect(is_file(mmdb()->databases()->path('GeoLite2-ASN')))->toBeFalse()
        ->and(is_file(mmdb()->databases()->path('GeoLite2-Country')))->toBeTrue();
});

it('forgets the credentials', function () use ($actAs) {
    $actAs('administrator');
    maxmind_settings(['account_id' => '123456', 'license_key' => 'abc_DEF123']);

    $location = post_provider('forget');

    expect(mmdb()->settings()->value('license_key'))->toBe('')
        ->and(mmdb()->settings()->value('account_id'))->toBe('')
        ->and($location)->toContain('forgot=1')
        ->and($location)->toContain('tab=maxmind');
});

it('refuses every action to whoever cannot manage options', function (string $action) use ($actAs) {
    $actAs('editor');
    maxmind_settings(['account_id' => '123456', 'license_key' => 'abc_DEF123']);
    $fields = [SettingsPage::FIELD => ['account_id' => '1', 'license_key' => 'x']];

    expect(fn() => $action === 'save' ? post_action('gecka_geolocation_save', $fields) : post_provider($action, $fields))->toThrow(WP_Die_Exception::class);
    expect(mmdb()->settings()->value('license_key'))->toBe('abc_DEF123');
})->with(['save', 'save_provider', 'update', 'detect', 'forget']);

it('refuses an action for a provider the plugin does not download', function () use ($actAs) {
    $actAs('administrator');

    expect(fn() => post_provider('forget', [], 'nope'))->toThrow(WP_Die_Exception::class);
});

it('refuses the nonce of another provider', function () use ($actAs) {
    $actAs('administrator');
    maxmind_settings(['account_id' => '123456', 'license_key' => 'abc_DEF123']);

    expect(fn() => post_action('gecka_geolocation_forget', ['provider' => 'maxmind'], 'gecka_geolocation_forget_ipinfo'))->toThrow(WP_Die_Exception::class);
    expect(mmdb()->settings()->value('license_key'))->toBe('abc_DEF123');
});

it('refuses an action without its nonce', function () use ($actAs) {
    $actAs('administrator');
    $_POST = [SettingsPage::FIELD => ['account_id' => '1']];
    $_REQUEST['_wpnonce'] = 'wrong';

    expect(fn() => do_action('admin_post_gecka_geolocation_save'))->toThrow(WP_Die_Exception::class);
});

it('refuses the update routes to whoever cannot manage options', function () use ($actAs) {
    $actAs('editor');

    foreach (['check', 'next'] as $route) {
        $response = rest_do_request(new WP_REST_Request('POST', '/' . SettingsPage::REST_NAMESPACE . '/maxmind/update/' . $route));

        expect($response->get_status())->toBe(403);
    }
});

it('runs the update routes for an administrator', function () use ($actAs) {
    $actAs('administrator');
    maxmind_settings(['account_id' => '123456', 'license_key' => 'abc_DEF123']);
    fake_http([MaxMind::permalink('GeoLite2-Country', 'tar.gz') => ['status' => 401]]);

    $response = rest_do_request(new WP_REST_Request('POST', '/' . SettingsPage::REST_NAMESPACE . '/maxmind/update/check'));

    expect($response->get_status())->toBe(200)
        ->and($response->get_data())->toBe(['pending' => [], 'error' => mmdb()->message(401, 'GeoLite2-Country'), 'failed' => true]);

    $response = rest_do_request(new WP_REST_Request('POST', '/' . SettingsPage::REST_NAMESPACE . '/maxmind/update/next'));

    expect($response->get_data())->toBe(['done' => true, 'left' => 0]);
});

it('answers 404 on the update routes of an unknown provider', function () use ($actAs) {
    $actAs('administrator');

    $response = rest_do_request(new WP_REST_Request('POST', '/' . SettingsPage::REST_NAMESPACE . '/nope/update/check'));

    expect($response->get_status())->toBe(404);
});

it('saves the default provider, or none to take the first with data', function () use ($actAs) {
    $actAs('administrator');

    foreach (['dbip' => 'dbip', 'nope' => 'dbip', '' => ''] as $posted => $kept) {
        $location = post_action('gecka_geolocation_save', [SettingsPage::FIELD => ['provider' => $posted]]);

        expect(gecka_geolocation_plugin()->settings()->provider())->toBe($kept)
            ->and($location)->toContain('tab=settings');
    }
});

it('lets a filter change the capability', function () {
    add_filter('gecka_geolocation_capability', static fn(): string => 'edit_others_posts');

    expect(Plugin::capability())->toBe('edit_others_posts');

    add_filter('gecka_geolocation_capability', static fn(): string => '', 20);

    expect(Plugin::capability())->toBe(is_multisite() ? 'manage_network_options' : 'manage_options');
});

/**
 * Prints the tab of a provider, as the settings page does.
 *
 * @param string $provider
 *
 * @return string
 */
function render_provider_tab(string $provider = 'maxmind'): string
{
    require_once ABSPATH . 'wp-admin/includes/template.php';
    ob_start();
    Menus::options(SettingsPage::PAGE)->tab($provider)->runRender();

    return (string) ob_get_clean();
}

it('types the secrets in a masked text field, never carrying the one saved', function () use ($actAs) {
    $actAs('administrator');
    maxmind_settings(['account_id' => '123456', 'license_key' => 'abc_DEF123']);

    $html = render_provider_tab();

    expect($html)->not->toContain('type="password"')
        ->and($html)->not->toContain('abc_DEF123')
        ->and($html)->toContain('value="123456"')
        ->and($html)->toMatch('/<input type="text" class="regular-text code gecka-geolocation-masked" id="gecka-geolocation-maxmind-license-key"[^>]* value=""[^>]* placeholder="•/')
        ->and($html)->toContain('<button type="button" class="button gecka-geolocation-reveal hide-if-no-js" aria-pressed="false" aria-controls="gecka-geolocation-maxmind-license-key"')
        ->and($html)->toContain('Leave the field empty to keep it.')
        ->and($html)->toContain('Forget the credentials');
});
