<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Gecka <contact@gecka.nc>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Gecka\WP\Plugin\Geolocation\IPinfo;

use Gecka\Geolocation\Vendor\MaxMind\Db\Reader;
use Gecka\WP\Plugin\Geolocation\Facet;
use Gecka\WP\Plugin\Geolocation\Mmdb\Archive;
use Gecka\WP\Plugin\Geolocation\Mmdb\Client;
use Gecka\WP\Plugin\Geolocation\Mmdb\Provider as MmdbProvider;
use Gecka\WP\Plugin\Geolocation\Value;

/**
 * IPinfo Lite, free under the Creative Commons Attribution-ShareAlike 4.0
 * licence, downloaded with the token of an IPinfo account.
 *
 * One MMDB file answers the country and the autonomous system of an
 * address, in flat records (country_code, continent, asn, as_name,
 * as_domain) which normalize() puts in the shape of the GeoIP2 databases.
 * IPinfo limits the downloads of a token to a few a day: the release is
 * told by the SHA-256 the checksums address publishes, which also checks
 * the download. The metadata of the file does not name its content
 * reliably: a file is accepted once it answers a known address with those
 * fields.
 *
 * @author Laurent Dinclaux - Gecka <laurent@gecka.nc>
 */
class Provider extends MmdbProvider
{
    /**
     * Identifier of the provider.
     */
    public const ID = 'ipinfo';

    /**
     * The only edition.
     */
    public const EDITION = 'ipinfo_lite';

    /**
     * Page of IPinfo Lite.
     */
    public const URL = 'https://ipinfo.io/lite';

    /**
     * Page of the account where the token is.
     */
    public const TOKEN_URL = 'https://ipinfo.io/dashboard/token';

    /**
     * Address a valid file answers, a public resolver.
     */
    private const PROBE = '8.8.8.8';

    /**
     * @return string
     */
    public function id(): string
    {
        return self::ID;
    }

    /**
     * @return string
     */
    public function label(): string
    {
        return 'IPinfo';
    }

    /**
     * @return array<string, array{label: string, facets: string[], free: bool, type: string}>
     */
    public function editions(): array
    {
        return [self::EDITION => ['label' => 'IPinfo Lite', 'free' => true, 'facets' => [Facet::COUNTRY, Facet::ASN], 'type' => '']];
    }

    /**
     * @return array<string, string[]>
     */
    public function preferences(): array
    {
        return [Facet::COUNTRY => [self::EDITION], Facet::ASN => [self::EDITION]];
    }

    /**
     * The only edition is chosen: the token is what opts in.
     *
     * @return string[]
     */
    public function defaults(): array
    {
        return [self::EDITION];
    }

    /**
     * @return array<string, array{label: string, pattern: string, secret: bool, invalid: string}>
     */
    public function fields(): array
    {
        return [
            'token' => [
                'label' => __('Access token', 'gecka-geolocation'),
                'pattern' => '/^[A-Za-z0-9_-]{1,64}$/',
                'secret' => true,
                'invalid' => __('An access token holds letters, digits, dashes and underscores only. It was not saved.', 'gecka-geolocation'),
            ],
        ];
    }

    /**
     * @return string
     */
    public function format(): string
    {
        return Archive::MMDB;
    }

    /**
     * Address of the file, the token in its query as IPinfo asks
     *
     * @param string $edition
     * @param string $release Unused: the address serves the latest release.
     *
     * @return string
     */
    public function url(string $edition, string $release = ''): string
    {
        return $this->address($edition, '');
    }

    /**
     * An address of the data of an edition, with the token
     *
     * @param string $edition
     * @param string $path    What follows the file: empty for the file itself, /checksums for its checksums.
     *
     * @return string
     */
    private function address(string $edition, string $path): string
    {
        return 'https://ipinfo.io/data/' . rawurlencode($edition) . '.mmdb' . $path . '?token=' . rawurlencode($this->settings()->value('token'));
    }

