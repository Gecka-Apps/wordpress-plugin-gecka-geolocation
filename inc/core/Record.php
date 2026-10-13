<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Gecka <contact@gecka.nc>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Gecka\WP\Plugin\Geolocation;

/**
 * What a provider knows of an IP address.
 *
 * Each method asks the provider for its facet, on first use: the country,
 * the city, the network, and so on. A method returns null when the provider
 * does not answer the facet, or knows nothing of the address, as for a
 * private one.
 *
 * Names come in the language asked for, else in the language of the
 * record, else in English: the MaxMind databases hold de, en, es, fr, ja,
 * pt-BR, ru and zh-CN, those of DB-IP fa and ko besides, IPinfo English
 * only.
 *
 * @author Laurent Dinclaux - Gecka <laurent@gecka.nc>
 */
final class Record
{
    /**
     * @var string
     */
    private string $ip;

    /**
     * @var Provider
     */
    private Provider $provider;

    /**
     * Language of the names, as the records write it.
     *
     * @var string
     */
    private string $language;

    /**
     * @var Logos|null
     */
    private ?Logos $logos;

    /**
     * What the provider answered, by facet, once asked.
     *
     * @var array<string, array{data: array<string, mixed>, network: string|null, source: string}|null>
     */
    private array $lookups = [];

    /**
     * @param string     $ip       A valid IP address.
     * @param Provider   $provider
     * @param string     $locale   Locale of WordPress the names are wanted in, as fr_FR.
     * @param Logos|null $logos    The logos of the networks, none when null.
     */
    public function __construct(string $ip, Provider $provider, string $locale, ?Logos $logos = null)
    {
        $this->ip = $ip;
        $this->provider = $provider;
        $this->language = self::language($locale);
        $this->logos = $logos;
    }

    /**
     * The address
     *
     * @return string
     */
    public function ip(): string
    {
        return $this->ip;
    }

    /**
     * ISO 3166-1 code of the country, as FR
     *
     * @return string|null
     */
    public function country(): ?string
    {
        return self::string($this->value(Facet::COUNTRY, ['country', 'iso_code']));
    }

    /**
     * Name of the country
     *
     * @param string|null $locale Locale of WordPress, the one of the record by default.
     *
     * @return string|null
     */
    public function countryName(?string $locale = null): ?string
    {
        return $this->name($this->value(Facet::COUNTRY, ['country', 'names']), $locale);
    }

    /**
     * Emoji of the flag of the country, as 🇫🇷. Windows shows the two
     * letters of the code instead: flagUrl() gives an image drawn everywhere.
     *
     * @return string|null
     */
    public function flag(): ?string
    {
        $country = $this->country();

        return $country !== null ? Flags::emoji($country) : null;
    }

    /**
     * Address of the SVG image of the flag of the country, relative to the
     * root of the site unless asked absolute
     *
     * @param string $ratio    4x3, or 1x1 for a square.
     * @param bool   $absolute With the scheme and the host of the site.
     *
     * @return string|null
     */
    public function flagUrl(string $ratio = '4x3', bool $absolute = false): ?string
    {
        $country = $this->country();

        return $country !== null ? Flags::url($country, $ratio, $absolute) : null;
    }

    /**
     * Path of the SVG file of the flag of the country on the server, to
     * inline it or attach it
     *
     * @param string $ratio 4x3, or 1x1 for a square.
     *
     * @return string|null
     */
    public function flagPath(string $ratio = '4x3'): ?string
    {
        $country = $this->country();

        return $country !== null ? Flags::path($country, $ratio) : null;
    }

    /**
     * Code of the continent: AF, AN, AS, EU, NA, OC or SA
     *
     * @return string|null
     */
    public function continent(): ?string
    {
        return self::string($this->value(Facet::COUNTRY, ['continent', 'code']));
    }

    /**
     * Name of the continent
     *
     * @param string|null $locale
     *
     * @return string|null
     */
    public function continentName(?string $locale = null): ?string
    {
        return $this->name($this->value(Facet::COUNTRY, ['continent', 'names']), $locale);
    }

    /**
     * Whether the country belongs to the European Union; null when no
     * database holds countries
     *
     * @return bool|null
     */
    public function inEuropeanUnion(): ?bool
    {
        $country = $this->value(Facet::COUNTRY, ['country']);

        return is_array($country) && array_key_exists('is_in_european_union', $country) ? (bool) $country['is_in_european_union'] : null;
    }

