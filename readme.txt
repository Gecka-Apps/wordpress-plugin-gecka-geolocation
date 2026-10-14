=== Gecka Geolocation ===
Contributors: gecka, ldinclaux
Tags: geolocation, geoip, maxmind, db-ip, ipinfo
Requires at least: 6.8
Tested up to: 7.1
Requires PHP: 8.2
Stable tag: 1.0.1
License: GPL-3.0-or-later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Locates IP addresses on your server for the plugins that ask, WooCommerce included, with the databases of MaxMind, DB-IP or IPinfo kept up to date.

== Description ==

Plugins keep asking where an IP address comes from: to name the country of a visitor, to show where an attack originates, to let WooCommerce guess the location of a customer. Gecka Geolocation answers that question on your server, from the IP geolocation databases of MaxMind, DB-IP or IPinfo, which it downloads and keeps up to date.

On its own, the plugin changes nothing on your site. It serves the plugins that ask it, Gecka Security and Gecka Antispam among them, WooCommerce when you turn that on, and the plugins and themes you write. Until one of them asks, it keeps the databases ready.

= Why on your server =

A geolocation API answers one address per request, counts the requests, and gets the address of each visitor you ask about. A database on the server answers thousands of addresses a second, costs nothing per lookup, and tells nobody anything. Nothing about the site or its visitors goes to a provider: the only requests the plugin makes are the downloads of the databases, and it keeps no address.

= The providers =

The databases come from the provider you choose, or several. A plugin asking a question gets the best database installed for it: the paid one over the free one, the most detailed over the others.

* **MaxMind**: a free GeoLite account downloads GeoLite Country, City and ASN, in 8 languages, with a region, a postal code, coordinates and a time zone in City. A paid GeoIP subscription downloads the databases it covers, more accurate, with the ISP, the connection type, the domain and whether an address hides behind a VPN, a proxy, Tor or a hosting provider. The settings page tells which databases your account has access to. 30 downloads a day per account; checking for a new release does not count.
* **DB-IP**: no account. DB-IP Country Lite, City Lite and ASN Lite, released every month, with a region, a city and coordinates in City.
* **IPinfo**: the access token of a free account. IPinfo Lite, one database released every day, with the country and the network of an address and its domain, nothing finer.

The README of the repository, https://github.com/Gecka-Apps/wordpress-plugin-gecka-geolocation#readme, compares the three free offers in a table, with what each database answers.

Nothing is downloaded before you tick a database and enter the credentials of its provider. A daily task then asks each provider for its latest releases and downloads only what changed, checked against the checksum the provider publishes and moved in place of the old file in one step.

= WooCommerce =

A switch in the settings has WooCommerce locate its customers with the default provider, ahead of its own MaxMind integration. The default customer location set to Geolocate, taxes and shipping then read the country, the state, the city and the postal code from here, and WooCommerce needs no licence key of its own.

= Logos of the networks =

A switch in the settings, off by default, gives the plugins the logo of the network of an address. The server downloads each logo once from asn-data, a mirror of PeeringDB, and serves it from the uploads of the site. Many networks have no logo there: the plugins then show a generic icon. Turning the switch off deletes the logos.

= Multisite =

On a network, the plugin is activated network-wide and set up from the network admin, under Settings > Geolocation. Every site shares the settings and the databases: one download serves the whole network.

= Licence of the databases =

* **MaxMind**: the GeoLite databases are under the GeoLite End User License Agreement, the GeoIP databases under your agreement with MaxMind. GeoLite asks for the credit "This product includes GeoLite Data created by MaxMind, available from https://www.maxmind.com" where the data is used, and that an outdated database be deleted within 30 days of a new release. The plugin updates every day, and when it cannot, past that delay, Site Health turns critical and a notice shows in the admin. Lookups go on meanwhile; updating or removing the database is up to you.
* **DB-IP**: the Lite databases are under the Creative Commons Attribution 4.0 licence, which asks for a link "IP Geolocation by DB-IP" to https://db-ip.com on the pages that show or use their results.
* **IPinfo**: IPinfo Lite is under the Creative Commons Attribution-ShareAlike 4.0 licence, which asks for the credit "IP address data powered by IPinfo", linking to https://ipinfo.io.

