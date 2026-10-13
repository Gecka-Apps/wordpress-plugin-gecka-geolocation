<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Gecka <contact@gecka.nc>
// SPDX-License-Identifier: GPL-3.0-or-later

use Gecka\WP\Plugin\Geolocation\Facet;
use Gecka\WP\Plugin\Geolocation\Record;

it('answers nothing before a database is installed', function () {
    expect(gecka_geolocation('81.2.69.142'))->toBeNull()
        ->and(gecka_geolocation_country('81.2.69.142'))->toBeNull()
        ->and(gecka_geolocation_available())->toBeFalse();
});

it('locates an address in a city database', function () {
    install_fixture('GeoLite2-City');

    $record = gecka_geolocation('81.2.69.142');

    expect($record)->toBeInstanceOf(Record::class)
        ->and($record->country())->toBe('GB')
        ->and($record->countryName('en_US'))->toBe('United Kingdom')
        ->and($record->continent())->toBe('EU')
        ->and($record->city('fr_FR'))->toBe('Londres')
        ->and($record->city('en_GB'))->toBe('London')
        ->and($record->subdivisions('en_US'))->toBe([['code' => 'ENG', 'name' => 'England']])
        ->and($record->region())->toBe('ENG')
        ->and($record->timeZone())->toBe('Europe/London')
        ->and($record->latitude())->toBeFloat()
        ->and($record->network())->toBe('81.2.69.142/31')
        ->and($record->source())->toBe('GeoLite2-City')
        ->and(gecka_geolocation_country('81.2.69.142'))->toBe('GB')
        ->and(gecka_geolocation_available(Facet::CITY))->toBeTrue()
        ->and(gecka_geolocation_available(Facet::ASN))->toBeFalse();
});

it('falls back on English for a name missing in the language asked', function () {
    install_fixture('GeoLite2-City');

    expect(gecka_geolocation('2.125.160.216')->city('fr_FR'))->toBe('Boxford');
});

it('maps the locales of WordPress to the languages of the databases', function (string $locale, string $language) {
    expect(Record::language($locale))->toBe($language);
})->with([
    ['fr_FR', 'fr'],
    ['fr_BE', 'fr'],
    ['pt_BR', 'pt-BR'],
    ['pt_PT', 'pt'],
    ['zh_CN', 'zh-CN'],
    ['en_US', 'en'],
]);

it('knows nothing of a private address', function () {
    install_fixture('GeoLite2-City');

    $record = gecka_geolocation('10.1.2.3');

    expect($record)->toBeInstanceOf(Record::class)
        ->and($record->country())->toBeNull()
        ->and($record->network())->toBeNull()
        ->and($record->toArray())->toBe(['ip' => '10.1.2.3']);
});

it('refuses what is not an IP address', function (string $ip) {
    install_fixture('GeoLite2-Country');

    expect(gecka_geolocation($ip))->toBeNull();
})->with(['', 'example.com', '999.1.1.1', '81.2.69.142/24']);

it('locates IPv6 addresses', function () {
    install_fixture('GeoLite2-Country');

    expect(gecka_geolocation_country('2001:218::1'))->toBe('JP')
        ->and(gecka_geolocation('2001:218::1')->network())->toBe('2001:218::/32');
});

it('prefers a paid database to a free one, and the most detailed', function () {
    install_fixture('GeoLite2-City');
    install_fixture('GeoIP2-Country');

    $record = gecka_geolocation('81.2.69.142');

    expect($record->source(Facet::COUNTRY))->toBe('GeoIP2-Country')
        ->and($record->source(Facet::CITY))->toBe('GeoLite2-City')
        ->and($record->city('en_US'))->toBe('London');
});

it('reads the network databases', function () {
    install_fixture('GeoLite2-ASN');
    install_fixture('GeoIP2-Connection-Type');
    install_fixture('GeoIP2-Domain');

    $record = gecka_geolocation('1.128.0.0');

    expect($record->asn())->toBe(1221)
        ->and($record->asOrganization())->toBe('Telstra Pty Ltd')
        ->and($record->isp())->toBeNull()
        ->and(gecka_geolocation('1.0.0.0')->connectionType())->toBe('Cable/DSL')
        ->and(gecka_geolocation('71.160.223.45')->domain())->toBe('verizon.net')
        ->and($record->country())->toBeNull();
});

it('takes the ISP database before the ASN one', function () {
    install_fixture('GeoLite2-ASN');
    install_fixture('GeoIP2-ISP');

    $record = gecka_geolocation('1.128.0.0');

    expect($record->source(Facet::ASN))->toBe('GeoIP2-ISP')
        ->and($record->isp())->toBe('Telstra Internet')
        ->and($record->organization())->toBe('Telstra Internet');
});