    /**
     * ISO code of the country the network is registered in, which differs
     * from the country of the address for a mobile or satellite network
     *
     * @return string|null
     */
    public function registeredCountry(): ?string
    {
        return self::string($this->value(Facet::COUNTRY, ['registered_country', 'iso_code']));
    }

    /**
     * Name of the city
     *
     * @param string|null $locale
     *
     * @return string|null
     */
    public function city(?string $locale = null): ?string
    {
        return $this->name($this->value(Facet::CITY, ['city', 'names']), $locale);
    }

    /**
     * Postal code
     *
     * @return string|null
     */
    public function postalCode(): ?string
    {
        return self::string($this->value(Facet::CITY, ['postal', 'code']));
    }

    /**
     * Subdivisions of the country the address lies in, the largest first:
     * their ISO 3166-2 code without the country, and their name
     *
     * @param string|null $locale
     *
     * @return array<int, array{code: string|null, name: string|null}>
     */
    public function subdivisions(?string $locale = null): array
    {
        $subdivisions = [];

        foreach (Value::list($this->value(Facet::CITY, ['subdivisions'])) as $subdivision) {
            if (is_array($subdivision)) {
                $subdivisions[] = [
                    'code' => self::string($subdivision['iso_code'] ?? null),
                    'name' => $this->name($subdivision['names'] ?? null, $locale),
                ];
            }
        }

        return $subdivisions;
    }

    /**
     * ISO 3166-2 code of the largest subdivision, without the country
     *
     * @return string|null
     */
    public function region(): ?string
    {
        return $this->subdivisions()[0]['code'] ?? null;
    }

    /**
     * Name of the largest subdivision
     *
     * @param string|null $locale
     *
     * @return string|null
     */
    public function regionName(?string $locale = null): ?string
    {
        return $this->subdivisions($locale)[0]['name'] ?? null;
    }

    /**
     * Latitude of the place, approximate
     *
     * @return float|null
     */
    public function latitude(): ?float
    {
        $value = $this->value(Facet::CITY, ['location', 'latitude']);

        return is_numeric($value) ? Value::float($value) : null;
    }

    /**
     * Longitude of the place, approximate
     *
     * @return float|null
     */
    public function longitude(): ?float
    {
        $value = $this->value(Facet::CITY, ['location', 'longitude']);

        return is_numeric($value) ? Value::float($value) : null;
    }

    /**
     * Radius around the coordinates the address lies in, in kilometres
     *
     * @return int|null
     */
    public function accuracyRadius(): ?int
    {
        $value = $this->value(Facet::CITY, ['location', 'accuracy_radius']);

        return is_numeric($value) ? Value::int($value) : null;
    }

    /**
     * Time zone of the place, as Pacific/Noumea
     *
     * @return string|null
     */
    public function timeZone(): ?string
    {
        return self::string($this->value(Facet::CITY, ['location', 'time_zone']));
    }

    /**
     * Number of the autonomous system the address belongs to
     *
     * @return int|null
     */
    public function asn(): ?int
    {
        $value = $this->value(Facet::ASN, ['autonomous_system_number']);

        return is_numeric($value) ? Value::int($value) : null;
    }

    /**
     * Organisation the autonomous system is registered to
     *
     * @return string|null
     */
    public function asOrganization(): ?string
    {
        return self::string($this->value(Facet::ASN, ['autonomous_system_organization']));
    }

    /**
     * Domain of the organisation the autonomous system is registered to, as
     * IPinfo gives it: canl.nc
     *
     * @return string|null
     */
    public function asDomain(): ?string
    {
        return self::string($this->value(Facet::ASN, ['as_domain']));
    }

    /**
     * Address of the logo of the network the address belongs to, relative
     * to the root of the site unless asked absolute. Null while the setting
     * is off, and for a network without a logo, or whose logo is not
     * downloaded yet: it is then queued, and comes on a later request.
     *
     * @param bool $absolute With the scheme and the host of the site.
     *
     * @return string|null
     */
    public function networkLogoUrl(bool $absolute = false): ?string
    {
        $asn = $this->asn();

        return $asn !== null && $this->logos !== null ? $this->logos->url($asn, $absolute) : null;
    }

