<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Gecka <contact@gecka.nc>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Gecka\WP\Plugin\Geolocation\DbIp;

use Gecka\WP\Plugin\Geolocation\Facet;
use Gecka\WP\Plugin\Geolocation\Mmdb\Archive;
use Gecka\WP\Plugin\Geolocation\Mmdb\Client;
use Gecka\WP\Plugin\Geolocation\Mmdb\Provider as MmdbProvider;

/**
 * The Lite databases of DB-IP, free under the Creative Commons Attribution
 * 4.0 licence, downloaded without an account.
 *
 * DB-IP releases them at the start of each month, under a name carrying
 * the month, as a gzipped MMDB file in the shape of the GeoIP2 databases.
 * The release of the month is looked for first, then the one of the month
 * before while the new one is not out. DB-IP publishes no checksum the
 * plugin can read: the file is checked by its metadata.
 *
 * @author Laurent Dinclaux - Gecka <laurent@gecka.nc>
 */
class Provider extends MmdbProvider
{
    /**
     * Identifier of the provider.
     */
    public const ID = 'dbip';

    /**
     * Page of the Lite databases.
     */
    public const URL = 'https://db-ip.com/db/lite.php';

    /**
     * The Lite databases, by the name DB-IP gives their files, with the
     * type their metadata names.
     */
    public const EDITIONS = [
        'dbip-country-lite' => ['label' => 'DB-IP Country Lite', 'free' => true, 'facets' => [Facet::COUNTRY], 'type' => 'DBIP-Country-Lite'],
        'dbip-city-lite' => ['label' => 'DB-IP City Lite', 'free' => true, 'facets' => [Facet::COUNTRY, Facet::CITY], 'type' => 'DBIP-City-Lite'],
        'dbip-asn-lite' => ['label' => 'DB-IP ASN Lite', 'free' => true, 'facets' => [Facet::ASN], 'type' => 'DBIP-ASN-Lite'],
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
        return 'DB-IP';
    }

    /**
     * @return array<string, array{label: string, facets: string[], free: bool, type: string}>
     */
    public function editions(): array
    {
        return self::EDITIONS;
    }

    /**
     * @return array<string, string[]>
     */
    public function preferences(): array
    {
        return [
            Facet::COUNTRY => ['dbip-city-lite', 'dbip-country-lite'],
            Facet::CITY => ['dbip-city-lite'],
            Facet::ASN => ['dbip-asn-lite'],
        ];
    }

    /**
     * @return string
     */
    public function format(): string
    {
        return Archive::GZ;
    }

    /**
     * Address of the release of a month
     *
     * @param string $edition
     * @param string $release Month, as 2026-10; the current month when empty.
     *
     * @return string
     */
    public function url(string $edition, string $release): string
    {
        return 'https://download.db-ip.com/free/' . rawurlencode($edition) . '-' . ($release !== '' ? $release : gmdate('Y-m')) . '.mmdb.gz';
    }

    /**
     * The release of the current month, or of the month before while the
     * new one is not out
     *
     * @param Client $client
     * @param string $edition
     *
     * @return array{status: int, modified: int, release: string}
     */
    public function release(Client $client, string $edition): array
    {
        $month = gmdate('Y-m');
        $head = $client->head($this->url($edition, $month));

        if ($head['status'] === 404) {
            $month = gmdate('Y-m', (int) strtotime(gmdate('Y-m-01') . ' -1 month'));
            $head = $client->head($this->url($edition, $month));
        }

        return $head + ['release' => $month];
    }

    /**
     * Only too many requests stop the whole check: DB-IP asks no credentials.
     *
     * @param int $status
     *
     * @return bool
     */
    public function fatal(int $status): bool
    {
        return in_array($status, [403, 429], true);
    }

    /**
     * @param int    $status
     * @param string $edition
     *
     * @return string
     */
    /**
     * The City database carries no postal code.
     *
     * @param string $edition
     *
     * @return string
     */
    public function description(string $edition): string
    {
        return $edition === 'dbip-city-lite'
            ? __('Country, region, city and coordinates', 'gecka-geolocation')
            : parent::description($edition);
    }

    public function message(int $status, string $edition): string
    {
        return $this->fatal($status)
            ? __('DB-IP refuses the downloads of this server for now. The download is tried again later.', 'gecka-geolocation')
            : parent::message($status, $edition);
    }

    /**
     * The link the licence of DB-IP asks for, on the pages that show or use
     * its data
     *
     * @return array{text: string, url: string|null}
     */
    public function credit(): array
    {
        return ['text' => 'IP Geolocation by DB-IP', 'url' => 'https://db-ip.com'];
    }

    /**
     * @return string
     */
    public function intro(): string
    {
        return sprintf(
            /* translators: 1: address of the page of the databases, 2: address of the licence */
            __('The <a href="%1$s">Lite databases of DB-IP</a> need no account and come out at the start of each month. Their licence, <a href="%2$s">Creative Commons Attribution 4.0</a>, asks for a link to DB-IP on the pages that show or use their results: the plugins using them get its wording from Gecka Geolocation. Nothing is downloaded until a database is ticked.', 'gecka-geolocation'),
            esc_url(self::URL),
            esc_url('https://creativecommons.org/licenses/by/4.0/'),
        );
    }
}
