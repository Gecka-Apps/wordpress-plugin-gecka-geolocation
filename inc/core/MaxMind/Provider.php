<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Gecka <contact@gecka.nc>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Gecka\WP\Plugin\Geolocation\MaxMind;

use Gecka\WP\Plugin\Geolocation\Facet;
use Gecka\WP\Plugin\Geolocation\Mmdb\Archive;
use Gecka\WP\Plugin\Geolocation\Mmdb\Client;
use Gecka\WP\Plugin\Geolocation\Mmdb\Provider as MmdbProvider;
use Gecka\WP\Plugin\Geolocation\Value;

/**
 * The databases of MaxMind, GeoLite or GeoIP, downloaded with the account of
 * the site.
 *
 * A database is downloaded from its permalink, authenticated by the account
 * ID and the licence key in HTTP Basic, as a .tar.gz archive with a SHA-256
 * checksum next to it. The permalink redirects to a storage service, which
 * gets no credentials. A HEAD request on the permalink tells the date of
 * the latest release without counting in the daily download limit of the
 * account. The licence of the GeoLite databases asks that an old release be
 * deleted within 30 days of a new one.
 *
 * @author Laurent Dinclaux - Gecka <laurent@gecka.nc>
 */
class Provider extends MmdbProvider
{
    /**
     * Identifier of the provider.
     */
    public const ID = 'maxmind';

    /**
     * Host of the download service, the only one the credentials go to.
     */
    public const HOST = 'download.maxmind.com';

    /**
     * Page of the free GeoLite databases, where a GeoLite account is opened.
     */
    public const SIGN_UP_URL = 'https://www.maxmind.com/en/geolite-free-ip-geolocation-data';

    /**
     * Page where MaxMind hands out licence keys.
     */
    public const LICENSE_KEYS_URL = 'https://www.maxmind.com/en/accounts/current/license-key';

    /**
     * Edition IDs of the databases, as MaxMind names them in its download
     * addresses and in their metadata, with the facets they answer and
     * whether a free GeoLite account downloads them.
     */
    public const EDITIONS = [
        'GeoLite2-Country' => ['label' => 'GeoLite Country', 'free' => true, 'facets' => [Facet::COUNTRY]],
        'GeoLite2-City' => ['label' => 'GeoLite City', 'free' => true, 'facets' => [Facet::COUNTRY, Facet::CITY]],
        'GeoLite2-ASN' => ['label' => 'GeoLite ASN', 'free' => true, 'facets' => [Facet::ASN]],
        'GeoIP2-Country' => ['label' => 'GeoIP Country', 'free' => false, 'facets' => [Facet::COUNTRY]],
        'GeoIP2-City' => ['label' => 'GeoIP City', 'free' => false, 'facets' => [Facet::COUNTRY, Facet::CITY]],
        'GeoIP2-Enterprise' => ['label' => 'GeoIP Enterprise', 'free' => false, 'facets' => [Facet::COUNTRY, Facet::CITY, Facet::ASN, Facet::ISP, Facet::CONNECTION, Facet::DOMAIN]],
        'GeoIP2-ISP' => ['label' => 'GeoIP ISP', 'free' => false, 'facets' => [Facet::ASN, Facet::ISP]],
        'GeoIP2-Connection-Type' => ['label' => 'GeoIP Connection Type', 'free' => false, 'facets' => [Facet::CONNECTION]],
        'GeoIP2-Domain' => ['label' => 'GeoIP Domain', 'free' => false, 'facets' => [Facet::DOMAIN]],
        'GeoIP2-Anonymous-IP' => ['label' => 'GeoIP Anonymous IP', 'free' => false, 'facets' => [Facet::ANONYMOUS]],
        'GeoIP-Anonymous-Plus' => ['label' => 'GeoIP Anonymous Plus', 'free' => false, 'facets' => [Facet::ANONYMOUS]],
    ];

