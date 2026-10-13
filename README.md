![](.github/banner.svg)

[![CI](https://github.com/Gecka-Apps/wordpress-plugin-gecka-geolocation/actions/workflows/ci.yml/badge.svg)](https://github.com/Gecka-Apps/wordpress-plugin-gecka-geolocation/actions/workflows/ci.yml)
[![WordPress 6.8+](https://img.shields.io/badge/WordPress-6.8%2B-21759b?logo=wordpress&logoColor=white)](readme.txt)
[![PHP 8.2+](https://img.shields.io/badge/PHP-8.2%2B-777bb4?logo=php&logoColor=white)](readme.txt)
[![License](https://img.shields.io/badge/license-GPL--3.0--or--later-blue.svg)](LICENSE)

# Gecka Geolocation for WordPress

Plugins keep asking where an IP address comes from: to name the country of a visitor, to show where an attack originates, to let WooCommerce guess the location of a customer. Gecka Geolocation answers that question on your server, from the IP geolocation databases of MaxMind, DB-IP or IPinfo, which it downloads and keeps up to date. No address of a visitor leaves the server.

On its own, the plugin changes nothing on your site. It serves the plugins that ask it, Gecka Security and Gecka Antispam among them, and WooCommerce when you turn that on. Until one of them asks, it keeps the databases ready.

## 🧭 Principles

- **Everything stays on your server.** The lookups read files on disk. Nothing about the site or its visitors goes to a provider: the only requests are the downloads of the databases.
- **The provider is your choice.** MaxMind, DB-IP or IPinfo, one or several, free or paid. A plugin asking a question gets the best database installed for it.
- **The licences are handled for you.** The plugins that show the data get the credit the free databases ask for, an outdated GeoLite release is reported before its 30 days are out, and the files are kept out of reach of browsers.
- **One download for the whole network.** On a multisite, every site shares the settings and the databases.
- **Nothing is downloaded until you ask.** No database is fetched before you tick it and enter the credentials of its provider.

## 🗺️ Databases

| Provider | Database | Account | Answers |
|---|---|---|---|
| MaxMind | GeoLite Country | free | Country and continent |
| MaxMind | GeoLite City | free | Country, region, city, postal code, coordinates, time zone |
| MaxMind | GeoLite ASN | free | Autonomous system and its organisation |
| MaxMind | GeoIP Country | paid | Country and continent |
| MaxMind | GeoIP City | paid | Country, region, city, postal code, coordinates, time zone |
| MaxMind | GeoIP Enterprise | paid | City, network, ISP, connection type and domain |
| MaxMind | GeoIP ISP | paid | Autonomous system, ISP and organisation |
| MaxMind | GeoIP Connection Type | paid | Cable/DSL, cellular, corporate, satellite |
| MaxMind | GeoIP Domain | paid | Domain of the address |
| MaxMind | GeoIP Anonymous IP | paid | VPN, hosting provider, proxy, Tor exit node |
| MaxMind | GeoIP Anonymous Plus | paid | Like Anonymous IP, with a confidence score and the name of the VPN provider |
| DB-IP | DB-IP Country Lite | none | Country and continent |
| DB-IP | DB-IP City Lite | none | Country, region, city, coordinates |
| DB-IP | DB-IP ASN Lite | none | Autonomous system and its organisation |
| IPinfo | IPinfo Lite | free | Country, continent, autonomous system and its domain |

The MaxMind tab asks MaxMind which databases your account has access to, and marks them. When several databases of a provider answer the same question, the paid one wins over the free one, and the most detailed over the others.

## 📊 Choosing a provider

The three free offers compared. The paid GeoIP databases of MaxMind are GeoLite with more detail, more fields and daily releases.

| | MaxMind GeoLite | DB-IP Lite | IPinfo Lite |
|---|---|---|---|
| Account | Free GeoLite account and licence key | None | Free account and access token |
| Databases | Country, City, ASN | Country, City, ASN | One, country and ASN together |
| Releases | Country and City on Tuesdays and Fridays, ASN every weekday | Monthly | Daily |
| Download limit | 30 a day per account; the check of the plugin does not count | None stated | Limited per token; the plugin downloads only when the checksum changed |
| Size | Country 3 MB, City 48 MB, ASN 9 MB | Country 8 MB, City 121 MB, ASN 9 MB | 25 MB |
| Accuracy, as the provider tells it | Less accurate than the paid GeoIP, which MaxMind recommends for commercial-grade use | An accuracy index of 77 out of 100, against 96 for its paid database | Country and ASN verified by its probe network, the same data as its paid tiers |
| Behaviour | Gives an accuracy radius, and no city when it is unsure | Always names a city, even for an anycast address; a city sometimes carries its neighbourhood | A country and a network, nothing finer; the domain of the network comes with it |
| Languages of the names | 8 (de, en, es, fr, ja, pt-BR, ru, zh-CN) | 10 for countries and continents (fa and ko besides), cities in English | English |
| French outermost regions in the European Union | Yes (Réunion is in) | No | Not told; Réunion is placed in Africa |
| Licence | GeoLite EULA: a credit, an outdated release deleted within 30 days, no redistribution | CC BY 4.0: a link on the pages that show or use the results | CC BY-SA 4.0: a credit with a link |
| Credit | This product includes GeoLite Data created by MaxMind, available from https://www.maxmind.com. | IP Geolocation by DB-IP | IP address data powered by IPinfo |

What each database answers, as the plugin reads it:

| | MaxMind GeoLite (free) | MaxMind GeoIP (paid) | DB-IP Lite | IPinfo Lite |
|---|:---:|:---:|:---:|:---:|
| Country and continent | ✅ | ✅ | ✅ | ✅ |
| Member of the European Union | ✅ | ✅ | ✅ | ❌ |
| Registered country | ✅ | ✅ | ❌ | ❌ |
| Region, by name | ✅ | ✅ | ✅ | ❌ |
| Region, by ISO 3166-2 code | ✅ | ✅ | ❌ | ❌ |
| City | ✅ | ✅ | ✅ | ❌ |
| Postal code | ✅ | ✅ | ❌ | ❌ |
| Coordinates | ✅ | ✅ | ✅ | ❌ |
| Accuracy radius | ✅ | ✅ | ❌ | ❌ |
| Time zone | ✅ | ✅ | ❌ | ❌ |
| Autonomous system and its organisation | ✅ | ✅ | ✅ | ✅ |
| Domain of the autonomous system | ❌ | ❌ | ❌ | ✅ |
| ISP and organisation | ❌ | ✅ | ❌ | ❌ |
| Connection type | ❌ | ✅ | ❌ | ❌ |
| Domain of the address | ❌ | ✅ | ❌ | ❌ |
| Anonymity: VPN, proxy, Tor, hosting | ❌ | ✅ | ❌ | ❌ |
| Names in several languages | ✅ | ✅ | ✅ | ❌ |
| IPv6 | ✅ | ✅ | ✅ | ✅ |

A region or a city is only as good as the database. For taxes or anything binding, the paid databases are the ones made for it.

## ⚙️ Setting up

1. Activate the plugin, for the network on a multisite.
2. Under Settings > Geolocation (network admin on a multisite), open the tab of a provider:
   - **MaxMind**: open a free GeoLite account at [maxmind.com](https://www.maxmind.com/en/geolite-free-ip-geolocation-data), or use your GeoIP account, create a licence key on the licence keys page of the account, and enter the account ID and the key.
   - **DB-IP**: nothing to enter.
   - **IPinfo**: open a free account at [ipinfo.io](https://ipinfo.io/lite) and enter the access token of its dashboard.
3. Tick the databases and save. The Databases tab downloads them with a progress bar, shows their release and lets you try an address with any provider.
4. In the Settings tab, choose the provider the plugins and WooCommerce get when they name none. By default it is the first one with databases in place, in the order MaxMind, DB-IP, IPinfo.

To keep the credentials out of the database, define them in `wp-config.php`. The settings then show the field disabled, with the name of its constant:

```php
define('GECKA_GEOLOCATION_MAXMIND_ACCOUNT_ID', '123456');
define('GECKA_GEOLOCATION_MAXMIND_LICENSE_KEY', 'your_licence_key');
define('GECKA_GEOLOCATION_IPINFO_TOKEN', 'your_token');
```

## 🔄 Updates

A daily task asks each provider for its latest releases and downloads only what changed. A download is checked against the checksum the provider publishes, then opened to make sure it holds the database expected, and moved in place of the old one in one step: a request never reads half a file. A database you untick is deleted. When a download fails, the task tries again within the hour, and Site Health tells you why.

Asking MaxMind for its latest releases does not count in the daily download limit of your account. DB-IP publishes one release a month. IPinfo limits the downloads of a token to a few a day, so the plugin downloads only when the checksum it publishes changed.

## ⚖️ Licence of the databases

- **MaxMind.** The GeoLite databases are under the [GeoLite End User License Agreement](https://www.maxmind.com/en/geolite/eula), the GeoIP ones under your agreement with MaxMind. GeoLite asks for the credit "This product includes GeoLite Data created by MaxMind, available from https://www.maxmind.com", and that a release be deleted within 30 days of a newer one. The plugin updates every day, and when it cannot, past that delay, Site Health turns critical and an error shows in the admin. The lookups go on: updating or removing the database is up to you.
- **DB-IP.** The Lite databases are under the [Creative Commons Attribution 4.0](https://creativecommons.org/licenses/by/4.0/) licence, which asks for a link "IP Geolocation by DB-IP" to https://db-ip.com on the pages that show or use their results.
- **IPinfo.** IPinfo Lite is under the [Creative Commons Attribution-ShareAlike 4.0](https://creativecommons.org/licenses/by-sa/4.0/) licence, which asks for the credit "IP address data powered by IPinfo", linking to https://ipinfo.io.

The plugins showing the locations get the wording and the link of each credit from `gecka_geolocation_credit()`.

The MaxMind licence forbids handing the files to third parties. They live in `uploads/gecka-geolocation-<random key>/`, with a `.htaccess` denying access, which Apache honours. On Nginx or another server, deny that directory in the configuration, or move the databases out of the web root with the `GECKA_GEOLOCATION_DIRECTORY` constant. Site Health checks that the directory cannot be reached from outside.

## 🖼️ Logos of the networks

A switch in the Settings tab, off by default, gives the plugins the logo of the network of an address, as a small PNG image. The server downloads each logo once from [asn-data](https://github.com/tiagozip/asn-data), a mirror of PeeringDB with the favicon of the site of each network, and serves it from `uploads/gecka-geolocation-logos/`: browsers never ask the source. Many networks have no logo there; the plugins then show a generic icon. Turning the switch off deletes the logos.

## 🛒 WooCommerce

A switch in the Settings tab has WooCommerce locate its customers with the default provider, through its `woocommerce_get_geolocation` filter and ahead of its own MaxMind integration. The default customer location set to Geolocate, taxes and shipping then read the country, the state, the city and the postal code from here, and WooCommerce needs no licence key of its own. A country a CDN header gives WooCommerce wins. A state is filled in only when WooCommerce lists the subdivision under the same code.

## 🩺 Site Health

The test of the databases turns critical for a database outdated past the limit of its licence or a data directory reachable from outside, and recommended for no provider set up, a database missing, a failed update, or a daily task that has not run for two days. The Info tab sums up, for each provider, the credentials in use (never their value), the databases, their release, size and errors. Both show to whoever manages the plugin only.

## 🌍 Multisite

On a network, the plugin is activated network-wide and set up from the network admin. Every site shares the settings and the files: one download serves the network. The daily task is scheduled on the main site.

## 🔒 Privacy

Nothing about the site or its visitors goes to the providers. The requests download files, from `download.maxmind.com` and the storage it redirects to (with the account ID and the licence key, to MaxMind only), from `download.db-ip.com`, and from `ipinfo.io` and the storage it redirects to (with the token, to IPinfo only). With the logos of the networks on, the server asks `tiagozip.github.io` for the logo of a network number, once. The plugin keeps no address.

## 🧩 Works with

- **[Gecka Security](https://github.com/Gecka-Apps/wordpress-plugin-gecka-security)** uses the locations for its country rules on logins, its alert on a login from a new network, the country and the network under each address of its journal, and its statistics of where the attempts come from.
- **[Gecka Antispam](https://github.com/Gecka-Apps/wordpress-plugin-gecka-antispam)** breaks the submissions it refuses down by country and network.
- **WooCommerce**, as above.
- **Your own plugin or theme**, through a few functions available once the plugin is loaded: the country of an address, a full record, the flag, the logo of the network, the credit to show. Another plugin can also add a provider of its own. See [docs/DEVELOPERS.md](docs/DEVELOPERS.md).

## 🙅 Out of scope

Gecka Geolocation locates addresses and stops there. It does not block visitors by country, redirect them to a language or a store, or change the content of a page: those are for the plugins that ask it, Gecka Security for the country rules on logins. It shows no map and keeps no history of the addresses looked up.

## 📋 Requirements

- WordPress 6.8 or later, single site or multisite
- PHP 8.2 or later, with zlib, which reads the archives. With intl, the countries are named in the language of the site; without it, in English or French from the list of the plugin
- For MaxMind, an account: a free GeoLite one, or a paid GeoIP subscription. For IPinfo, the access token of a free account. For DB-IP, nothing
- Composer 2, if you install the plugin with Composer

The functions, the record, the providers, the hooks, the files and the work on the plugin itself are documented in [docs/DEVELOPERS.md](docs/DEVELOPERS.md).

## 📜 Licence

Copyright 2026 Gecka. GPL-3.0-or-later. The MaxMind reader in `inc/vendor-prefixed/` is under the Apache License 2.0, the flags in `assets/flags/` ([flag-icons](https://github.com/lipis/flag-icons)) under the MIT licence.

---
Built with 🥥 and ☕ by [Gecka](https://gecka.nc) — Kanaky-New Caledonia 🇳🇨
