<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Gecka <contact@gecka.nc>
// SPDX-License-Identifier: GPL-3.0-or-later

use Gecka\WP\Plugin\Geolocation\Countries;

it('names a country from its code', function () {
    expect(gecka_geolocation_country_name('NC', 'fr_FR'))->toBe('Nouvelle-Calédonie')
        ->and(gecka_geolocation_country_name('nc', 'en_US'))->toBe('New Caledonia')
        ->and(gecka_geolocation_country_name('EU', 'fr_FR'))->toBe('Union européenne')
        ->and(gecka_geolocation_country_name('ZZ', 'fr_FR'))->toBe('ZZ')
        ->and(gecka_geolocation_country_name('XA', 'fr_FR'))->toBe('XA')
        ->and(gecka_geolocation_country_name('FRA'))->toBeNull()
        ->and(gecka_geolocation_country_name(''))->toBeNull();
});

it('takes the name from its list for a code intl does not know', function () {
    expect(gecka_geolocation_country_name('PC'))->toBe('Pacific Community');
});

it('names from its list every country it has a flag for', function () {
    $codes = array_map(static fn(string $file): string => strtoupper(basename($file, '.svg')), (array) glob(dirname(__DIR__, 2) . '/assets/flags/4x3/*.svg'));

    expect($codes)->toHaveCount(256);

    foreach ($codes as $code) {
        expect(Countries::fromList($code))->toBeString()->not->toBe($code);
    }

    expect(Countries::fromList('NC'))->toBe('New Caledonia')
        ->and(Countries::fromList('ZZ'))->toBeNull();
});

it('lists the same codes as its names and its flags', function () {
    $names = (new ReflectionMethod(Countries::class, 'names'))->invoke(null);

    expect(array_keys($names))->toBe(Countries::CODES)
        ->and(Countries::CODES)->toHaveCount(256)
        ->and(Countries::CODES)->toContain('PC');
});
