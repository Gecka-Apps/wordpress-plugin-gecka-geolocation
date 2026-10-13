<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Gecka <contact@gecka.nc>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Gecka\WP\Plugin\Geolocation;

use Gecka\WP\Plugin\Geolocation\Admin\Notices;
use Gecka\WP\Plugin\Geolocation\Admin\SettingsPage;
use Gecka\WP\Plugin\Geolocation\Admin\SiteHealth;
use Gecka\WP\Plugin\Geolocation\Integrations\WooCommerce;
use Gecka\WP\Plugin\Geolocation\DbIp\Provider as DbIp;
use Gecka\WP\Plugin\Geolocation\IPinfo\Provider as IPinfo;
use Gecka\WP\Plugin\Geolocation\MaxMind\Provider as MaxMind;
use Gecka\WP\Plugin\Geolocation\Mmdb\Provider as MmdbProvider;

/**
 * Plugin instance: the pieces it hooks up, the providers of locations, and
 * the lookups other plugins ask for.
 *
 * @author Laurent Dinclaux - Gecka <laurent@gecka.nc>
 */
class Plugin
{
    /**
     * Version of the plugin.
     */
    public const VERSION = '1.0.0';

    /**
     * Records kept for the request at most.
     */
    public const CACHE = 1000;

    /**
     * Main file of the plugin.
     *
     * @var string
     */
    private string $file;

    /**
     * @var Settings
     */
    private Settings $settings;

    /**
     * The providers the plugin downloads the databases of, by identifier.
     *
     * @var array<string, MmdbProvider>
     */
    private array $bundled;

    /**
     * @var Logos
     */
    private Logos $logos;

    /**
     * The providers, by identifier, once the filter ran.
     *
     * @var array<string, Provider>|null
     */
    private ?array $providers = null;

    /**
     * Records made, by address, locale and provider.
     *
     * @var array<string, Record>
     */
    private array $records = [];

    /**
     * @param string $file Main file of the plugin
     */
    public function __construct(string $file)
    {
        $this->file = $file;

        $this->settings = new Settings();
        $this->bundled = self::bundle();

        foreach ($this->bundled as $provider) {
            $provider->setup();
        }

        foreach (['add_site_option_', 'update_site_option_', 'delete_site_option_'] as $hook) {
            add_action($hook . Settings::OPTION, [$this->settings, 'flush']);
        }

        $this->logos = new Logos($this->settings);
        $this->logos->setup();

        // The screens and their handlers exist in the admin only; admin-ajax.php
        // and admin-post.php are the admin too. The routes of the update
        // buttons answer REST requests, which are not. Site Health hooks
        // everywhere: its two filters cost nothing, and its tests run from
        // the weekly event of WordPress or from WP-CLI as well as the admin.
        if (is_admin()) {
            (new SettingsPage($this))->setup();
            (new Notices($this))->setup();
        }

        add_action('rest_api_init', function (): void {
            (new SettingsPage($this))->registerRoutes();
        });
        (new SiteHealth($this))->setup();
        (new WooCommerce($this))->setup();

        add_action('init', static function (): void {
            Setup::upgrade(self::VERSION);
        });
        add_action(
            'gecka_geolocation_updated',
            function (): void {
                $this->records = [];
            },
        );
    }

    /**
     * The providers of locations: MaxMind, DB-IP, IPinfo, and those other
     * plugins add
     *
     * @return array<string, Provider> By identifier.
     */
    public function providers(): array
    {
        if ($this->providers !== null) {
            return $this->providers;
        }

        /**
         * Filters the providers of locations. A plugin adds its own, an
         * object implementing Gecka\WP\Plugin\Geolocation\Provider, from
         * plugins_loaded on: the list is read once a request, on the first
         * lookup.
         *
         * @param array<string, Provider> $providers By identifier.
         */
        /** @var mixed $filtered Whatever the callbacks returned. */
        $filtered = apply_filters('gecka_geolocation_providers', $this->bundled);
        $providers = [];

        foreach (is_array($filtered) ? $filtered : [] as $provider) {
            if ($provider instanceof Provider && preg_match('/^[a-z][a-z0-9_-]*\z/', $provider->id())) {
                $providers[$provider->id()] = $provider;
            }
        }

        // A lookup before the end of plugins_loaded, from a plugin loaded
        // earlier, must not freeze a list the other plugins have not filled
        // yet.
        if (did_action('plugins_loaded') && ! doing_action('plugins_loaded')) {
            $this->providers = $providers;
        }

        return $providers;
    }

