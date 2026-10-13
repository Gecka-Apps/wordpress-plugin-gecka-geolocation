<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Gecka <contact@gecka.nc>
// SPDX-License-Identifier: GPL-3.0-or-later

use Gecka\WP\Plugin\Geolocation\Facet;

/**
 * An IPinfo Lite database, with flat records
 *
 * @param bool $probe Whether it answers the address the plugin checks a download with.
 *
 * @return string
 */
function ipinfo_release(bool $probe = true): string
{
    $networks = [
        '203.147.64.0/19' => ['network' => '203.147.64.0/19', 'country' => 'New Caledonia', 'country_code' => 'NC', 'continent' => 'Oceania', 'continent_code' => 'OC', 'asn' => 'AS17480', 'as_name' => "CAN'L", 'as_domain' => 'canl.nc'],
    ];

    if ($probe) {
        $networks['8.8.8.0/24'] = ['network' => '8.8.8.0/24', 'country' => 'United States', 'country_code' => 'US', 'continent' => 'North America', 'continent_code' => 'NA', 'asn' => 'AS15169', 'as_name' => 'Google LLC', 'as_domain' => 'google.com'];
    }

    $path = make_mmdb(GEOLOCATION_DIRECTORY . '-ipinfo.mmdb', 'ipinfo bundle_location_lite.mmdb', $networks);
    $content = (string) file_get_contents($path);

    unlink($path);

    return $content;
}

/**
 * The answers of IPinfo: the checksums, then the file, which redirects to
 * a storage
 *
 * @param string      $file
 * @param string|null $sha256 The checksum published, the one of the file by default.
 *
 * @return array<string, array<string, mixed>>
 */
function ipinfo_routes(string $file, ?string $sha256 = null): array
{
    $storage = 'https://storage.example.com/ipinfo_lite.mmdb?signature=abc';

    return [
        'https://ipinfo.io/data/ipinfo_lite.mmdb/checksums?token=tok123' => ['body' => (string) wp_json_encode(['checksums' => ['md5' => md5($file), 'sha1' => sha1($file), 'sha256' => $sha256 ?? hash('sha256', $file)]])],
        'https://ipinfo.io/data/ipinfo_lite.mmdb?token=tok123' => ['status' => 302, 'location' => $storage],
        $storage => ['body' => $file],
    ];
}

it('waits for a token, its only database chosen', function () {
    expect(mmdb('ipinfo')->settings()->wanted())->toBe(['ipinfo_lite'])
        ->and(mmdb('ipinfo')->settings()->configured())->toBeFalse()
        ->and(mmdb('ipinfo')->updater()->due())->toBeFalse();
});

it('puts a flat record in the shape of the GeoIP2 databases', function () {
    $record = mmdb('ipinfo')->normalize(['network' => '203.147.64.0/19', 'country' => 'New Caledonia', 'country_code' => 'NC', 'continent' => 'Oceania', 'continent_code' => 'OC', 'asn' => 'AS17480', 'as_name' => "CAN'L", 'as_domain' => 'canl.nc'], 'ipinfo_lite');

    expect($record)->toBe([
        'country' => ['iso_code' => 'NC', 'names' => ['en' => 'New Caledonia']],
        'continent' => ['code' => 'OC', 'names' => ['en' => 'Oceania']],
        'autonomous_system_number' => 17480,
        'autonomous_system_organization' => "CAN'L",
        'as_domain' => 'canl.nc',
    ])
        ->and(mmdb('ipinfo')->normalize(['country_code' => '', 'asn' => 'nope'], 'ipinfo_lite'))->toBe([]);
});

