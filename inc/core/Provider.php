<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Gecka <contact@gecka.nc>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Gecka\WP\Plugin\Geolocation;

/**
 * A source of locations: MaxMind, DB-IP, IPinfo, or one another plugin adds
 * through the gecka_geolocation_providers filter.
 *
 * A provider answers facets of an address with records in the shape of the
 * GeoIP2 databases of MaxMind, which DB-IP also publishes its files in, and
 * which a provider whose records differ, as IPinfo, puts them in: country
 * and continent as
 * ['country' => ['iso_code' => 'FR', 'names' => ['en' => 'France', 'fr' => 'France']]],
 * subdivisions, city, postal, location, and the network facets (asn, isp,
 * connection_type, domain, anonymous) either at the top of the record or
 * under traits. Record reads that shape whatever the provider.
 *
 * A provider looks an address up as often as asked: it keeps what it read
 * for the request itself when that is costly.
 *
 * @author Laurent Dinclaux - Gecka <laurent@gecka.nc>
 */
interface Provider
{
    /**
     * Identifier of the provider: a lowercase letter, then lowercase
     * letters, digits, dashes and underscores, as maxmind
     *
     * @return string
     */
    public function id(): string;

    /**
     * Name of the provider, shown to administrators
     *
     * @return string
     */
    public function label(): string;

    /**
     * Whether the provider can answer a facet now: its data is in place
     *
     * @param string $facet One of the constants of Facet.
     *
     * @return bool
     */
    public function available(string $facet): bool;

    /**
     * What the provider knows of an address for a facet
     *
     * @param string $facet One of the constants of Facet.
     * @param string $ip    A valid IP address.
     *
     * @return array{data: array<string, mixed>, network: string|null, source: string}|null The record, the network it groups the address in (CIDR) and the name of what answered, as GeoLite2-City; null when it knows nothing of the address, or does not answer the facet.
     */
    public function lookup(string $facet, string $ip): ?array;

    /**
     * The credit the licence of the data asks for, for the plugins showing
     * or using its results to build the sentence and the link: the text to
     * show, untranslated as the licence words it, and the address to link
     *
     * @return array{text: string, url: string|null}|null Null when the data in use asks for none.
     */
    public function credit(): ?array;
}