    /**
     * Whether a provider answers a facet, a provider that fails answering
     * none
     *
     * @param Provider $provider
     * @param string   $facet
     *
     * @return bool
     */
    private static function answers(Provider $provider, string $facet): bool
    {
        try {
            return $provider->available($facet);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Whether a provider answers any facet
     *
     * @param Provider $provider
     *
     * @return bool
     */
    private static function answersAny(Provider $provider): bool
    {
        foreach (Facet::ALL as $facet) {
            if (self::answers($provider, $facet)) {
                return true;
            }
        }

        return false;
    }

    /**
     * A provider by its identifier, or the provider asked when none is named
     *
     * @param string|null $id Null or empty for the default provider.
     *
     * @return Provider|null Null for a provider that is not registered.
     */
    public function provider(?string $id = null): ?Provider
    {
        $providers = $this->providers();

        return $providers[$id === null || $id === '' ? $this->defaultProvider() : $id] ?? null;
    }

    /**
     * Identifier of the provider asked when a lookup names none: the one of
     * the settings, or by default the first registered that has data in
     * place, through the gecka_geolocation_default_provider filter; else the
     * first registered
     *
     * @return string
     */
    public function defaultProvider(): string
    {
        $providers = $this->providers();
        $id = $this->settings->provider();

        if ($id === '') {
            foreach ($providers as $provider) {
                if (self::answersAny($provider)) {
                    $id = $provider->id();
                    break;
                }
            }
        }

        /**
         * Filters the provider asked when a lookup names none.
         *
         * @param string   $provider  Identifier of the provider of the settings, or of the first one with data in place; empty when none has any.
         * @param string[] $available Identifiers of the providers registered.
         */
        $id = Value::string(apply_filters('gecka_geolocation_default_provider', $id, array_keys($providers)));

        return isset($providers[$id]) ? $id : Value::string(array_key_first($providers));
    }

    /**
     * What a provider knows of an address
     *
     * @param string|null $ip       The address of the visitor when null.
     * @param string|null $provider The default provider when null.
     *
     * @return Record|null Null for an invalid address, an unknown provider, or one that answers nothing yet.
     */
    public function lookup(?string $ip = null, ?string $provider = null): ?Record
    {
        $ip = trim($ip ?? $this->clientIp());
        $source = $this->provider($provider);

        if (! filter_var($ip, FILTER_VALIDATE_IP) || $source === null) {
            return null;
        }

        $locale = function_exists('determine_locale') ? determine_locale() : get_locale();
        $key = $ip . '|' . $locale . '|' . $source->id();

        if (isset($this->records[$key])) {
            return $this->records[$key];
        }

        if (! self::answersAny($source)) {
            return null;
        }

        // A long run, as a WP-CLI command over a journal, keeps a bounded
        // number of records.
        if (count($this->records) >= self::CACHE) {
            $this->records = [];
        }

        return $this->records[$key] = new Record($ip, $source, $locale, $this->logos);
    }

    /**
     * The credit the licence of the data of a provider asks for
     *
     * @param string|null $provider The default provider when null.
     *
     * @return array{text: string, url: string|null}|null
     */
    public function credit(?string $provider = null): ?array
    {
        return $this->provider($provider)?->credit();
    }

    /**
     * Whether a provider answers a facet
     *
     * @param string      $facet    One of the constants of Facet.
     * @param string|null $provider The default provider when null.
     *
     * @return bool
     */
    public function available(string $facet = Facet::COUNTRY, ?string $provider = null): bool
    {
        return (bool) $this->provider($provider)?->available($facet);
    }

    /**
     * Address of the visitor: REMOTE_ADDR, which the
     * gecka_geolocation_client_ip filter replaces behind a proxy
     *
     * @return string Empty when unknown.
     */
    public function clientIp(): string
    {
        $remote = Request::server('REMOTE_ADDR');

        /**
         * Filters the address of the visitor the plugin locates when none is
         * given. Behind a reverse proxy or a CDN, REMOTE_ADDR is the address
         * of the proxy: return the one it forwards, from a header only a
         * trusted proxy can set.
         *
         * @param string $ip REMOTE_ADDR.
         */
        $ip = trim(Value::string(apply_filters('gecka_geolocation_client_ip', $remote)));

        return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '';
    }

    /**
     * Capability the screens and actions of the plugin ask for: managing
     * the network on a multisite, where the databases serve every site,
     * managing options otherwise
     *
     * @return string
     */
    public static function capability(): string
    {
        $default = is_multisite() ? 'manage_network_options' : 'manage_options';

        /**
         * Filters the capability the screens of the plugin ask for.
         *
         * @param string $capability manage_network_options on a multisite, manage_options otherwise.
         */
        $capability = Value::string(apply_filters('gecka_geolocation_capability', $default));

        return $capability !== '' ? $capability : $default;
    }

    /**
     * Forgets the providers read and the records made, for a filter added
     * late.
     *
     * @return void
     */
    public function flush(): void
    {
        $this->providers = null;
        $this->records = [];
    }

    /**
     * @return Settings
     */
    public function settings(): Settings
    {
        return $this->settings;
    }

    /**
     * @return Logos
     */
    public function logos(): Logos
    {
        return $this->logos;
    }

    /**
     * The providers the plugin downloads the databases of, which its
     * screens manage
     *
     * @return array<string, MmdbProvider> By identifier.
     */
    public function bundled(): array
    {
        return $this->bundled;
    }

    /**
     * A provider the plugin downloads the databases of
     *
     * @param string $id
     *
     * @return MmdbProvider|null
     */
    public function mmdb(string $id): ?MmdbProvider
    {
        return $this->bundled[$id] ?? null;
    }

    /**
     * New instances of the providers the plugin downloads the databases of,
     * as uninstalling needs them without the plugin loaded
     *
     * @return array<string, MmdbProvider> By identifier.
     */
    public static function bundle(): array
    {
        $providers = [];

        foreach ([new MaxMind(), new DbIp(), new IPinfo()] as $provider) {
            $providers[$provider->id()] = $provider;
        }

        return $providers;
    }

    /**
     * Url of a file of the plugin
     *
     * @param string $relative
     *
     * @return string
     */
    public function url(string $relative = ''): string
    {
        return plugins_url($relative, $this->file);
    }

    /**
     * Main file of the plugin
     *
     * @return string
     */
    public function file(): string
    {
        return $this->file;
    }
}
