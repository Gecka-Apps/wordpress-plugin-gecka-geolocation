<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Gecka <contact@gecka.nc>
// SPDX-License-Identifier: GPL-3.0-or-later

use Gecka\WP\Plugin\Geolocation\Admin\SettingsPage;
use Gecka\WP\Plugin\Geolocation\Facet;
use Gecka\WP\Plugin\Geolocation\Integrations\WooCommerce;
use Gecka\WP\Plugin\Geolocation\Provider;

/**
 * A provider answering every address with one country, as another plugin
 * would add it.
 *
 * @param string $id
 * @param string $country
 *
 * @return Provider
 */
function fake_provider(string $id = 'fake', string $country = 'NC'): Provider
{
    return new class ($id, $country) implements Provider {
        public function __construct(private string $id, private string $country) {}

        public function id(): string
        {
            return $this->id;
        }

        public function label(): string
        {
            return 'Fake ' . $this->country;
        }

        public function available(string $facet): bool
        {
            return $facet === Facet::COUNTRY;
        }

        public function lookup(string $facet, string $ip): ?array
        {
            return $facet === Facet::COUNTRY && ! str_starts_with($ip, '10.')
                ? ['data' => ['country' => ['iso_code' => $this->country, 'names' => ['en' => 'Somewhere']]], 'network' => null, 'source' => 'fake-list']
                : null;
        }

        public function credit(): ?array
        {
            return ['text' => 'Data by Fake', 'url' => null];
        }
    };
}

/**
 * Adds providers through the filter, the way another plugin does.
 *
 * @param Provider ...$providers
 *
 * @return void
 */
function add_providers(Provider ...$providers): void
{
    add_filter('gecka_geolocation_providers', static function (array $list) use ($providers): array {
        foreach ($providers as $provider) {
            $list[$provider->id()] = $provider;
        }

        return $list;
    });
    gecka_geolocation_plugin()->flush();
}

it('offers MaxMind, DB-IP and IPinfo, MaxMind first while none has data', function () {
    expect(gecka_geolocation_providers())->toBe(['maxmind' => 'MaxMind', 'dbip' => 'DB-IP', 'ipinfo' => 'IPinfo'])
        ->and(gecka_geolocation_plugin()->defaultProvider())->toBe('maxmind');
});

it('asks by default the first provider with data in place', function () {
    install_fixture('dbip-country-lite', make_mmdb(GEOLOCATION_DIRECTORY . '-dbip.mmdb', 'DBIP-Country-Lite', ['81.2.69.0/24' => ['country' => ['iso_code' => 'FR']]]), 'dbip');

    expect(gecka_geolocation_plugin()->defaultProvider())->toBe('dbip')
        ->and(gecka_geolocation_country('81.2.69.142'))->toBe('FR');

    install_fixture('GeoLite2-Country');
    gecka_geolocation_plugin()->flush();

    expect(gecka_geolocation_plugin()->defaultProvider())->toBe('maxmind')
        ->and(gecka_geolocation_country('81.2.69.142'))->toBe('GB');

    unlink(GEOLOCATION_DIRECTORY . '-dbip.mmdb');
});

it('takes the providers another plugin adds, and asks the one named', function () {
    install_fixture('GeoLite2-Country');
    add_providers(fake_provider());

    $record = gecka_geolocation('81.2.69.142', 'fake');

    expect(gecka_geolocation_providers())->toBe(['maxmind' => 'MaxMind', 'dbip' => 'DB-IP', 'ipinfo' => 'IPinfo', 'fake' => 'Fake NC'])
        ->and($record->provider())->toBe('fake')
        ->and($record->country())->toBe('NC')
        ->and($record->source())->toBe('fake-list')
        ->and($record->city())->toBeNull()
        ->and(gecka_geolocation_country('81.2.69.142'))->toBe('GB')
        ->and(gecka_geolocation_country('81.2.69.142', 'fake'))->toBe('NC')
        ->and(gecka_geolocation_available(Facet::CITY, 'fake'))->toBeFalse()
        ->and(gecka_geolocation_available(Facet::COUNTRY, 'fake'))->toBeTrue();
});

it('answers nothing for a provider that is not registered', function () {
    install_fixture('GeoLite2-Country');

    expect(gecka_geolocation('81.2.69.142', 'nope'))->toBeNull()
        ->and(gecka_geolocation_country('81.2.69.142', 'nope'))->toBeNull()
        ->and(gecka_geolocation_available(Facet::COUNTRY, 'nope'))->toBeFalse();
});

it('asks the provider of the settings when none is named', function () {
    add_providers(fake_provider());
    gecka_geolocation_plugin()->settings()->save(['provider' => 'fake']);

    expect(gecka_geolocation_plugin()->defaultProvider())->toBe('fake')
        ->and(array_key_first(gecka_geolocation_providers()))->toBe('fake')
        ->and(gecka_geolocation_country('81.2.69.142'))->toBe('NC');
});

it('lets a plugin choose the default provider', function () {
    add_providers(fake_provider(), fake_provider('other', 'FR'));
    add_filter('gecka_geolocation_default_provider', static fn(string $provider, array $available): string => in_array('other', $available, true) ? 'other' : $provider, 10, 2);

    expect(gecka_geolocation_country('81.2.69.142'))->toBe('FR');
});