The plugins showing the locations get the wording and the link of each credit from the plugin. The databases live in a directory of the uploads whose name holds a random key, with a .htaccess file denying access on Apache: the MaxMind licence forbids handing them to third parties. On Nginx or another server, deny that directory in its configuration, or move the databases out of the web root with the `GECKA_GEOLOCATION_DIRECTORY` constant. Site Health checks that the directory cannot be reached from outside.

= Site Health =

Site Health tests the databases: missing, outdated past the limit of their licence, failed updates, a daily task that does not run, a directory reachable from outside. Its Info tab sums up, for each provider, the credentials in use (never their value), the databases, their release and size, for support.

= Privacy =

The plugin sends no address of your visitors anywhere: the databases are read on your server. It keeps none either. What the plugins using it keep of a location is up to them. A paragraph for the privacy policy of the site is offered under Settings > Privacy.

= For your own plugin or theme =

A few functions, available once the plugin is loaded, give the country of an address, as `FR`, a full record with the region, the city, the coordinates, the network and the anonymity flags as far as the databases installed go, the flag of a country as an emoji or an SVG image, the name of a country in the language of the site, the logo of a network, and the credit to show. Without an address, they locate the visitor; the `gecka_geolocation_client_ip` filter gives the right address behind a proxy or a CDN. Another plugin can add a provider of its own. A plugin calls `function_exists( 'gecka_geolocation' )` first, and does without when Gecka Geolocation is missing. docs/DEVELOPERS.md, in the folder of the plugin, documents the functions, the record, the providers, the credits and the hooks.

= Credits =