    /**
     * The latest release, named by its SHA-256, from the checksums address,
     * which counts in no download limit
     *
     * @param Client $client
     * @param string $edition
     *
     * @return array{status: int, modified: int, release: string}
     *
     * @throws \RuntimeException When the request fails, or the answer holds no checksum.
     */
    public function release(Client $client, string $edition): array
    {
        $answer = $client->fetch($this->address($edition, '/checksums'));

        if ($answer['status'] !== 200) {
            return ['status' => $answer['status'], 'modified' => 0, 'release' => ''];
        }

        $json = Value::list(json_decode($answer['body'], true));

        return ['status' => 200, 'modified' => 0, 'release' => $this->sha256(Value::string(Value::list($json['checksums'] ?? null)['sha256'] ?? $json['sha256'] ?? null))];
    }

    /**
     * The SHA-256 the release was named by
     *
     * @param Client $client
     * @param string $edition
     * @param string $release
     *
     * @return string
     *
     * @throws \RuntimeException When no release was checked first.
     */
    public function checksum(Client $client, string $edition, string $release): string
    {
        if ($release === '') {
            throw new \RuntimeException(esc_html__('IPinfo gave no checksum to check the download against.', 'gecka-geolocation'));
        }

        return $release;
    }

    /**
     * Whether the file answers a known address with the fields of IPinfo
     * Lite
     *
     * @param Reader $reader
     * @param string $edition
     *
     * @return bool
     */
    public function accepts(Reader $reader, string $edition): bool
    {
        $record = $reader->get(self::PROBE);

        return is_array($record) && isset($record['country_code'], $record['asn']);
    }

    /**
     * A flat record of IPinfo Lite in the shape of the GeoIP2 databases
     *
     * @param array<string, mixed> $data
     * @param string               $edition
     *
     * @return array<string, mixed>
     */
    public function normalize(array $data, string $edition): array
    {
        $text = static fn(string $key): ?string => Value::string($data[$key] ?? null) !== '' ? Value::string($data[$key]) : null;
        $place = static fn(?string $code, ?string $name): ?array => $code !== null ? array_filter(['iso_code' => $code, 'names' => $name !== null ? ['en' => $name] : null]) : null;
        $asn = $text('asn');

        return array_filter(
            [
                'country' => $place($text('country_code'), $text('country')),
                'continent' => ($continent = $text('continent_code')) !== null ? array_filter(['code' => $continent, 'names' => $text('continent') !== null ? ['en' => $text('continent')] : null]) : null,
                'autonomous_system_number' => $asn !== null && preg_match('/^(?:AS)?(\d+)$/i', $asn, $match) ? (int) $match[1] : null,
                'autonomous_system_organization' => $text('as_name'),
                'as_domain' => $text('as_domain'),
            ],
            static fn(mixed $value): bool => $value !== null,
        );
    }

    /**
     * The credit the licence of IPinfo Lite asks for
     *
     * @return array{text: string, url: string|null}
     */
    public function credit(): array
    {
        return ['text' => 'IP address data powered by IPinfo', 'url' => 'https://ipinfo.io'];
    }

    /**
     * @return string
     */
    public function intro(): string
    {
        return sprintf(
            /* translators: 1: address of the page of IPinfo Lite, 2: address of the page of the token, 3: address of the licence */
            __('<a href="%1$s">IPinfo Lite</a> gives the country and the autonomous system of an address, updated every day. It downloads with the access token of a free IPinfo account, shown on its <a href="%2$s">token page</a>. Its licence, <a href="%3$s">Creative Commons Attribution-ShareAlike 4.0</a>, asks for a link to IPinfo where its data is used: the plugins using it get its wording from Gecka Geolocation.', 'gecka-geolocation'),
            esc_url(self::URL),
            esc_url(self::TOKEN_URL),
            esc_url('https://creativecommons.org/licenses/by-sa/4.0/'),
        );
    }
}
