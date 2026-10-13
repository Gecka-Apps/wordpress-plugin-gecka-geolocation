# Gecka Geolocation for developers

Gecka Geolocation locates IP addresses on the server, with providers of
location data: MaxMind (GeoLite or GeoIP® databases), DB-IP (Lite databases)
and IPinfo (IPinfo Lite), whose files it downloads and keeps up to date, and
any provider another plugin adds. This page is for the plugins and themes
that ask it where an address is, and for those that add a provider.

## Checking that the plugin is there

The functions exist once the plugin is loaded, from `plugins_loaded` on. A
plugin that can do without geolocation tests for them:

```php
$country = function_exists('gecka_geolocation_country') ? gecka_geolocation_country() : null;
```

Nothing else is needed: no class to load. Call them from `plugins_loaded`
on, once every plugin had a chance to register its provider; earlier, only
the providers the plugin ships answer.

## Functions

| Function | Returns |
|---|---|
| `gecka_geolocation_country(?string $ip = null, ?string $provider = null): ?string` | ISO 3166-1 code of the country, as `FR` |
| `gecka_geolocation(?string $ip = null, ?string $provider = null): ?Gecka\WP\Plugin\Geolocation\Record` | Everything the provider knows of the address |
| `gecka_geolocation_available(string $facet = 'country', ?string $provider = null): bool` | Whether the provider answers a facet |
| `gecka_geolocation_providers(): array` | Names of the providers by identifier, the default one first: `['maxmind' => 'MaxMind', 'dbip' => 'DB-IP', 'ipinfo' => 'IPinfo']` |
| `gecka_geolocation_credit(?string $provider = null): ?array` | The credit the licence of the data asks for: `['text' => 'IP Geolocation by DB-IP', 'url' => 'https://db-ip.com']` (see [Crediting the data](#crediting-the-data)) |
| `gecka_geolocation_country_name(string $country, ?string $locale = null): ?string` | Name of a country from its code, as `Nouvelle-Calédonie` for `NC` in `fr_FR` |
| `gecka_geolocation_flag(string $country): ?string` | Emoji of the flag of a country code, as `🇫🇷` |
| `gecka_geolocation_flag_url(string $country, string $ratio = '4x3', bool $absolute = false): ?string` | Address of the SVG image of the flag of a country code, `4x3` or `1x1` |
| `gecka_geolocation_flag_path(string $country, string $ratio = '4x3'): ?string` | Path of that SVG file on the server |
| `gecka_geolocation_network_logo_url(int $asn, bool $absolute = false): ?string` | Address of the logo of a network, once the setting is on (see [Logos of the networks](#logos-of-the-networks)) |
| `gecka_geolocation_client_ip(): string` | Address of the visitor, empty when unknown |
| `gecka_geolocation_plugin(): Gecka\WP\Plugin\Geolocation\Plugin` | The plugin itself, for what the functions above do not cover: `->mmdb('maxmind')` gives a bundled provider, with its `settings()`, `updater()` and `databases()` |

Without an address, the functions locate the visitor (see
[The address of the visitor](#the-address-of-the-visitor)). Without a
provider, they ask the default one (see [Providers](#providers)).

`gecka_geolocation()` returns `null` for an invalid address, for a provider
that is not registered, or for one that has no data yet, as a provider
before its first download. For a private or reserved address, or one the provider
does not cover, it returns a record whose methods all return `null`.

A lookup reads the data on the first call and keeps the answer for the
request: several plugins asking about the same address cost one lookup.

## The record

The object `gecka_geolocation()` returns, a `Gecka\WP\Plugin\Geolocation\Record`: what the provider knows of one address, read through its methods.

```php
$record = gecka_geolocation($ip);

if ($record) {
    echo $record->flag(), " ", $record->countryName(), ", ", $record->city();
}
```

Every method returns `null` when the provider does not answer its facet, or
knows nothing of the address. One exception: with an anonymity database in
place, `anonymous()` gives every flag `false` and `isAnonymous()` gives
`false` for an address the database does not list, since that database
lists anonymisers only.

| Method | Facet | Example |
|---|---|---|
| `ip()` | | `81.2.69.142` |
| `country()` | country | `GB` |
| `countryName(?string $locale = null)` | country | `United Kingdom` |
| `flag()` | country | `🇬🇧` |
| `flagUrl(string $ratio = '4x3', bool $absolute = false)` | country | `/wp-content/plugins/gecka-geolocation/assets/flags/4x3/gb.svg?ver=1.0.0`; `1x1` for a square |
| `flagPath(string $ratio = '4x3')` | country | Path of that SVG file on the server |
| `continent()` | country | `EU` |
| `continentName(?string $locale = null)` | country | `Europe` |
| `inEuropeanUnion(): ?bool` | country | `false`; null when the provider does not tell, as IPinfo Lite |
| `registeredCountry()` | country | `GB`: the country the network is registered in, which differs for a mobile or satellite network |
| `city(?string $locale = null)` | city | `London` |
| `postalCode()` | city | `SW1A` |
| `subdivisions(?string $locale = null): array` | city | `[['code' => 'ENG', 'name' => 'England']]`, the largest first |
| `region()` | city | `ENG`, the ISO 3166-2 code of the largest subdivision without the country |
| `regionName(?string $locale = null)` | city | `England` |
| `latitude(): ?float`, `longitude(): ?float` | city | `51.5142`, `-0.0931` |
| `accuracyRadius(): ?int` | city | `100`, in kilometres |
| `timeZone()` | city | `Europe/London` |
| `asn(): ?int` | asn | `1221` |
| `asOrganization()` | asn | `Telstra Pty Ltd` |
| `asDomain()` | asn | `telstra.com`, from IPinfo only |
| `networkLogoUrl(bool $absolute = false)` | asn | `/wp-content/uploads/gecka-geolocation-logos/1221.png`, see [Logos of the networks](#logos-of-the-networks) |
| `isp()`, `organization()` | isp | `Telstra Internet` |
| `connectionType()` | connection_type | `Cable/DSL`, `Cellular`, `Corporate`, `Satellite` |
| `domain()` | domain | `example.com` |
| `anonymous(): ?array` | anonymous | `['is_anonymous' => true, 'is_anonymous_vpn' => true, 'is_hosting_provider' => false, 'is_public_proxy' => false, 'is_residential_proxy' => false, 'is_tor_exit_node' => false]` |
| `isAnonymous(): ?bool` | anonymous | `true` when any flag is set |
| `network(string $facet = 'country')` | | `81.2.69.142/31`, the network the provider groups the address in |
| `provider()` | | `maxmind`, the provider of the record |
| `source(string $facet = 'country')` | | What answered within the provider: the edition ID of the database, as `GeoLite2-City` or `dbip-city-lite` |
| `raw(string $facet = 'country'): ?array` | | The record of the provider, in the shape of the GeoIP2 databases |
| `credit(): ?array` | | The credit the licence of the data of the provider asks for |
| `toArray(): array` | | Every value known but the path of the flag, the `null` ones left out |

Names come in the language of the `$locale` given (a WordPress locale, as
`fr_FR`), else in the language of the request, else in English. The MaxMind
databases hold de, en, es, fr, ja, pt-BR, ru and zh-CN, the DB-IP ones fa
and ko besides, IPinfo Lite English only.

`raw()` gives what the methods leave out: confidence figures of the
Enterprise database, `geoname_id`, `represented_country`, `traits`, the
fields of Anonymous Plus (`anonymizer_confidence`, `provider_name`,
`network_last_seen`).

## Flags

The flag comes two ways. The emoji costs nothing and goes anywhere text
goes, but Windows draws no flag emoji: it shows the two letters of the
code. The image is an SVG file the plugin ships, from
[flag-icons](https://github.com/lipis/flag-icons) (MIT), drawn by every
browser, in two shapes: `4x3` and square (`1x1`), for a round badge with
`border-radius: 50%`.

```php
$record = gecka_geolocation();

if ($record && $record->flagUrl()) {
    printf(
        '<img src="%s" alt="%s" width="20" height="15">',
        esc_url($record->flagUrl()),
        esc_attr($record->countryName() ?? $record->country()),
    );
}
```

The address is relative to the root of the site, without the scheme and
the host: it is the same whatever request computes it, a page, WP-Cron or
WP-CLI, and whatever site of a network, so it can be stored.
`$absolute = true` adds the scheme and the host the current request sees.
It carries the version of the plugin, so that a flag redrawn reaches the
browsers that kept the old one. `flagPath()` and
`gecka_geolocation_flag_path()` give the file itself, to inline the SVG for
one.

A plugin keeping a country code, in a journal for one, draws its flag later
with `gecka_geolocation_flag($code)`, `gecka_geolocation_flag_url($code)` or
`gecka_geolocation_flag_path($code)`, and names it with
`gecka_geolocation_country_name($code)`, without looking the address up
again. The name comes in the language of the request unless a locale is
given, from the intl extension of PHP, or without it from the list the
plugin ships, translated like the rest of the plugin. The list holds the
codes the flags cover, `PC` (Pacific Community) among them; for a code it
does not hold, as `ZZ`, the function returns the code itself.
The images cover the countries and territories of ISO 3166-1, plus `EU`
(European Union), `UN` (United Nations) and `XK` (Kosovo), which the
databases also use. New Caledonia (`NC`) is drawn with the Kanak flag; the
emoji is whatever the system draws.

## Logos of the networks

Once "Logos of the networks" is on in Settings > Geolocation (off by
default), `gecka_geolocation_network_logo_url($asn)` and
`$record->networkLogoUrl()` give the address of the logo of a network, a
small PNG image of 16 to 100 pixels or so. The logos come from
[asn-data](https://github.com/tiagozip/asn-data), a mirror of PeeringDB with
the favicon of the site of each network. Many networks are missing: draw a generic icon when the
answer is null.

The browser never asks the source. The first call for a network returns
null and queues its number; a WP-Cron event downloads the queue by batches
into `uploads/gecka-geolocation-logos/` of the main site, and the next calls
return the address of that file, relative to the root of the site unless
`$absolute` is true. A network the source has no logo for is not asked
again for a month. Turning the setting off deletes the logos.

```php
$logo = function_exists('gecka_geolocation_network_logo_url') ? gecka_geolocation_network_logo_url($asn) : null;

echo $logo
    ? sprintf('<img src="%s" alt="" width="16" height="16">', esc_url($logo))
    : '<span class="dashicons dashicons-admin-site"></span>';
```

## Facets

A facet is a kind of question. The constants of
`Gecka\WP\Plugin\Geolocation\Facet` name them:

| Constant | Facet | Answers |
|---|---|---|
| `Facet::COUNTRY` | `country` | Country, continent, registered country |
| `Facet::CITY` | `city` | Subdivisions, city, postal code, coordinates, time zone |
| `Facet::ASN` | `asn` | Autonomous system and its organisation |
| `Facet::ISP` | `isp` | Internet service provider and organisation |
| `Facet::CONNECTION` | `connection_type` | Connection type |
| `Facet::DOMAIN` | `domain` | Domain of the address |
| `Facet::ANONYMOUS` | `anonymous` | VPN, hosting provider, proxy, Tor exit node |

A plugin needing the city tells the site administrator when the facet is
missing:

```php
if (function_exists('gecka_geolocation_available') && ! gecka_geolocation_available('city')) {
    // Ask for GeoLite City in Settings > Geolocation.
}
```

When several databases of a provider answer a facet, the first installed of
this list wins:

| Facet | MaxMind | DB-IP | IPinfo |
|---|---|---|---|
| `country` | GeoIP2-Enterprise, GeoIP2-City, GeoIP2-Country, GeoLite2-City, GeoLite2-Country | dbip-city-lite, dbip-country-lite | ipinfo_lite |
| `city` | GeoIP2-Enterprise, GeoIP2-City, GeoLite2-City | dbip-city-lite | |
| `asn` | GeoIP2-Enterprise, GeoIP2-ISP, GeoLite2-ASN | dbip-asn-lite | ipinfo_lite |
| `isp` | GeoIP2-Enterprise, GeoIP2-ISP | | |
| `connection_type` | GeoIP2-Enterprise, GeoIP2-Connection-Type | | |
| `domain` | GeoIP2-Enterprise, GeoIP2-Domain | | |
| `anonymous` | GeoIP-Anonymous-Plus, GeoIP2-Anonymous-IP | | |

## The address of the visitor

Without an address, the functions read `REMOTE_ADDR`. Behind a reverse proxy
or a CDN, that is the address of the proxy. The `gecka_geolocation_client_ip`
filter returns the right one:

```php
add_filter('gecka_geolocation_client_ip', function (string $ip): string {
    // Cloudflare in front of the site, and only Cloudflare can reach it.
    return $_SERVER['HTTP_CF_CONNECTING_IP'] ?? $ip;
});
```

Read the forwarded address only from a header your proxy sets, and only when
the request comes from the proxy: anyone can send `X-Forwarded-For`. A value
that is not an IP address makes the visitor unknown.

## Providers

A provider is a source of locations, identified by a short name. The plugin
ships three, which download their databases once set up in their tab of
Settings > Geolocation:

| Identifier | Provider | Databases | Needs |
|---|---|---|---|
| `maxmind` | MaxMind | GeoLite Country, City, ASN (free); GeoIP Country, City, Enterprise, ISP, Connection Type, Domain, Anonymous IP, Anonymous Plus (paid) | An account ID and a licence key |
| `dbip` | DB-IP | DB-IP Country Lite, City Lite, ASN Lite | Nothing: a database ticked |
| `ipinfo` | IPinfo | IPinfo Lite: country and autonomous system | The access token of a free account |

A lookup names its provider, or gets the default one: the provider chosen in
Settings > Geolocation, or by default the first one registered with data in
place, which the `gecka_geolocation_default_provider` filter can change. A
default provider that is not registered falls back on the first one.

```php
// DB-IP, whatever the default.
$country = gecka_geolocation_country($ip, 'dbip');

// A plugin making its own provider the default.
add_filter('gecka_geolocation_default_provider', fn(string $provider, array $available): string
    => in_array('my-provider', $available, true) ? 'my-provider' : $provider, 10, 2);
```

### Adding a provider

A plugin adds a provider through the `gecka_geolocation_providers` filter,
an object implementing `Gecka\WP\Plugin\Geolocation\Provider`:

```php
use Gecka\WP\Plugin\Geolocation\Facet;
use Gecka\WP\Plugin\Geolocation\Provider;

final class MyProvider implements Provider
{
    public function id(): string
    {
        return 'my-provider';
    }

    public function label(): string
    {
        return 'My provider';
    }

    public function available(string $facet): bool
    {
        return in_array($facet, [Facet::COUNTRY, Facet::CITY], true);
    }

    public function lookup(string $facet, string $ip): ?array
    {
        $found = my_provider_find($ip); // Kept for the request by the provider.

        return $found ? ['data' => $found, 'network' => null, 'source' => 'my-database'] : null;
    }

    public function credit(): ?array
    {
        return ['text' => 'Locations by My provider', 'url' => 'https://example.com'];
    }
}

add_filter('gecka_geolocation_providers', function (array $providers): array {
    $providers['my-provider'] = new MyProvider();

    return $providers;
});
```

- `id()` starts with a lowercase letter and holds lowercase letters, digits,
  dashes and underscores; a provider with another identifier, or anything
  that is not a `Provider`, is left out.
- A provider that throws, or answers in another shape, counts as one that
  knows nothing: the lookups of the other providers go on.
- `available()` tells whether the provider can answer a facet now, its data
  in place.
- `lookup()` returns the record in the shape of the GeoIP2 databases of
  MaxMind, which DB-IP also publishes its files in, and which the plugin
  puts the flat records of IPinfo in: `['country' => ['iso_code' => 'FR', 'names' => ['en' => 'France']], 'city' => [...], 'subdivisions' => [...], 'location' => [...]]`, the network facets at the top of the
  record or under `traits`. `network` is the network in CIDR notation, or
  null; `source` names what answered within the provider. Null when the
  provider knows nothing of the address.
- `credit()` returns the credit the licence of the data asks for, or null
  when it asks for none.
- The list is read once a request, on the first lookup: add the filter on
  `plugins_loaded` at the latest.

A provider keeping files gets a folder of the protected directory of the
plugin with `Gecka\WP\Plugin\Geolocation\Storage::prepare('my-provider')`,
which creates it and returns its path, and announces new data with the
`gecka_geolocation_updated` action. It adds its own screen as a tab of
Settings > Geolocation through
[gecka/wp-admin-menu](https://github.com/Gecka-Apps/wp-admin-menu):
`Menus::options('gecka-geolocation')->tab('my-provider')`.

## Hooks

| Hook | Type | Use |
|---|---|---|
| `gecka_geolocation_providers` | filter | `(array $providers)`: the providers by identifier |
| `gecka_geolocation_default_provider` | filter | `(string $provider, array $available)`: the provider asked when a lookup names none |
| `gecka_geolocation_client_ip` | filter | `(string $ip)`: address of the visitor, `REMOTE_ADDR` by default |
| `gecka_geolocation_updated` | action | `(string $provider, string $source, string $path)`: a provider put new data in place; for those the plugin ships, the edition ID and the path of the `.mmdb` file |
| `gecka_geolocation_directory` | filter | `(string $directory)`: where the providers keep their data |
| `gecka_geolocation_capability` | filter | `(string $capability)`: capability of the settings, `manage_network_options` on a multisite, `manage_options` otherwise |

## Constants

Defined in `wp-config.php`:

| Constant | Use |
|---|---|
| `GECKA_GEOLOCATION_MAXMIND_ACCOUNT_ID` | Account ID of the MaxMind account, instead of the settings |
| `GECKA_GEOLOCATION_MAXMIND_LICENSE_KEY` | Licence key, instead of the settings, which then never store it |
| `GECKA_GEOLOCATION_IPINFO_TOKEN` | Access token of the IPinfo account, instead of the settings |
| `GECKA_GEOLOCATION_DIRECTORY` | Directory of the data, out of the web root for one. Created when missing |

## The files

The providers keep their data in a directory of the uploads of the main site
named `gecka-geolocation-` followed by a random key, or in
`GECKA_GEOLOCATION_DIRECTORY`, each in a folder of its own. The databases
are `.mmdb` files named after their edition ID, as
`maxmind/GeoLite2-City.mmdb`, `dbip/dbip-country-lite.mmdb` or
`ipinfo/ipinfo_lite.mmdb`. The logos of the networks are apart, in the
public folder `gecka-geolocation-logos` of the uploads. A new release is written next to the old one and
moved in its place in one step: a request never reads half a file. A plugin
reading the files with its own reader takes the path from the
`gecka_geolocation_updated` action, or from
`gecka_geolocation_plugin()->mmdb('maxmind')->databases()->path($edition)`,
and opens it again after an update. The records of IPinfo Lite are flat in
the file: the plugin puts them in the shape of the GeoIP2 databases only
when it answers a lookup.

The plugin reads the files with the PHP reader of MaxMind,
`maxmind-db/reader`, which it ships under its own namespace
(`Gecka\Geolocation\Vendor\MaxMind\Db\Reader`): it does not clash with the
copy of WooCommerce or of another plugin.

## Crediting the data

The free databases ask for a credit where their data is shown or used.
`gecka_geolocation_credit($provider)`, or `$record->credit()` for the
provider of a record, gives what to show: the text, worded as the licence
asks and therefore untranslated, and the address to link. The plugin
leaves the sentence and the markup to you:

```php
$credit = gecka_geolocation_credit();

if ($credit) {
    echo '<p class="geolocation-credit">';
    echo $credit['url'] ? sprintf('<a href="%s">%s</a>', esc_url($credit['url']), esc_html($credit['text'])) : esc_html($credit['text']);
    echo '</p>';
}
```

| Provider | Text | Address | When |
|---|---|---|---|
| `maxmind` | This product includes GeoLite Data created by MaxMind, available from https://www.maxmind.com. | https://www.maxmind.com | While a GeoLite database is installed; null for GeoIP databases alone |
| `dbip` | IP Geolocation by DB-IP | https://db-ip.com | Always: CC BY 4.0, a link on the pages that show or use the results |
| `ipinfo` | IP address data powered by IPinfo | https://ipinfo.io | Always: CC BY-SA 4.0 |

`url` may be null for a provider another plugin adds: show the text alone.

## Working on the plugin

The plugin runs on PHP 8.2 and later; the tests need PHP 8.3, for Pest 4.

```sh
composer install
composer format          # Pint
composer lint:php        # Pint (PER) and PHPCS (WordPress security sniffs)
composer analyse         # PHPStan at the highest level, PHP 8.2 to 8.5
composer test            # Pest on Mantle, WordPress on SQLite in .tests/
composer test:multisite  # the same on a network
composer i18n            # template, catalogs, .mo and .l10n.php, from the code
composer i18n:check      # what the CI runs: every string in the template, translated and compiled
bin/build [dir]          # installable zip of the package of bin/package, in builds/ by default
```

The package that ships is what `.distignore` leaves of the tree, with the dependencies installed without the dev ones: `bin/package <dir>` copies it, `bin/zip <dir> <archive>` zips it under a `gecka-geolocation/` top folder. `bin/check-version [vX.Y.Z]` checks that the header, `Plugin::VERSION` and the Stable tag agree, `bin/changelog X.Y.Z` prints the entry of readme.txt.

The GitHub workflows and the GitLab CI run the gate on PHP 8.3 to 8.5, lint the syntax on 8.2, check the translations, run Plugin Check on the package, make a release with the zip on a `vX.Y.Z` tag, and publish on WordPress.org by a manual job on that tag (`SVN_USERNAME` and `SVN_PASSWORD` secrets); whichever of the two runs second finds the version published and stops.

The MaxMind reader is copied into `inc/vendor-prefixed/` under the `Gecka\Geolocation\Vendor\` namespace by [Strauss](https://github.com/BrianHenryIE/strauss), and committed: WooCommerce ships the same library without a prefix. After a change of `maxmind-db/reader` in `composer.lock`, run `composer prefix`.

The tests read the test databases of MaxMind ([MaxMind-DB](https://github.com/maxmind/MaxMind-DB), Apache-2.0 or MIT) in `tests/fixtures/`, write the DB-IP and IPinfo ones they need with `tests/mmdb.php`, and answer the requests to the providers themselves: no test reaches the network.

---
Built with 🥥 and ☕ by [Gecka](https://gecka.nc) — Kanaky-New Caledonia 🇳🇨
