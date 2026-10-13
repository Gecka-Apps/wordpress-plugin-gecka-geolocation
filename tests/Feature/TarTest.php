<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Gecka <contact@gecka.nc>
// SPDX-License-Identifier: GPL-3.0-or-later

use Gecka\WP\Plugin\Geolocation\Tar;

beforeEach(function () {
    wp_mkdir_p(GEOLOCATION_DIRECTORY);
});

it('takes the database out of an archive as MaxMind ships it', function () {
    $content = random_bytes(5000);
    file_put_contents(GEOLOCATION_DIRECTORY . '/a.tar.gz', make_tar([
        'GeoLite2-City_20261006/COPYRIGHT.txt' => 'Database and Contents Copyright (c) 2026 MaxMind, Inc.',
        'GeoLite2-City_20261006/LICENSE.txt' => str_repeat('licence ', 300),
        'GeoLite2-City_20261006/GeoLite2-City.mmdb' => $content,
    ]));

    $name = Tar::extract(GEOLOCATION_DIRECTORY . '/a.tar.gz', '.mmdb', GEOLOCATION_DIRECTORY . '/out');

    expect($name)->toBe('GeoLite2-City_20261006/GeoLite2-City.mmdb')
        ->and(file_get_contents(GEOLOCATION_DIRECTORY . '/out'))->toBe($content);
});

it('reads a file whose size is a multiple of the block', function () {
    $content = str_repeat('x', 1024);
    file_put_contents(GEOLOCATION_DIRECTORY . '/a.tar.gz', make_tar(['a.mmdb' => $content, 'b.txt' => 'b']));

    Tar::extract(GEOLOCATION_DIRECTORY . '/a.tar.gz', '.mmdb', GEOLOCATION_DIRECTORY . '/out');

    expect(file_get_contents(GEOLOCATION_DIRECTORY . '/out'))->toBe($content);
});

it('understands GNU long names', function () {
    $name = str_repeat('d', 120) . '/GeoIP2-Enterprise.mmdb';
    file_put_contents(GEOLOCATION_DIRECTORY . '/a.tar.gz', make_tar([$name => 'data'], true));

    expect(Tar::extract(GEOLOCATION_DIRECTORY . '/a.tar.gz', '.mmdb', GEOLOCATION_DIRECTORY . '/out'))->toBe($name)
        ->and(file_get_contents(GEOLOCATION_DIRECTORY . '/out'))->toBe('data');
});

it('fails when the archive holds no database', function () {
    file_put_contents(GEOLOCATION_DIRECTORY . '/a.tar.gz', make_tar(['README.txt' => 'nothing']));

    Tar::extract(GEOLOCATION_DIRECTORY . '/a.tar.gz', '.mmdb', GEOLOCATION_DIRECTORY . '/out');
})->throws(RuntimeException::class, 'holds no .mmdb file');

it('fails on a truncated archive', function () {
    $tar = gzdecode(make_tar(['a.mmdb' => str_repeat('x', 4000)]));
    file_put_contents(GEOLOCATION_DIRECTORY . '/a.tar.gz', gzencode(substr($tar, 0, 2048)));

    Tar::extract(GEOLOCATION_DIRECTORY . '/a.tar.gz', '.mmdb', GEOLOCATION_DIRECTORY . '/out');
})->throws(RuntimeException::class, 'truncated');

it('fails on a file that is no gzip archive', function () {
    file_put_contents(GEOLOCATION_DIRECTORY . '/a.tar.gz', 'plain text, long enough to look like something');

    Tar::extract(GEOLOCATION_DIRECTORY . '/a.tar.gz', '.mmdb', GEOLOCATION_DIRECTORY . '/out');
})->throws(RuntimeException::class);

it('refuses an entry whose size does not fit an integer', function () {
    $header = tar_header('GeoLite2-Country.mmdb', 0, '0');
    $header = substr_replace($header, "\x80" . str_repeat("\xff", 11), 124, 12);
    $checksum = array_sum(array_map('ord', str_split(substr_replace($header, '        ', 148, 8))));
    $header = substr_replace($header, sprintf('%06o', $checksum) . "\0 ", 148, 8);
    $archive = GEOLOCATION_DIRECTORY . '-big.tar.gz';
    file_put_contents($archive, gzencode($header . str_repeat("\0", 1024)));

    expect(fn() => Gecka\WP\Plugin\Geolocation\Tar::extract($archive, '.mmdb', GEOLOCATION_DIRECTORY . '-big.mmdb'))->toThrow(RuntimeException::class, 'too large');

    unlink($archive);
});

it('refuses a long name past what a name may hold', function () {
    $tar = tar_header('././@LongLink', 70000, 'L') . str_repeat('a', 512);

    expect(fn() => Tar::extract(tar_file((string) gzencode($tar)), '.mmdb', GEOLOCATION_DIRECTORY . '/out.mmdb'))
        ->toThrow(RuntimeException::class, 'name too long');
});

it('refuses an entry declared larger than the plugin accepts', function () {
    $tar = tar_header('x.mmdb', 2147483649, '0');

    expect(fn() => Tar::extract(tar_file((string) gzencode($tar)), '.mmdb', GEOLOCATION_DIRECTORY . '/out.mmdb'))
        ->toThrow(RuntimeException::class, 'unpacks to more');
});

it('fails cleanly on an archive of noise', function () {
    $tar = str_repeat("\x41\x7f\xff\x80", 1024);

    expect(fn() => Tar::extract(tar_file((string) gzencode($tar)), '.mmdb', GEOLOCATION_DIRECTORY . '/out.mmdb'))
        ->toThrow(RuntimeException::class, 'malformed');
});

it('skips a link named like a database and takes the regular file', function () {
    $tar = tar_header('evil.mmdb', 0, '2') . tar_header('real.mmdb', 4, '0') . str_pad('data', 512, "\0") . str_repeat("\0", 1024);
    $target = GEOLOCATION_DIRECTORY . '/out.mmdb';

    expect(Tar::extract(tar_file((string) gzencode($tar)), '.mmdb', $target))->toBe('real.mmdb')
        ->and(file_get_contents($target))->toBe('data');
});

/**
 * Writes an archive in the directory of the tests.
 *
 * @param string $content
 *
 * @return string Its path.
 */
function tar_file(string $content): string
{
    wp_mkdir_p(GEOLOCATION_DIRECTORY);
    $path = GEOLOCATION_DIRECTORY . '/archive-' . wp_generate_password(6, false) . '.tar.gz';
    file_put_contents($path, $content);

    return $path;
}
