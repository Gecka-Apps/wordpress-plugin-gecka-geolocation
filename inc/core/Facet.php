<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Gecka <contact@gecka.nc>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Gecka\WP\Plugin\Geolocation;

/**
 * The facets of an address a provider may answer: its country, its city,
 * the network it belongs to, the way it connects, whether it hides behind a
 * proxy.
 *
 * @author Laurent Dinclaux - Gecka <laurent@gecka.nc>
 */
final class Facet
{
    /**
     * Country, continent, registered country.
     */
    public const COUNTRY = 'country';

    /**
     * City, subdivisions, postal code, coordinates, time zone.
     */
    public const CITY = 'city';

    /**
     * Autonomous system number and organisation.
     */
    public const ASN = 'asn';

    /**
     * Internet service provider and organisation.
     */
    public const ISP = 'isp';

    /**
     * Connection type: cable/DSL, cellular, corporate, satellite.
     */
    public const CONNECTION = 'connection_type';

    /**
     * Second level domain of the address.
     */
    public const DOMAIN = 'domain';

    /**
     * Anonymous VPN, hosting provider, public proxy, residential proxy, Tor
     * exit node.
     */
    public const ANONYMOUS = 'anonymous';

    /**
     * Every facet.
     */
    public const ALL = [self::COUNTRY, self::CITY, self::ASN, self::ISP, self::CONNECTION, self::DOMAIN, self::ANONYMOUS];
}