it('downloads the release the checksums name, and again only once they change', function () {
    mmdb('ipinfo')->settings()->save(['token' => 'tok123']);
    $file = ipinfo_release();
    $sent = fake_http(ipinfo_routes($file));

    expect(mmdb('ipinfo')->updater()->update())->toBeTrue();

    $record = gecka_geolocation('203.147.65.1', 'ipinfo');

    expect($record->country())->toBe('NC')
        ->and($record->countryName())->toBe('New Caledonia')
        ->and($record->continent())->toBe('OC')
        ->and($record->inEuropeanUnion())->toBeNull()
        ->and($record->toArray())->not->toHaveKey('in_european_union')
        ->and($record->asn())->toBe(17480)
        ->and($record->asOrganization())->toBe("CAN'L")
        ->and($record->asDomain())->toBe('canl.nc')
        ->and($record->toArray()['as_domain'])->toBe('canl.nc')
        ->and($record->network(Facet::ASN))->toBe('203.147.64.0/19')
        ->and($record->city())->toBeNull()
        ->and(gecka_geolocation_available(Facet::CITY, 'ipinfo'))->toBeFalse()
        ->and(array_column((array) $sent, 'method'))->toBe(['GET', 'GET', 'GET']);

    // The token stays out of the request to the storage.
    foreach ($sent as $request) {
        expect(str_contains($request['url'], 'tok123'))->toBe(str_starts_with($request['url'], 'https://ipinfo.io/'));
    }

    $sent = fake_http(ipinfo_routes($file));

    expect(mmdb('ipinfo')->updater()->check())->toBe([])
        ->and(count($sent))->toBe(1)
        ->and(mmdb('ipinfo')->updater()->outdated('ipinfo_lite'))->toBe(0);

    fake_http(ipinfo_routes($file, str_repeat('a', 64)));

    expect(mmdb('ipinfo')->updater()->check())->toBe([['edition' => 'ipinfo_lite', 'label' => 'IPinfo Lite']])
        ->and(mmdb('ipinfo')->updater()->outdated('ipinfo_lite'))->toBeGreaterThan(0);
});

it('keeps the database in place when the download does not match the checksum', function () {
    mmdb('ipinfo')->settings()->save(['token' => 'tok123']);
    fake_http(ipinfo_routes(ipinfo_release(), str_repeat('b', 64)));

    expect(mmdb('ipinfo')->updater()->update())->toBeFalse()
        ->and(mmdb('ipinfo')->updater()->errors()['ipinfo_lite'])->toContain('checksum IPinfo publishes')
        ->and(gecka_geolocation_available(Facet::COUNTRY, 'ipinfo'))->toBeFalse();
});

it('refuses a file without the records of IPinfo Lite', function () {
    mmdb('ipinfo')->settings()->save(['token' => 'tok123']);
    fake_http(ipinfo_routes(ipinfo_release(probe: false)));

    expect(mmdb('ipinfo')->updater()->update())->toBeFalse()
        ->and(mmdb('ipinfo')->updater()->errors())->toHaveKey('ipinfo_lite');
});

it('stops at a token IPinfo refuses', function (int $status) {
    mmdb('ipinfo')->settings()->save(['token' => 'tok123']);
    fake_http(['https://ipinfo.io/data/ipinfo_lite.mmdb/checksums?token=tok123' => ['status' => $status]]);

    expect(mmdb('ipinfo')->updater()->check())->toBeNull()
        ->and(mmdb('ipinfo')->updater()->errors()[''])->toBe('IPinfo refused the credentials.');
})->with([401, 403]);

it('reports checksums it cannot read', function () {
    mmdb('ipinfo')->settings()->save(['token' => 'tok123']);
    fake_http(['https://ipinfo.io/data/ipinfo_lite.mmdb/checksums?token=tok123' => ['body' => '<html>']]);

    expect(mmdb('ipinfo')->updater()->check())->toBeNull()
        ->and(mmdb('ipinfo')->updater()->errors()[''])->toContain('malformed checksum');
});

it('takes the token from wp-config.php', function () {
    expect(mmdb('ipinfo')->constant('token'))->toBe('GECKA_GEOLOCATION_IPINFO_TOKEN')
        ->and(mmdb('ipinfo')->settings()->clean('token', 'bad token!'))->toBe('');
});

it('gives the credit the licence of IPinfo Lite asks for', function () {
    expect(gecka_geolocation_credit('ipinfo'))->toBe(['text' => 'IP address data powered by IPinfo', 'url' => 'https://ipinfo.io']);
});
