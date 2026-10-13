<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Gecka <contact@gecka.nc>
// SPDX-License-Identifier: GPL-3.0-or-later

use Gecka\WP\Plugin\Geolocation\Facet;

/**
 * A DB-IP Country Lite database, gzipped as DB-IP serves it
 *
 * @param string $type database_type of the metadata.
 *
 * @return string
 */
function dbip_release(string $type = 'DBIP-Country-Lite'): string
{
    $path = make_mmdb(GEOLOCATION_DIRECTORY . '-dbip.mmdb', $type, [
        '203.147.64.0/19' => ['country' => ['iso_code' => 'NC', 'names' => ['en' => 'New Caledonia', 'fr' => 'Nouvelle-Calédonie']], 'continent' => ['code' => 'OC', 'names' => ['en' => 'Oceania']]],
    ], ['en', 'fr']);
    $gzipped = (string) gzencode((string) file_get_contents($path));

    unlink($path);

    return $gzipped;
}

/**
 * Address of the release of a month
 *
 * @param string $month
 *
 * @return string
 */
function dbip_url(string $month): string
{
    return 'https://download.db-ip.com/free/dbip-country-lite-' . $month . '.mmdb.gz';
}

it('downloads nothing until a database is ticked', function () {
    expect(mmdb('dbip')->settings()->wanted())->toBe([])
        ->and(mmdb('dbip')->settings()->configured())->toBeFalse()
        ->and(mmdb('dbip')->updater()->due())->toBeFalse();
});

it('downloads the release of the month, gzipped, without credentials', function () {
    mmdb('dbip')->settings()->save(['editions' => ['dbip-country-lite']]);
    $month = gmdate('Y-m');
    $sent = fake_http([dbip_url($month) => ['body' => dbip_release(), 'modified' => 'Thu, 01 Oct 2026 06:29:26 GMT']]);

    expect(mmdb('dbip')->updater()->update())->toBeTrue()
        ->and(gecka_geolocation_country('203.147.65.1', 'dbip'))->toBe('NC')
        ->and(gecka_geolocation('203.147.65.1', 'dbip')->source())->toBe('dbip-country-lite')
        ->and(mmdb('dbip')->updater()->state()['editions']['dbip-country-lite']['installed_release'])->toBe($month)
        ->and(array_unique(array_column((array) $sent, 'authorization')))->toBe([null])
        ->and(glob(GEOLOCATION_DIRECTORY . '/dbip/*.part'))->toBe([]);

    // The release in place is the one of the month: nothing more to download.
    $sent = fake_http([dbip_url($month) => ['modified' => 'Thu, 01 Oct 2026 06:29:26 GMT']]);

    expect(mmdb('dbip')->updater()->check())->toBe([])
        ->and(array_column((array) $sent, 'method'))->toBe(['HEAD']);
});

it('takes the release of the month before while the new one is not out', function () {
    mmdb('dbip')->settings()->save(['editions' => ['dbip-country-lite']]);
    $previous = gmdate('Y-m', (int) strtotime(gmdate('Y-m-01') . ' -1 month'));
    fake_http([dbip_url($previous) => ['body' => dbip_release()]]);

    expect(mmdb('dbip')->updater()->update())->toBeTrue()
        ->and(mmdb('dbip')->updater()->state()['editions']['dbip-country-lite']['release'])->toBe($previous)
        ->and(gecka_geolocation_country('203.147.65.1', 'dbip'))->toBe('NC');
});

it('refuses a file holding another database', function () {
    mmdb('dbip')->settings()->save(['editions' => ['dbip-country-lite']]);
    fake_http([dbip_url(gmdate('Y-m')) => ['body' => dbip_release('DBIP-City-Lite')]]);

    expect(mmdb('dbip')->updater()->update())->toBeFalse()
        ->and(mmdb('dbip')->updater()->errors()['dbip-country-lite'])->toContain('DBIP-City-Lite')
        ->and(gecka_geolocation_available(Facet::COUNTRY, 'dbip'))->toBeFalse();
});

it('gives the credit the licence of DB-IP asks for', function () {
    expect(gecka_geolocation_credit('dbip'))->toBe(['text' => 'IP Geolocation by DB-IP', 'url' => 'https://db-ip.com']);
});

it('names a country in the language of the site', function () {
    install_fixture('dbip-country-lite', make_mmdb(GEOLOCATION_DIRECTORY . '-dbip.mmdb', 'DBIP-Country-Lite', [
        '203.147.64.0/19' => ['country' => ['iso_code' => 'NC', 'names' => ['en' => 'New Caledonia', 'fr' => 'Nouvelle-Calédonie']]],
    ], ['en', 'fr']), 'dbip');
    unlink(GEOLOCATION_DIRECTORY . '-dbip.mmdb');

    expect(gecka_geolocation('203.147.65.1', 'dbip')->countryName('fr_FR'))->toBe('Nouvelle-Calédonie')
        ->and(gecka_geolocation('203.147.65.1', 'dbip')->credit()['text'])->toBe('IP Geolocation by DB-IP');
});
