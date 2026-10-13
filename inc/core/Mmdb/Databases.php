<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Gecka <contact@gecka.nc>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Gecka\WP\Plugin\Geolocation\Mmdb;

use Gecka\Geolocation\Vendor\MaxMind\Db\Reader;
use Gecka\WP\Plugin\Geolocation\Plugin;
use Gecka\WP\Plugin\Geolocation\Storage;
use Gecka\WP\Plugin\Geolocation\Value;

/**
 * The databases of a provider installed, opened on first use, and the
 * lookups in them, in the shape of the GeoIP2 databases.
 *
 * A reader stays open for the request and its lookups are kept, so that
 * several plugins asking about the same address read the file once.
 *
 * @author Laurent Dinclaux - Gecka <laurent@gecka.nc>
 */
class Databases
{
    /**
     * Readers opened, by edition; false for a file that could not be read.
     *
     * @var array<string, Reader|false>
     */
    private array $readers = [];

    /**
     * Lookups made, by edition and address.
     *
     * @var array<string, array<string, array{data: array<string, mixed>|null, prefix: int}>>
     */
    private array $lookups = [];

    /**
     * Errors met opening a file, by edition.
     *
     * @var array<string, string>
     */
    private array $errors = [];

    /**
     * @var Provider
     */
    private Provider $provider;

    /**
     * @param Provider $provider
     */
    public function __construct(Provider $provider)
    {
        $this->provider = $provider;
    }

    /**
     * Path of the file of an edition, in the folder of the provider
     *
     * @param string $edition
     *
     * @return string
     */
    public function path(string $edition): string
    {
        return Storage::directory($this->provider->id()) . '/' . $edition . '.mmdb';
    }

    /**
     * Editions whose file is in the directory, in the order of the provider
     *
     * @return string[]
     */
    public function installed(): array
    {
        return array_values(array_filter(array_keys($this->provider->editions()), fn(string $edition): bool => is_file($this->path($edition))));
    }

    /**
     * The first edition installed answering a facet
     *
     * @param string $facet One of the constants of Facet.
     *
     * @return string|null
     */
    public function edition(string $facet): ?string
    {
        foreach ($this->provider->preferences()[$facet] ?? [] as $edition) {
            if ($this->reader($edition)) {
                return $edition;
            }
        }

        return null;
    }

    /**
     * What the database answering a facet holds about an address
     *
     * @param string $facet
     * @param string $ip    A valid IP address.
     *
     * @return array{data: array<string, mixed>, network: string, source: string}|null The record, its network and the edition that answered; null when no database answers the facet, or it knows nothing of the address.
     */
    public function lookup(string $facet, string $ip): ?array
    {
        $edition = $this->edition($facet);

        if ($edition === null) {
            return null;
        }

        if (! isset($this->lookups[$edition][$ip])) {
            $reader = $this->reader($edition);
            $data = null;
            $prefix = 0;

            try {
                [$found, $prefix] = $reader ? $reader->getWithPrefixLen($ip) : [null, 0];
                /** @var array<string, mixed>|null $found The reader keys a record by its field names. */
                $data = is_array($found) ? $this->provider->normalize($found, $edition) : null;
            } catch (\Exception $e) {
                $this->errors[$edition] = $e->getMessage();
            }

            if (count($this->lookups[$edition] ?? []) >= Plugin::CACHE) {
                $this->lookups[$edition] = [];
            }

            $this->lookups[$edition][$ip] = ['data' => $data, 'prefix' => (int) $prefix];
        }

        $lookup = $this->lookups[$edition][$ip];

        if ($lookup['data'] === null) {
            return null;
        }

        return ['data' => $lookup['data'], 'network' => self::network($ip, $lookup['prefix']), 'source' => $edition];
    }

    /**
     * What the metadata of a database tells
     *
     * @param string $edition
     *
     * @return array{type: string, built: int, ip_version: int, nodes: int, languages: string[]}|null Null when the file is missing or unreadable.
     */
    public function metadata(string $edition): ?array
    {
        $reader = $this->reader($edition);

        if (! $reader) {
            return null;
        }

        $metadata = $reader->metadata();

        return [
            'type' => Value::string($metadata->databaseType),
            'built' => Value::int($metadata->buildEpoch),
            'ip_version' => Value::int($metadata->ipVersion),
            'nodes' => Value::int($metadata->nodeCount),
            'languages' => Value::strings($metadata->languages),
        ];
    }

    /**
     * Why a database could not be opened or read, if it could not
     *
     * @param string $edition
     *
     * @return string|null
     */
    public function error(string $edition): ?string
    {
        return $this->errors[$edition] ?? null;
    }

    /**
     * Closes the reader of an edition and forgets its lookups, before its
     * file is replaced or deleted.
     *
     * @param string $edition
     *
     * @return void
     */
    public function forget(string $edition): void
    {
        $reader = $this->readers[$edition] ?? false;

        if ($reader) {
            $reader->close();
        }

        unset($this->readers[$edition], $this->lookups[$edition], $this->errors[$edition]);
    }

    /**
     * Closes every reader and forgets every lookup.
     *
     * @return void
     */
    public function flush(): void
    {
        foreach (array_keys($this->readers) as $edition) {
            $this->forget($edition);
        }
    }

    /**
     * Opens a database file and checks it is the edition it is named after.
     *
     * @param string $path
     * @param string $edition
     *
     * @return array{type: string, built: int}
     *
     * @throws \RuntimeException When the file is no database, or another one.
     */
    public function verify(string $path, string $edition): array
    {
        try {
            $reader = new Reader($path);
            $metadata = $reader->metadata();
            $accepted = $this->provider->accepts($reader, $edition);
            $reader->close();
        } catch (\Throwable $e) {
            /* translators: %s: error */
            throw new \RuntimeException(esc_html(sprintf(__('The database cannot be read: %s', 'gecka-geolocation'), $e->getMessage())));
        }

        if (! $accepted) {
            /* translators: 1: type of database found, 2: name of the database expected */
            throw new \RuntimeException(esc_html(sprintf(__('The file holds a %1$s database instead of %2$s.', 'gecka-geolocation'), Value::string($metadata->databaseType), $this->provider->editionLabel($edition))));
        }

        return ['type' => Value::string($metadata->databaseType), 'built' => Value::int($metadata->buildEpoch)];
    }

    /**
     * The reader of an edition, opened on first use; null when its file is
     * missing or cannot be read
     *
     * @param string $edition
     *
     * @return Reader|null
     */
    private function reader(string $edition): ?Reader
    {
        if (! isset($this->readers[$edition])) {
            $path = $this->path($edition);
            $this->readers[$edition] = false;

            if (is_file($path)) {
                try {
                    $this->readers[$edition] = new Reader($path);
                } catch (\Exception $e) {
                    $this->errors[$edition] = $e->getMessage();
                }
            }
        }

        return $this->readers[$edition] ?: null;
    }

    /**
     * The network of an address, in CIDR notation
     *
     * @param string $ip
     * @param int    $prefix Bits of the address the network keeps.
     *
     * @return string
     */
    private static function network(string $ip, int $prefix): string
    {
        $binary = (string) inet_pton($ip);
        $bits = strlen($binary) * 8;
        $prefix = max(0, min($bits, $prefix));
        $masked = '';

        for ($i = 0; $i < strlen($binary); $i++) {
            $keep = max(0, min(8, $prefix - $i * 8));
            $masked .= chr(ord($binary[$i]) & (0xff << (8 - $keep)) & 0xff);
        }

        return inet_ntop($masked) . '/' . $prefix;
    }
}