it('falls back on the first provider when the default one is gone', function () {
    add_filter('gecka_geolocation_providers', static fn(): array => ['fake' => fake_provider()]);
    gecka_geolocation_plugin()->flush();

    expect(gecka_geolocation_plugin()->defaultProvider())->toBe('fake')
        ->and(gecka_geolocation_country('81.2.69.142'))->toBe('NC');
});

it('leaves out what is no provider, or one with a malformed identifier', function () {
    add_filter('gecka_geolocation_providers', static fn(array $list): array => $list + ['junk' => new stdClass(), 'Bad ID' => fake_provider('Bad ID'), 'ok' => fake_provider('ok')]);
    gecka_geolocation_plugin()->flush();

    expect(array_keys(gecka_geolocation_plugin()->providers()))->toBe(['maxmind', 'dbip', 'ipinfo', 'ok']);

    add_filter('gecka_geolocation_providers', static fn(): string => 'nothing', 20);
    gecka_geolocation_plugin()->flush();

    expect(gecka_geolocation_plugin()->providers())->toBe([])
        ->and(gecka_geolocation('81.2.69.142'))->toBeNull();
});

it('saves the default provider chosen in the settings, when it is registered', function () {
    $user = wp_insert_user(['user_login' => 'admin-' . wp_generate_password(6, false), 'user_pass' => wp_generate_password(), 'role' => 'administrator']);

    if (is_multisite()) {
        grant_super_admin($user);
    }

    wp_set_current_user($user);
    add_providers(fake_provider());
    (new SettingsPage(gecka_geolocation_plugin()))->setup();
    add_filter('wp_redirect', static function (string $location): never {
        throw new RuntimeException('Redirected to ' . $location);
    });

    foreach (['fake' => 'fake', 'nope' => 'fake'] as $posted => $kept) {
        $_POST = [SettingsPage::FIELD => ['provider' => $posted, 'editions' => ['GeoLite2-Country']]];
        $_REQUEST['_wpnonce'] = wp_create_nonce('gecka_geolocation_save');

        try {
            do_action('admin_post_gecka_geolocation_save');
        } catch (RuntimeException) {
        }

        expect(gecka_geolocation_plugin()->settings()->provider())->toBe($kept);
    }

    $_POST = [];
    unset($_REQUEST['_wpnonce']);
    wp_set_current_user(0);
});

it('locates the customers of WooCommerce with the default provider', function () {
    add_providers(fake_provider());
    gecka_geolocation_plugin()->settings()->save(['provider' => 'fake', 'woocommerce' => true]);

    expect((new WooCommerce(gecka_geolocation_plugin()))->geolocation(['country' => '', 'state' => '', 'city' => '', 'postcode' => ''], '203.0.113.9'))
        ->toBe(['country' => 'NC', 'state' => '', 'city' => '', 'postcode' => '']);
});

it('treats an empty provider name as the default one', function () {
    install_fixture('GeoLite2-Country');

    expect(gecka_geolocation_country('81.2.69.142', ''))->toBe('GB');
});

it('keeps answering when a provider another plugin added fails', function () {
    install_fixture('GeoLite2-Country');
    $broken = new class implements Provider {
        public function id(): string
        {
            return 'broken';
        }

        public function label(): string
        {
            return 'Broken';
        }

        public function available(string $facet): bool
        {
            throw new RuntimeException('down');
        }

        public function lookup(string $facet, string $ip): ?array
        {
            throw new RuntimeException('down');
        }

        public function credit(): ?array
        {
            return null;
        }
    };
    $odd = new class implements Provider {
        public function id(): string
        {
            return 'odd';
        }

        public function label(): string
        {
            return 'Odd';
        }

        public function available(string $facet): bool
        {
            return true;
        }

        public function lookup(string $facet, string $ip): ?array
        {
            return ['nothing' => 'useful'];
        }

        public function credit(): ?array
        {
            return null;
        }
    };
    add_providers($broken, $odd);
    gecka_geolocation_plugin()->settings()->save(['provider' => '']);
    add_filter('gecka_geolocation_providers', static fn(array $list): array => ['broken' => $list['broken']] + $list, 20);
    gecka_geolocation_plugin()->flush();

    expect(array_key_first(gecka_geolocation_plugin()->providers()))->toBe('broken')
        ->and(gecka_geolocation_plugin()->defaultProvider())->toBe('maxmind')
        ->and(gecka_geolocation_country('81.2.69.142'))->toBe('GB')
        ->and(gecka_geolocation('81.2.69.142', 'broken'))->toBeNull()
        ->and(gecka_geolocation('81.2.69.142', 'odd')->country())->toBeNull()
        ->and(gecka_geolocation('81.2.69.142', 'odd')->raw())->toBeNull();
});

it('refuses a provider whose identifier starts with a digit or carries a newline', function () {
    add_filter('gecka_geolocation_providers', static fn(array $list): array => $list + ['1st' => fake_provider('1st'), "nl\n" => fake_provider("nl\n")]);
    gecka_geolocation_plugin()->flush();

    expect(array_keys(gecka_geolocation_plugin()->providers()))->toBe(['maxmind', 'dbip', 'ipinfo']);
});
