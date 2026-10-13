<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Gecka <contact@gecka.nc>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Gecka\WP\Plugin\Geolocation\Mmdb;

use Gecka\Geolocation\Vendor\MaxMind\Db\Reader;
use Gecka\WP\Plugin\Geolocation\Facet;
use Gecka\WP\Plugin\Geolocation\Provider as ProviderContract;
use Gecka\WP\Plugin\Geolocation\Value;

/**
 * A provider publishing its data as MMDB files, which the plugin downloads
 * to the server and keeps up to date: its settings, its databases and its
 * updates.
 *
 * A subclass describes the provider: its editions, the credentials it asks
 * for, where and in what form it serves a release, how to check one, and
 * what its records look like when they differ from those of MaxMind.
 *
 * @author Laurent Dinclaux - Gecka <laurent@gecka.nc>
 */
abstract class Provider implements ProviderContract
{
    /**
     * @var Settings
     */
    private Settings $settings;

    /**
     * @var Databases
     */
    private Databases $databases;

    /**
     * @var Updater
     */
    private Updater $updater;

    /**
     * Builds the settings, the databases and the updater of the provider.
     */
    public function __construct()
    {
        $this->settings = new Settings($this);
        $this->databases = new Databases($this);
        $this->updater = new Updater($this);
    }

    /**
     * Hooks the updates up, and forgets the settings read once they change.
     *
     * @return void
     */
    public function setup(): void
    {
        $this->updater->setup();

        foreach (['add_site_option_', 'update_site_option_', 'delete_site_option_'] as $hook) {
            add_action($hook . $this->settings->option(), [$this->settings, 'flush']);
        }
    }

    /**
     * The databases of the provider, by edition ID: their name, the facets
     * they answer, whether they come free, and the beginning of the type
     * their metadata names
     *
     * @return array<string, array{label: string, facets: string[], free: bool, type: string}>
     */
    abstract public function editions(): array;

    /**
     * Editions asked for a facet, the first one installed answering
     *
     * @return array<string, string[]> By facet.
     */
    abstract public function preferences(): array;

    /**
     * Form of the file a release comes in, one of the constants of Archive
     *
     * @return string
     */
    abstract public function format(): string;

    /**
     * Address a release of an edition is downloaded from
     *
     * @param string $edition
     * @param string $release Identifier of the release, as release() gives it.
     *
     * @return string
     */
    abstract public function url(string $edition, string $release): string;

    /**
     * Paragraph introducing the provider on its tab, as HTML
     *
     * @return string
     */
    abstract public function intro(): string;

    /**
     * Editions chosen on a new installation
     *
     * @return string[]
     */
    public function defaults(): array
    {
        return [];
    }

    /**
     * The credentials the provider asks for, by field: their label, the
     * pattern a value matches, whether it is kept from sight, and what to
     * say of a value that does not match
     *
     * @return array<string, array{label: string, pattern: string, secret: bool, invalid: string}>
     */
    public function fields(): array
    {
        return [];
    }

    /**
     * Headers of a request to an address, the credentials going only to the
     * host of the provider
     *
     * @param string $url
     *
     * @return array<string, string>
     */
    public function headers(string $url): array
    {
        return [];
    }

    /**
     * The latest release of an edition: the status of its address, its date
     * when the provider tells it, and an identifier when the provider names
     * its releases
     *
     * @param Client $client
     * @param string $edition
     *
     * @return array{status: int, modified: int, release: string}
     *
     * @throws \RuntimeException When the request fails.
     */
    public function release(Client $client, string $edition): array
    {
        return $client->head($this->url($edition, '')) + ['release' => ''];
    }

    /**
     * SHA-256 of the file of a release, as the provider publishes it
     *
     * @param Client $client
     * @param string $edition
     * @param string $release
     *
     * @return string|null 64 hexadecimal digits; null when the provider publishes none.
     *
     * @throws \RuntimeException When it cannot be downloaded or read.
     */
    public function checksum(Client $client, string $edition, string $release): ?string
    {
        return null;
    }

    /**
     * Whether a database file holds the edition it was downloaded as
     *
     * @param Reader $reader
     * @param string $edition
     *
     * @return bool
     */
    public function accepts(Reader $reader, string $edition): bool
    {
        $type = $this->editions()[$edition]['type'] ?? '';

        return $type !== '' && str_starts_with(Value::string($reader->metadata()->databaseType), $type);
    }

    /**
     * The SHA-256 a text of the provider starts with, lower-cased
     *
     * @param string $text
     *
     * @return string
     *
     * @throws \RuntimeException When the text starts with no SHA-256.
     */
    protected function sha256(string $text): string
    {
        if (! preg_match('/^([0-9a-f]{64})\b/i', trim($text), $match)) {
            /* translators: %s: name of a provider */
            throw new \RuntimeException(esc_html(sprintf(__('%s sent a malformed checksum.', 'gecka-geolocation'), $this->label())));
        }

        return strtolower($match[1]);
    }