The flags are the SVG images of flag-icons (https://github.com/lipis/flag-icons) by Panayiotis Lipiridis, under the MIT licence. The MaxMind DB reader is maxmind-db/reader by MaxMind, Inc., under the Apache License 2.0.

== External services ==

The plugin downloads databases from the providers you set up, and from no other, and the logos of the networks from asn-data once that setting is on. Each provider is contacted only once its databases are ticked and, when it asks for them, its credentials entered: when the settings are saved, from the "Update now" buttons, and from the daily task, which asks for the latest releases first and downloads only what changed. The requests carry what any request carries, the IP address of the server among it, under a user agent naming the plugin. Nothing about the site, its visitors or the addresses looked up is sent.

= MaxMind =

The databases of MaxMind, Inc. (https://www.maxmind.com) come with the account ID and licence key you enter from download.maxmind.com, which redirects each download to its storage on Cloudflare R2 (mm-prod-geoip-databases.a2649acb697e2c09b632799562c076f2.r2.cloudflarestorage.com). The daily task asks the date of the latest release of each database with a HEAD request; checking which databases the account has access to sends one HEAD request per database. The account ID and licence key go, in HTTP Basic authentication, to download.maxmind.com only.

MaxMind terms: GeoLite End User License Agreement https://www.maxmind.com/en/geolite/eula, GeoIP End User License Agreement https://www.maxmind.com/en/end-user-license-agreement, privacy policy https://www.maxmind.com/en/privacy-policy. Cloudflare privacy policy: https://www.cloudflare.com/privacypolicy/.

= DB-IP =

The Lite databases of DB-IP (https://db-ip.com) come from download.db-ip.com, without credentials. The daily task asks for the release of the month with a HEAD request.

DB-IP Lite licence: https://db-ip.com/db/lite.php (Creative Commons Attribution 4.0). Privacy policy: https://db-ip.com/privacy.php.

= IPinfo =

IPinfo Lite (https://ipinfo.io/lite) comes from ipinfo.io with the access token you enter, which IPinfo asks for in the address of the request; ipinfo.io redirects the download to its storage, dl.assets.ipinfo.io, which gets no token. The daily task asks ipinfo.io for the checksum of the latest release, and downloads the file only when it changed.

IPinfo terms of service: https://ipinfo.io/terms-of-service, privacy policy: https://ipinfo.io/privacy-policy. IPinfo Lite licence: Creative Commons Attribution-ShareAlike 4.0.

= asn-data =

Only when "Logos of the networks" is on in the settings: the server downloads the logo of a network from tiagozip.github.io/asn-data, a page hosted by GitHub, once per network, from a WP-Cron task. The request carries the number of the network (autonomous system number) and nothing else; a network without a logo is not asked again for a month. The browsers of the visitors and administrators never contact it.

asn-data: https://github.com/tiagozip/asn-data. GitHub privacy statement: https://docs.github.com/en/site-policy/privacy-policies/github-general-privacy-statement.

== Installation ==

1. Install and activate the plugin, for the network on a multisite.
2. Under Settings > Geolocation, open the tab of a provider. For MaxMind, open a free GeoLite account at https://www.maxmind.com/en/geolite-free-ip-geolocation-data, or use your GeoIP account, create a licence key on the licence keys page of the account, and enter the account ID and the key. For IPinfo, open a free account at https://ipinfo.io/lite and enter its access token. DB-IP needs nothing.
3. Tick the databases you want, save.
4. The Databases tab downloads them and shows their release. An address can be tried out there, with any provider.

To keep the credentials out of the database, define them in wp-config.php instead. The page then shows the field of a credential defined there disabled, with the name of its constant:

`define( 'GECKA_GEOLOCATION_MAXMIND_ACCOUNT_ID', '123456' );`
`define( 'GECKA_GEOLOCATION_MAXMIND_LICENSE_KEY', 'your_licence_key' );`
`define( 'GECKA_GEOLOCATION_IPINFO_TOKEN', 'your_token' );`

== Frequently Asked Questions ==

= Which database should I choose? =

A country database answers what most uses need, in about 10 MB: GeoLite Country, DB-IP Country Lite or IPinfo Lite, which adds the autonomous system. GeoLite City or DB-IP City Lite add the region, the city and the coordinates, in 60 MB or more. GeoLite ASN or DB-IP ASN Lite tell the network an address belongs to. With a paid MaxMind subscription, the GeoIP databases are more accurate and add the ISP, the connection type and anonymity.

= Which provider should I choose? =

DB-IP asks for nothing and starts at once. MaxMind GeoLite is the most widely used, gives the most fields, and is the way to the paid GeoIP databases. IPinfo Lite is updated every day but answers the country and the network only. All three are free, and each asks for its credit where its data is shown or used. Several can be set up at once: the Settings tab chooses the one the other plugins get by default. The "Choosing a provider" section above compares them.

= What does it do on its own? =

Nothing you can see. It keeps the databases up to date and answers the plugins that ask: Gecka Security for its country rules, alerts and statistics, Gecka Antispam for the origins of the spam it refuses, WooCommerce for the location of its customers, and any plugin or theme using its functions. Without one of them, the Databases tab is the only place where it shows a result: try an address there.

= Where does the address of the visitor come from? =

From REMOTE_ADDR. Behind a reverse proxy or a CDN, that is the address of the proxy: return the forwarded address in the `gecka_geolocation_client_ip` filter, read from a header only your proxy can set.

= The daily task does not run =

WP-Cron runs it on a visit of the site. On a site with DISABLE_WP_CRON, the cron of the server must call wp-cron.php. On a network, the tasks belong to the main site: a visit of another site does not run them, so the cron of the server must call the wp-cron.php of the main site. Site Health tells when the databases were last compared with the latest releases.

= MaxMind says the daily download limit is reached =

MaxMind limits the downloads of an account each day. The plugin only downloads a database when MaxMind released a new one, which its HEAD requests tell without counting in that limit. Several sites sharing one account count together: give each its own licence key, or run them as a multisite.

== Screenshots ==

1. The Databases tab: each provider with its databases, their release and an update button.
2. An address tried out with any provider: country, flags, coordinates, time zone, network and its logo.
3. MaxMind: the account ID and the licence key, typed in a masked field and kept out of the database with a constant if you prefer.
4. MaxMind: the GeoLite databases, and the GeoIP ones a paid account covers, each checked every day.
5. DB-IP: no account, tick the Lite databases to download and save.
6. IPinfo: the access token and IPinfo Lite, updated every day.
7. The provider answering the other plugins when they name none: by default, the first one with databases in place.
8. WooCommerce located with the databases of the plugin, instead of its own MaxMind integration.
9. The logos of the networks, downloaded once by the server and served from the uploads of the site.

== Changelog ==

= 1.0.1 =

* The error of a database outdated past the limit of its licence shows on the dashboard and the plugins screen, no longer on every admin screen.
* Admin page styled on sites that install the plugin through their own Composer, and notices spaced like those of Site Health.

= 1.0.0 =
* First release: downloads and updates of the databases of MaxMind (GeoLite and GeoIP), DB-IP (Lite) and IPinfo (Lite), lookups and credits for other plugins, WooCommerce geolocation, network settings on a multisite, Site Health tests.