it('reads the network traits of the Enterprise database', function () {
    install_fixture('GeoIP2-Enterprise');

    $raw = gecka_geolocation('2.125.160.216')->raw();
    $record = gecka_geolocation('2.125.160.216');

    expect($record->country())->toBe('GB')
        ->and($record->city())->toBe('Boxford')
        ->and($record->asn())->toBe($raw['traits']['autonomous_system_number'] ?? null)
        ->and($record->connectionType())->toBe($raw['traits']['connection_type'] ?? null);
});

it('tells the anonymity flags only when a database holds them', function () {
    install_fixture('GeoLite2-Country');

    expect(gecka_geolocation('1.2.0.0')->anonymous())->toBeNull()
        ->and(gecka_geolocation('1.2.0.0')->isAnonymous())->toBeNull();

    install_fixture('GeoIP2-Anonymous-IP');

    $record = gecka_geolocation('1.2.0.0');

    expect($record->isAnonymous())->toBeTrue()
        ->and(array_keys(array_filter($record->anonymous())))->toBe(['is_anonymous', 'is_anonymous_vpn'])
        ->and(gecka_geolocation('81.2.69.1')->anonymous()['is_tor_exit_node'])->toBeTrue()
        ->and(gecka_geolocation('8.8.8.8')->isAnonymous())->toBeFalse();
});

it('locates the visitor by REMOTE_ADDR, or the address the filter gives', function () {
    install_fixture('GeoLite2-Country');
    $remote = $_SERVER['REMOTE_ADDR'] ?? null;
    $_SERVER['REMOTE_ADDR'] = '81.2.69.142';

    try {
        locates_the_visitor();
    } finally {
        if ($remote === null) {
            unset($_SERVER['REMOTE_ADDR']);
        } else {
            $_SERVER['REMOTE_ADDR'] = $remote;
        }
    }
});

/**
 * The assertions of the test above, the address of the server set.
 *
 * @return void
 */
function locates_the_visitor(): void
{
    expect(gecka_geolocation_client_ip())->toBe('81.2.69.142')
        ->and(gecka_geolocation_country())->toBe('GB');

    add_filter('gecka_geolocation_client_ip', static fn(): string => '216.160.83.56');

    expect(gecka_geolocation_client_ip())->toBe('216.160.83.56')
        ->and(gecka_geolocation_country())->toBe('US');

    add_filter('gecka_geolocation_client_ip', static fn(): string => 'unknown', 20);

    expect(gecka_geolocation_client_ip())->toBe('')
        ->and(gecka_geolocation())->toBeNull();
}

it('reports an unreadable file instead of failing', function () {
    $path = install_fixture('GeoLite2-Country');
    file_put_contents($path, 'not a database');

    expect(gecka_geolocation('81.2.69.142')?->country())->toBeNull()
        ->and(mmdb()->databases()->metadata('GeoLite2-Country'))->toBeNull()
        ->and(mmdb()->databases()->error('GeoLite2-Country'))->toBeString();
});

it('gives the flag of the country, as an emoji, an address and a file', function () {
    install_fixture('GeoLite2-Country');

    $record = gecka_geolocation('81.2.69.142');
    $ver = '?ver=' . \Gecka\WP\Plugin\Geolocation\Plugin::VERSION;

    expect($record->flag())->toBe('🇬🇧')
        ->and($record->flagUrl())->toStartWith('/')
        ->and($record->flagUrl())->toEndWith('/assets/flags/4x3/gb.svg' . $ver)
        ->and($record->flagUrl('1x1'))->toEndWith('/assets/flags/1x1/gb.svg' . $ver)
        ->and($record->flagUrl('4x3', true))->toBe(plugins_url('assets/flags/4x3/gb.svg', dirname(__DIR__, 2) . '/bootstrap.php') . $ver)
        ->and($record->flagUrl('16x9'))->toBeNull()
        ->and($record->flagPath())->toBe(dirname(__DIR__, 2) . '/assets/flags/4x3/gb.svg')
        ->and(is_file($record->flagPath('1x1')))->toBeTrue()
        ->and($record->toArray())->toMatchArray(['flag' => '🇬🇧', 'flag_url' => $record->flagUrl()])
        ->and($record->toArray())->not->toHaveKey('flag_path')
        ->and(gecka_geolocation('10.0.0.1')->flag())->toBeNull()
        ->and(gecka_geolocation('10.0.0.1')->flagUrl())->toBeNull()
        ->and(gecka_geolocation('10.0.0.1')->flagPath())->toBeNull();
});