    /**
     * A record in the shape of the GeoIP2 databases
     *
     * @param array<string, mixed> $data
     * @param string               $edition
     *
     * @return array<string, mixed>
     */
    public function normalize(array $data, string $edition): array
    {
        return $data;
    }

    /**
     * Whether a status stops the whole check, as refused credentials,
     * rather than concern one edition
     *
     * @param int $status
     *
     * @return bool
     */
    public function fatal(int $status): bool
    {
        return in_array($status, [401, 403, 429], true);
    }

    /**
     * What a status of the download service means
     *
     * @param int    $status
     * @param string $edition
     *
     * @return string
     */
    public function message(int $status, string $edition): string
    {
        return match ($status) {
            /* translators: %s: name of a provider */
            401, 403 => sprintf(__('%s refused the credentials.', 'gecka-geolocation'), $this->label()),
            /* translators: 1: name of a provider, 2: name of a database */
            404 => sprintf(__('%1$s does not offer %2$s.', 'gecka-geolocation'), $this->label(), $this->editionLabel($edition)),
            /* translators: %s: name of a provider */
            429 => sprintf(__('%s limits the downloads for now. The download is tried again later.', 'gecka-geolocation'), $this->label()),
            /* translators: 1: name of a provider, 2: HTTP status code */
            default => sprintf(__('%1$s answered with HTTP status %2$d.', 'gecka-geolocation'), $this->label(), $status),
        };
    }

    /**
     * Days the licence of the databases allows an old release to be used
     * once a new one is out
     *
     * @return int|null Null when it sets no limit.
     */
    public function licenseDays(): ?int
    {
        return null;
    }

    /**
     * Name of the constant of wp-config.php holding a credential
     *
     * @param string $field
     *
     * @return string As GECKA_GEOLOCATION_MAXMIND_LICENSE_KEY.
     */
    public function constant(string $field): string
    {
        return strtoupper('gecka_geolocation_' . $this->id() . '_' . $field);
    }

    /**
     * Whether an edition ID is one of the provider
     *
     * @param string $edition
     *
     * @return bool
     */
    public function known(string $edition): bool
    {
        return isset($this->editions()[$edition]);
    }

    /**
     * Name of an edition, the way the provider publishes it
     *
     * @param string $edition
     *
     * @return string
     */
    public function editionLabel(string $edition): string
    {
        return $this->editions()[$edition]['label'] ?? $edition;
    }

    /**
     * Whether an edition comes free
     *
     * @param string $edition
     *
     * @return bool
     */
    public function free(string $edition): bool
    {
        return $this->editions()[$edition]['free'] ?? false;
    }

    /**
     * The facets an edition answers
     *
     * @param string $edition
     *
     * @return string[]
     */
    public function facets(string $edition): array
    {
        return $this->editions()[$edition]['facets'] ?? [];
    }

    /**
     * What an edition tells about an address, in a few words
     *
     * @param string $edition
     *
     * @return string
     */
    public function description(string $edition): string
    {
        $facets = $this->facets($edition);
        $has = static fn(string $facet): bool => in_array($facet, $facets, true);

        return match (true) {
            $has(Facet::DOMAIN) && $has(Facet::CITY) => __('City, network, ISP, connection type and domain', 'gecka-geolocation'),
            $has(Facet::CITY) => __('Country, region, city, postal code and coordinates', 'gecka-geolocation'),
            $has(Facet::COUNTRY) && $has(Facet::ASN) => __('Country, continent and autonomous system', 'gecka-geolocation'),
            $has(Facet::COUNTRY) => __('Country and continent', 'gecka-geolocation'),
            $has(Facet::ISP) => __('Autonomous system, ISP and organisation', 'gecka-geolocation'),
            $has(Facet::ASN) => __('Autonomous system and its organisation', 'gecka-geolocation'),
            $has(Facet::CONNECTION) => __('Connection type: cable/DSL, cellular, corporate, satellite', 'gecka-geolocation'),
            $has(Facet::DOMAIN) => __('Domain of the address', 'gecka-geolocation'),
            $has(Facet::ANONYMOUS) => __('VPN, hosting provider, proxy and Tor exit node', 'gecka-geolocation'),
            default => '',
        };
    }

    /**
     * Whether a database installed answers a facet
     *
     * @param string $facet
     *
     * @return bool
     */
    public function available(string $facet): bool
    {
        return $this->databases->edition($facet) !== null;
    }

    /**
     * What the database answering a facet holds about an address
     *
     * @param string $facet
     * @param string $ip
     *
     * @return array{data: array<string, mixed>, network: string|null, source: string}|null
     */
    public function lookup(string $facet, string $ip): ?array
    {
        return $this->databases->lookup($facet, $ip);
    }

    /**
     * @return Settings
     */
    public function settings(): Settings
    {
        return $this->settings;
    }

    /**
     * @return Databases
     */
    public function databases(): Databases
    {
        return $this->databases;
    }

    /**
     * @return Updater
     */
    public function updater(): Updater
    {
        return $this->updater;
    }
}