    /**
     * Internet service provider of the address
     *
     * @return string|null
     */
    public function isp(): ?string
    {
        return self::string($this->value(Facet::ISP, ['isp']));
    }

    /**
     * Organisation the address is assigned to
     *
     * @return string|null
     */
    public function organization(): ?string
    {
        return self::string($this->value(Facet::ISP, ['organization']));
    }

    /**
     * Type of connection: Cable/DSL, Cellular, Corporate or Satellite
     *
     * @return string|null
     */
    public function connectionType(): ?string
    {
        return self::string($this->value(Facet::CONNECTION, ['connection_type']));
    }

    /**
     * Second level domain of the address, as example.com
     *
     * @return string|null
     */
    public function domain(): ?string
    {
        return self::string($this->value(Facet::DOMAIN, ['domain']));
    }

    /**
     * The flags the anonymity database sets on the address, as is_anonymous_vpn
     * or is_tor_exit_node, each true or false: the database lists the
     * anonymisers, so an address it lacks gets them all false. Null when no
     * such database is installed.
     *
     * @return array<string, bool>|null
     */
    public function anonymous(): ?array
    {
        if (! $this->answers(Facet::ANONYMOUS)) {
            return null;
        }

        $flags = [];

        foreach (['is_anonymous', 'is_anonymous_vpn', 'is_hosting_provider', 'is_public_proxy', 'is_residential_proxy', 'is_tor_exit_node'] as $flag) {
            $flags[$flag] = ! empty($this->value(Facet::ANONYMOUS, [$flag]));
        }

        return $flags;
    }

    /**
     * Whether the address hides its user: VPN, proxy, Tor or hosting
     * provider; null when no anonymity database is installed
     *
     * @return bool|null
     */
    public function isAnonymous(): ?bool
    {
        $flags = $this->anonymous();

        return $flags === null ? null : in_array(true, $flags, true);
    }

    /**
     * The network the database answering a facet groups the address in, in
     * CIDR notation, as 203.0.113.0/24
     *
     * @param string $facet One of the constants of Facet.
     *
     * @return string|null
     */
    public function network(string $facet = Facet::COUNTRY): ?string
    {
        return $this->fetch($facet)['network'] ?? null;
    }

    /**
     * What answered a facet within the provider: for the databases the
     * plugin downloads, the edition ID, as GeoLite2-City
     *
     * @param string $facet
     *
     * @return string|null
     */
    public function source(string $facet = Facet::COUNTRY): ?string
    {
        return $this->fetch($facet)['source'] ?? null;
    }

    /**
     * Identifier of the provider of the record, as maxmind
     *
     * @return string
     */
    public function provider(): string
    {
        return $this->provider->id();
    }

    /**
     * The credit the licence of the data of the provider asks for, to show
     * where its results are shown or used
     *
     * @return array{text: string, url: string|null}|null
     */
    public function credit(): ?array
    {
        return $this->provider->credit();
    }

    /**
     * The record of the database answering a facet, in the shape of the
     * GeoIP2 databases
     *
     * @param string $facet
     *
     * @return array<string, mixed>|null
     */
    public function raw(string $facet = Facet::COUNTRY): ?array
    {
        return $this->fetch($facet)['data'] ?? null;
    }

    /**
     * Everything the record knows, the names in its language. The path of
     * the flag stays out: a server path has no place in an answer sent to a
     * browser. Asking the logo of the network queues it when it is not in
     * place yet, as networkLogoUrl() does.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_filter(
            [
                'ip' => $this->ip,
                'country' => $this->country(),
                'country_name' => $this->countryName(),
                'flag' => $this->flag(),
                'flag_url' => $this->flagUrl(),
                'continent' => $this->continent(),
                'continent_name' => $this->continentName(),
                'in_european_union' => $this->inEuropeanUnion(),
                'registered_country' => $this->registeredCountry(),
                'subdivisions' => $this->subdivisions() ?: null,
                'city' => $this->city(),
                'postal_code' => $this->postalCode(),
                'latitude' => $this->latitude(),
                'longitude' => $this->longitude(),
                'accuracy_radius' => $this->accuracyRadius(),
                'time_zone' => $this->timeZone(),
                'asn' => $this->asn(),
                'as_organization' => $this->asOrganization(),
                'as_domain' => $this->asDomain(),
                'network_logo_url' => $this->networkLogoUrl(),
                'isp' => $this->isp(),
                'organization' => $this->organization(),
                'connection_type' => $this->connectionType(),
                'domain' => $this->domain(),
                'anonymous' => $this->anonymous(),
            ],
            static fn(mixed $value): bool => $value !== null,
        );
    }

    /**
     * Language of the records matching a locale of WordPress: pt_BR gives
     * pt-BR, fr_BE gives fr
     *
     * @param string $locale
     *
     * @return string
     */
    public static function language(string $locale): string
    {
        $tag = str_replace('_', '-', $locale);

        if (in_array($tag, ['pt-BR', 'zh-CN'], true)) {
            return $tag;
        }

        return strtolower(explode('-', $tag)[0]) ?: 'en';
    }