it('gives the flag of a country code', function () {
    expect(gecka_geolocation_flag('nc'))->toBe('🇳🇨')
        ->and(gecka_geolocation_flag('EU'))->toBe('🇪🇺')
        ->and(gecka_geolocation_flag('FRA'))->toBeNull()
        ->and(gecka_geolocation_flag(''))->toBeNull()
        ->and(gecka_geolocation_flag_url('NC'))->toStartWith('/')
        ->and(gecka_geolocation_flag_url('NC', '1x1', true))->toStartWith('http')
        ->and(gecka_geolocation_flag_path('nc', '1x1'))->toEndWith('/assets/flags/1x1/nc.svg')
        ->and(gecka_geolocation_flag_url('../x'))->toBeNull()
        ->and(gecka_geolocation_flag_path('../x'))->toBeNull()
        ->and(gecka_geolocation_flag_url('QQ'))->toBeNull();
});

it('gives the same relative address whatever host the request computing it sees', function () {
    $relative = gecka_geolocation_flag_url('NC');

    add_filter('plugins_url', static fn(string $url): string => str_replace(home_url(), 'http://host.local', $url));

    expect(gecka_geolocation_flag_url('NC'))->toBe($relative)
        ->and(gecka_geolocation_flag_url('NC', absolute: true))->toStartWith('http://host.local/');
});

it('ships both shapes of the flags, Kosovo and the European Union included', function () {
    $missing = [];

    foreach (['AD', 'AE', 'AF', 'AU', 'BR', 'CN', 'DE', 'FR', 'GB', 'IN', 'JP', 'NC', 'PF', 'RE', 'US', 'WF', 'XK', 'EU'] as $code) {
        foreach (\Gecka\WP\Plugin\Geolocation\Flags::RATIOS as $ratio) {
            if (\Gecka\WP\Plugin\Geolocation\Flags::path($code, $ratio) === null) {
                $missing[] = "$code $ratio";
            }
        }
    }

    expect($missing)->toBe([]);
});

it('gives the credit of the GeoLite databases while one is installed, none for GeoIP alone', function () {
    expect(gecka_geolocation_credit('maxmind'))->toBeNull()
        ->and(gecka_geolocation_credit('nope'))->toBeNull();

    install_fixture('GeoIP2-Country');

    expect(gecka_geolocation_credit())->toBeNull()
        ->and(gecka_geolocation('81.2.69.142')->credit())->toBeNull();

    install_fixture('GeoLite2-ASN');

    expect(gecka_geolocation_credit())->toBe(['text' => 'This product includes GeoLite Data created by MaxMind, available from https://www.maxmind.com.', 'url' => 'https://www.maxmind.com']);
});

it('tells the membership of the European Union as MaxMind writes it, a false left out', function () {
    install_fixture('GeoLite2-Country');

    expect(gecka_geolocation('81.2.69.142')->inEuropeanUnion())->toBeFalse()
        ->and(gecka_geolocation('81.2.69.142')->raw()['country']['is_in_european_union'])->toBeFalse()
        ->and(gecka_geolocation('81.2.69.142')->toArray()['in_european_union'])->toBeFalse();
});

it('counts an address the anonymity database lacks as not anonymous', function () {
    install_fixture('GeoIP2-Anonymous-IP');

    expect(gecka_geolocation('192.168.1.1')->anonymous())->toBe(array_fill_keys(['is_anonymous', 'is_anonymous_vpn', 'is_hosting_provider', 'is_public_proxy', 'is_residential_proxy', 'is_tor_exit_node'], false))
        ->and(gecka_geolocation('192.168.1.1')->isAnonymous())->toBeFalse();
});

it('draws the flag emoji without mbstring', function () {
    expect(gecka_geolocation_flag('NC'))->toBe("\u{1F1F3}\u{1F1E8}")
        ->and(gecka_geolocation_flag('fr'))->toBe('🇫🇷');
});

it('falls back on the Portuguese and Chinese the records hold', function () {
    install_fixture('GeoLite2-City');

    expect(gecka_geolocation('81.2.69.142')->countryName('pt_PT'))->toBe('Reino Unido')
        ->and(gecka_geolocation('81.2.69.142')->countryName('zh_TW'))->toBe('英国')
        ->and(gecka_geolocation('81.2.69.142')->countryName('pt_BR'))->toBe('Reino Unido');
});