    /**
     * Databases asked for a facet, the first one installed answering: a
     * paid database before a free one, the most detailed before the others.
     */
    public const PREFERENCES = [
        Facet::COUNTRY => ['GeoIP2-Enterprise', 'GeoIP2-City', 'GeoIP2-Country', 'GeoLite2-City', 'GeoLite2-Country'],
        Facet::CITY => ['GeoIP2-Enterprise', 'GeoIP2-City', 'GeoLite2-City'],
        Facet::ASN => ['GeoIP2-Enterprise', 'GeoIP2-ISP', 'GeoLite2-ASN'],
        Facet::ISP => ['GeoIP2-Enterprise', 'GeoIP2-ISP'],
        Facet::CONNECTION => ['GeoIP2-Enterprise', 'GeoIP2-Connection-Type'],
        Facet::DOMAIN => ['GeoIP2-Enterprise', 'GeoIP2-Domain'],
        Facet::ANONYMOUS => ['GeoIP-Anonymous-Plus', 'GeoIP2-Anonymous-IP'],
    ];

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
        return 'MaxMind';
    }

    /**
     * The type of a MaxMind database is its edition ID.
     *
     * @return array<string, array{label: string, facets: string[], free: bool, type: string}>
     */
    public function editions(): array
    {
        $editions = [];

        foreach (self::EDITIONS as $edition => $description) {
            $editions[$edition] = $description + ['type' => $edition];
        }

        return $editions;
    }

    /**
     * @return array<string, string[]>
     */
    public function preferences(): array
    {
        return self::PREFERENCES;
    }

    /**
     * @return string[]
     */
    public function defaults(): array
    {
        return ['GeoLite2-Country'];
    }

    /**
     * @return array<string, array{label: string, pattern: string, secret: bool, invalid: string}>
     */
    public function fields(): array
    {
        return [
            'account_id' => [
                'label' => __('Account ID', 'gecka-geolocation'),
                'pattern' => '/^\d{1,12}$/',
                'secret' => false,
                'invalid' => __('The account ID is a number. It was not saved.', 'gecka-geolocation'),
            ],
            'license_key' => [
                'label' => __('Licence key', 'gecka-geolocation'),
                'pattern' => '/^[A-Za-z0-9_]{1,64}$/',
                'secret' => true,
                'invalid' => __('A licence key holds letters, digits and underscores only. It was not saved.', 'gecka-geolocation'),
            ],
        ];
    }

    /**
     * @return string
     */
    public function format(): string
    {
        return Archive::TAR_GZ;
    }

    /**
     * Permalink of a database
     *
     * @param string $edition
     * @param string $release Unused: the permalink serves the latest release.
     *
     * @return string
     */
    public function url(string $edition, string $release = ''): string
    {
        return self::permalink($edition, 'tar.gz');
    }

    /**
     * HTTP Basic credentials, for the download service only
     *
     * @param string $url
     *
     * @return array<string, string>
     */
    public function headers(string $url): array
    {
        $parts = wp_parse_url($url);

        if (! is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || strtolower(Value::string($parts['host'] ?? null)) !== self::HOST) {
            return [];
        }

        $credentials = $this->settings()->value('account_id') . ':' . $this->settings()->value('license_key');

        return ['Authorization' => 'Basic ' . base64_encode($credentials)]; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- HTTP Basic authentication.
    }

    /**
     * SHA-256 MaxMind publishes next to the archive
     *
     * @param Client $client
     * @param string $edition
     * @param string $release
     *
     * @return string
     *
     * @throws \RuntimeException When it cannot be downloaded or read.
     */
    public function checksum(Client $client, string $edition, string $release): string
    {
        return $this->sha256($client->get(self::permalink($edition, 'tar.gz.sha256'), $edition));
    }

    /**
     * A 403 tells an edition the account lacks, not refused credentials.
     *
     * @param int $status
     *
     * @return bool
     */
    public function fatal(int $status): bool
    {
        return in_array($status, [401, 429], true);
    }

    /**
     * @param int    $status
     * @param string $edition
     *
     * @return string
     */
    public function message(int $status, string $edition): string
    {
        return match ($status) {
            401 => __('MaxMind refused the account ID or the licence key. A key just created can take up to a quarter of an hour to be activated by MaxMind.', 'gecka-geolocation'),
            /* translators: %s: name of a database */
            403 => sprintf(__('The MaxMind account has no access to %s.', 'gecka-geolocation'), $this->editionLabel($edition)),
            429 => __('The MaxMind account reached its daily download limit. The download is tried again later.', 'gecka-geolocation'),
            default => parent::message($status, $edition),
        };
    }

    /**
     * @return int
     */
    public function licenseDays(): int
    {
        return 30;
    }

    /**
     * A record of a MaxMind database with its booleans written out: MaxMind
     * leaves a false boolean out of its records, is_in_european_union
     * among them, which a record of another provider carries either way.
     *
     * @param array<string, mixed> $data
     * @param string               $edition
     *
     * @return array<string, mixed>
     */
    public function normalize(array $data, string $edition): array
    {
        foreach (['country', 'registered_country', 'represented_country'] as $key) {
            if (isset($data[$key]) && is_array($data[$key]) && isset($data[$key]['iso_code'])) {
                $data[$key] += ['is_in_european_union' => false];
            }
        }

        return $data;
    }

    /**
     * The credit the licence of the GeoLite databases asks for, while one is
     * installed; the GeoIP databases ask for none
     *
     * @return array{text: string, url: string|null}|null
     */
    public function credit(): ?array
    {
        if (! array_filter($this->databases()->installed(), [$this, 'free'])) {
            return null;
        }

        return ['text' => 'This product includes GeoLite Data created by MaxMind, available from https://www.maxmind.com.', 'url' => 'https://www.maxmind.com'];
    }

    /**
     * @return string
     */
    public function intro(): string
    {
        return sprintf(
            /* translators: 1: address of the sign-up page, 2: address of the licence keys page */
            __('The databases come from MaxMind, with the account ID and a licence key of your account. A <a href="%1$s">free GeoLite account</a> downloads GeoLite Country, City and ASN; a paid subscription the GeoIP databases it covers. Licence keys are made on the <a href="%2$s">licence keys page</a> of the account.', 'gecka-geolocation'),
            esc_url(self::SIGN_UP_URL),
            esc_url(self::LICENSE_KEYS_URL),
        );
    }

    /**
     * Permalink of a file of a database
     *
     * @param string $edition
     * @param string $suffix  tar.gz, or tar.gz.sha256 for its checksum.
     *
     * @return string
     */
    public static function permalink(string $edition, string $suffix): string
    {
        return 'https://' . self::HOST . '/geoip/databases/' . rawurlencode($edition) . '/download?suffix=' . rawurlencode($suffix);
    }
}