    /**
     * A value of the record answering a facet. A record holding several
     * facets, as the Enterprise database of MaxMind, keeps the network ones
     * under traits.
     *
     * @param string   $facet
     * @param string[] $path
     *
     * @return mixed
     */
    private function value(string $facet, array $path): mixed
    {
        $lookup = $this->fetch($facet);

        if ($lookup === null) {
            return null;
        }

        $value = $lookup['data'];

        if (in_array($facet, [Facet::ASN, Facet::ISP, Facet::CONNECTION, Facet::DOMAIN], true) && isset($value['traits']) && is_array($value['traits'])) {
            $value = $value['traits'];
        }

        foreach ($path as $key) {
            if (! is_array($value) || ! array_key_exists($key, $value)) {
                return null;
            }

            $value = $value[$key];
        }

        return $value;
    }

    /**
     * What the provider answers for a facet, asked once. A provider that
     * does not answer the facet, answers in another shape than the one of
     * the interface, or fails, knows nothing: the record stays usable.
     *
     * @param string $facet
     *
     * @return array{data: array<string, mixed>, network: string|null, source: string}|null
     */
    private function fetch(string $facet): ?array
    {
        if (array_key_exists($facet, $this->lookups)) {
            return $this->lookups[$facet];
        }

        $lookup = null;

        try {
            $answer = $this->answers($facet) ? $this->provider->lookup($facet, $this->ip) : null;

            // Checked as if the provider had followed no contract at all.
            if (self::shaped($answer)) {
                $lookup = ['data' => $answer['data'], 'network' => $answer['network'] ?? null, 'source' => $answer['source']];
            }
        } catch (\Throwable) {
            // A provider that fails answers nothing.
        }

        return $this->lookups[$facet] = $lookup;
    }

    /**
     * Whether an answer of a provider has the shape the interface asks for
     *
     * @param mixed $answer
     *
     * @return bool
     *
     * @phpstan-assert-if-true array{data: array<string, mixed>, network?: string|null, source: string} $answer
     */
    private static function shaped(mixed $answer): bool
    {
        return is_array($answer)
            && is_array($answer['data'] ?? null)
            && is_string($answer['source'] ?? null)
            && (! isset($answer['network']) || is_string($answer['network']));
    }

    /**
     * Whether the provider answers a facet, a provider that fails answering
     * none
     *
     * @param string $facet
     *
     * @return bool
     */
    private function answers(string $facet): bool
    {
        try {
            return $this->provider->available($facet);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * A name in the language asked for, else in the language of the record,
     * else in English
     *
     * @param mixed       $names
     * @param string|null $locale
     *
     * @return string|null
     */
    private function name(mixed $names, ?string $locale): ?string
    {
        if (! is_array($names)) {
            return null;
        }

        $languages = [];

        // A Portuguese or Chinese the records lack falls back on the one
        // they hold.
        foreach (array_filter([$locale !== null ? self::language($locale) : null, $this->language]) as $language) {
            $languages[] = $language;
            $languages[] = ['pt' => 'pt-BR', 'zh' => 'zh-CN'][$language] ?? null;
        }

        $languages[] = 'en';

        foreach (array_unique(array_filter($languages)) as $language) {
            if (isset($names[$language]) && is_string($names[$language]) && $names[$language] !== '') {
                return $names[$language];
            }
        }

        return null;
    }

    /**
     * A non-empty string, or null
     *
     * @param mixed $value
     *
     * @return string|null
     */
    private static function string(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
